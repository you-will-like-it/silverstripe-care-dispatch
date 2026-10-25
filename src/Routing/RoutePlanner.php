<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Throwable;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\Shift;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingJob;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingProblem;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingSolution;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingVehicle;

/**
 * Builds routing problems from the day plan and turns solver output into a RouteProposal.
 *
 * Modes:
 * - optimizeTour: re-orders the pending visits of one tour (same caregiver).
 * - distributePool: places unassigned visits onto caregivers on duty; already planned visits
 *   stay fixed (pinned) at their time so existing tours are not reshuffled.
 *
 * Double visits (RequiredStaff = 2) and companions keep their scheduled time, so primary and
 * companion stay synchronous.
 */
class RoutePlanner
{
    use Configurable;
    use Injectable;

    /** Company depot / office (start and end of routes). Env: CARE_DISPATCH_DEPOT_LAT / _LNG */
    private static float $depot_lat = 0.0;

    private static float $depot_lng = 0.0;

    /** Vehicle window used when a caregiver has an open tour but no shift. */
    private static string $default_window_start = '06:00';

    private static string $default_window_end = '22:00';

    /** If a visit has no care plan time window: scheduled start ± tolerance. */
    private static int $window_tolerance_minutes = 30;

    private static array $dependencies = [
        'heuristic' => '%$' . InsertionHeuristicSolver::class,
        'haversine' => '%$' . HaversineMatrixProvider::class,
    ];

    public InsertionHeuristicSolver $heuristic;

    public HaversineMatrixProvider $haversine;

    /** @var GeoPoint[] */
    private array $locations = [];

    /** @var array<string, int> */
    private array $locationIndex = [];

    public function optimizeTour(int $tourID, string $date): RouteProposal
    {
        $tour = DailyTour::get()->byID($tourID) ?? throw NotFoundException::for('Tour', $tourID);
        if ((string) $tour->TourDate !== $date) {
            throw new DomainRuleException('DATE_MISMATCH', 'Tour gehört nicht zum gewählten Tag.');
        }
        if (!$tour->isOpen() || !$tour->CaregiverID) {
            throw new DomainRuleException('TOUR_NOT_OPTIMIZABLE', 'Nur offene Touren mit zugewiesener Pflegekraft können optimiert werden.');
        }
        $this->resetLocations();
        $caregiver = $tour->Caregiver();
        $vehicle = $this->vehicleFor($caregiver, $date, (int) $tour->ID);
        if ($vehicle === null) {
            throw new DomainRuleException('NO_DEPOT', 'Kein Startpunkt: Firmenstandort (depot_lat/lng) oder Startadresse der Pflegekraft fehlt.');
        }

        $jobs = [];
        $unassigned = [];
        $visits = $tour->Visits()->filter('Status', PatientVisit::STATUS_PENDING)->sort('SortOrder');
        $patients = $this->patientsFor($visits->column('PatientID'));
        foreach ($visits as $visit) {
            // Single vehicle: jobs stay free so the solver may reorder them.
            $job = $this->jobFor($visit, $patients[(int) $visit->PatientID] ?? null, null, false);
            if ($job === null) {
                $unassigned[(int) $visit->ID] = 'NO_GEO';
                continue;
            }
            $jobs[] = $job;
        }

        return $this->run('tour', $date, $jobs, [$vehicle], $unassigned, [(int) $caregiver->ID => (int) $tour->ID]);
    }

    public function distributePool(string $date): RouteProposal
    {
        $this->resetLocations();
        $vehicles = [];
        $tourByCaregiver = [];
        $notes = [];

        foreach ($this->caregiversOnDuty($date) as $caregiver) {
            $tour = DailyTour::get()
                ->filter(['CaregiverID' => $caregiver->ID, 'TourDate' => $date, 'Status' => DailyTour::OPEN_STATUSES])
                ->sort(['IsAdHoc' => 'ASC', 'ID' => 'ASC'])
                ->first();
            $vehicle = $this->vehicleFor($caregiver, $date, $tour?->ID);
            if ($vehicle === null) {
                $notes[] = sprintf('%s ohne Startpunkt – nicht berücksichtigt.', $caregiver->getTitle());
                continue;
            }
            $vehicles[] = $vehicle;
            $tourByCaregiver[(int) $caregiver->ID] = $tour !== null ? (int) $tour->ID : null;
        }

        $vehicleIDs = array_map(static fn (RoutingVehicle $v): int => $v->id, $vehicles);
        $jobs = [];
        $unassigned = [];

        // Existing plans are pinned to their caregiver at their current time.
        if ($vehicleIDs !== []) {
            $openTours = DailyTour::get()->filter([
                'CaregiverID' => $vehicleIDs,
                'TourDate' => $date,
                'Status' => DailyTour::OPEN_STATUSES,
            ]);
            $planned = PatientVisit::get()->filter([
                'DailyTourID' => $openTours->column('ID') ?: [0],
                'Status' => PatientVisit::STATUS_PENDING,
            ]);
            $tourCaregiver = $openTours->map('ID', 'CaregiverID')->toArray();
            $patients = $this->patientsFor($planned->column('PatientID'));
            foreach ($planned as $visit) {
                $job = $this->jobFor($visit, $patients[(int) $visit->PatientID] ?? null, (int) $tourCaregiver[$visit->DailyTourID], true);
                if ($job !== null) {
                    $jobs[] = $job;
                }
            }
        }

        $pool = PatientVisit::get()->filter(['VisitDate' => $date, 'Status' => PatientVisit::STATUS_UNASSIGNED]);
        $patients = $this->patientsFor($pool->column('PatientID'));
        foreach ($pool as $visit) {
            $patient = $patients[(int) $visit->PatientID] ?? null;
            if ($vehicles === []) {
                $unassigned[(int) $visit->ID] = 'NO_VEHICLES';
                continue;
            }
            $job = $this->jobFor($visit, $patient, null, false, $vehicleIDs);
            if ($job === null) {
                $unassigned[(int) $visit->ID] = 'NO_GEO';
                continue;
            }
            $jobs[] = $job;
        }

        return $this->run('pool', $date, $jobs, $vehicles, $unassigned, $tourByCaregiver, $notes);
    }

    /**
     * @param RoutingJob[]          $jobs
     * @param RoutingVehicle[]      $vehicles
     * @param array<int, string>    $preUnassigned visitId => reason
     * @param array<int, int|null>  $tourByCaregiver
     * @param string[]              $notes
     */
    private function run(string $mode, string $date, array $jobs, array $vehicles, array $preUnassigned, array $tourByCaregiver, array $notes = []): RouteProposal
    {
        [$matrix, $matrixSource] = $this->matrix($notes);
        $problem = new RoutingProblem($matrix, $jobs, $vehicles);

        $solution = null;
        if ($jobs !== [] && $vehicles !== [] && VroomSolver::isConfigured()) {
            try {
                $solution = VroomSolver::singleton()->solve($problem);
            } catch (Throwable $e) {
                Injector::inst()->get(LoggerInterface::class)->warning('CareDispatch: VROOM failed, using heuristic: ' . $e->getMessage());
                $notes[] = 'VROOM nicht erreichbar – interne Heuristik verwendet.';
            }
        }
        $solution ??= $jobs !== [] && $vehicles !== []
            ? $this->heuristic->solve($problem)
            : new RoutingSolution('none');

        return $this->toProposal($mode, $date, $problem, $solution, $preUnassigned, $tourByCaregiver, $matrixSource, $notes);
    }

    /**
     * @param array<int, string>   $preUnassigned
     * @param array<int, int|null> $tourByCaregiver
     * @param string[]             $notes
     */
    private function toProposal(
        string $mode,
        string $date,
        RoutingProblem $problem,
        RoutingSolution $solution,
        array $preUnassigned,
        array $tourByCaregiver,
        string $matrixSource,
        array $notes,
    ): RouteProposal {
        $visitIDs = array_map(static fn (RoutingJob $j): int => $j->id, $problem->jobs);
        $visitIDs = array_merge($visitIDs, array_keys($preUnassigned));
        $visits = [];
        foreach (PatientVisit::get()->byIDs($visitIDs ?: [0]) as $visit) {
            $visits[(int) $visit->ID] = $visit;
        }
        $patients = $this->patientsFor(array_map(static fn (PatientVisit $v): int => (int) $v->PatientID, $visits));
        $caregivers = [];
        foreach (Caregiver::get()->byIDs(array_keys($tourByCaregiver) ?: [0]) as $caregiver) {
            $caregivers[(int) $caregiver->ID] = $caregiver;
        }

        $routes = [];
        foreach ($solution->routes as $caregiverID => $stops) {
            $tourID = $tourByCaregiver[$caregiverID] ?? null;
            $routeStops = [];
            foreach ($stops as $stop) {
                $visit = $visits[$stop->jobId] ?? null;
                if ($visit === null) {
                    continue;
                }
                $job = $problem->job($stop->jobId);
                $newStart = Clock::short(Clock::secondsToTime($stop->start));
                $routeStops[] = [
                    'visitId' => (int) $visit->ID,
                    'patientName' => ($patients[(int) $visit->PatientID] ?? null)?->getTitle(),
                    'role' => (string) $visit->Role,
                    'start' => $newStart,
                    'end' => Clock::short(Clock::secondsToTime($stop->start + $visit->getDurationSeconds())),
                    'travelMinutes' => (int) round($stop->travelSeconds / 60),
                    'pinned' => $job?->isPinned() ?? false,
                    'changed' => $visit->Status === PatientVisit::STATUS_UNASSIGNED
                        || $tourID === null
                        || (int) $visit->DailyTourID !== $tourID
                        || Clock::short($visit->ScheduledStart) !== $newStart,
                    'basis' => [
                        'status' => (string) $visit->Status,
                        'tourId' => (int) $visit->DailyTourID ?: null,
                        'start' => Clock::short($visit->ScheduledStart),
                    ],
                ];
            }
            $routes[] = [
                'caregiverId' => (int) $caregiverID,
                'caregiverName' => ($caregivers[(int) $caregiverID] ?? null)?->getTitle(),
                'tourId' => $tourID,
                'stops' => $routeStops,
            ];
        }

        $unassigned = [];
        foreach ($preUnassigned + $solution->unassigned as $visitID => $reason) {
            $visit = $visits[(int) $visitID] ?? null;
            $unassigned[] = [
                'visitId' => (int) $visitID,
                'patientName' => $visit !== null ? ($patients[(int) $visit->PatientID] ?? null)?->getTitle() : null,
                'reason' => $reason,
                'reasonLabel' => RouteProposal::REASON_LABELS[$reason] ?? $reason,
            ];
        }

        return new RouteProposal(
            $mode,
            $date,
            $solution->solver,
            $matrixSource,
            $routes,
            $unassigned,
            $solution->totalTravelSeconds(),
            Clock::now()->format(DATE_ATOM),
            $notes
        );
    }

    /**
     * @param string[] $notes
     * @return array{0:int[][],1:string}
     */
    private function matrix(array &$notes): array
    {
        if ($this->locations === []) {
            return [[], 'none'];
        }
        if (OsrmMatrixProvider::isConfigured()) {
            try {
                return [OsrmMatrixProvider::singleton()->durations($this->locations), 'osrm'];
            } catch (Throwable $e) {
                Injector::inst()->get(LoggerInterface::class)->warning('CareDispatch: OSRM failed, using haversine: ' . $e->getMessage());
                $notes[] = 'OSRM nicht erreichbar – Fahrzeiten geschätzt (Luftlinie).';
            }
        }

        return [$this->haversine->durations($this->locations), 'haversine'];
    }

    /**
     * @param int[]|null $vehicleIDs vehicles on duty (pool mode) for exclusion/preference mapping
     */
    private function jobFor(PatientVisit $visit, ?Patient $patient, ?int $pinnedCaregiverID, bool $pinAtCurrentTime, ?array $vehicleIDs = null): ?RoutingJob
    {
        $point = $patient?->getGeoPoint();
        if ($point === null) {
            return null;
        }
        $start = Clock::timeToSeconds($visit->ScheduledStart);
        $fixedTime = $pinAtCurrentTime || $visit->isCompanion() || (int) $visit->RequiredStaff >= 2;
        if ($fixedTime) {
            [$windowStart, $windowEnd] = [$start, $start];
        } elseif ($visit->WindowStart && $visit->WindowEnd) {
            [$windowStart, $windowEnd] = [Clock::timeToSeconds($visit->WindowStart), Clock::timeToSeconds($visit->WindowEnd)];
        } else {
            $tolerance = (int) static::config()->get('window_tolerance_minutes') * 60;
            [$windowStart, $windowEnd] = [max(0, $start - $tolerance), min(86399, $start + $tolerance)];
        }

        $allowed = null;
        $preferred = [];
        if ($vehicleIDs !== null && $patient !== null) {
            $excluded = array_map('intval', $patient->ExcludedCaregivers()->column('ID'));
            if ($visit->isCompanion()) {
                $primary = $visit->PrimaryVisit();
                if ($primary->exists() && $primary->DailyTourID) {
                    $excluded[] = (int) $primary->DailyTour()->CaregiverID;
                }
            }
            if ($excluded !== []) {
                $allowed = array_values(array_diff($vehicleIDs, $excluded));
            }
            $preferred = array_values(array_intersect($vehicleIDs, array_map('intval', $patient->PreferredCaregivers()->column('ID'))));
        }

        return new RoutingJob(
            id: (int) $visit->ID,
            location: $this->locationFor($point),
            serviceSeconds: max(300, $visit->getDurationSeconds()),
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            requiredRank: $visit->isCompanion() ? 0 : $visit->getRequiredLevel()->rank(),
            allowedVehicleIds: $allowed,
            preferredVehicleIds: $preferred,
            pinnedVehicleId: $pinnedCaregiverID,
        );
    }

    private function vehicleFor(Caregiver $caregiver, string $date, ?int $tourID): ?RoutingVehicle
    {
        $home = $caregiver->getStartPoint() ?? $this->depot();
        if ($home === null) {
            return null;
        }
        $homeIndex = $this->locationFor($home);

        $shifts = Shift::get()->filter([
            'CaregiverID' => $caregiver->ID,
            'ShiftDate' => $date,
            'Status' => Shift::STATUS_SCHEDULED,
        ]);
        if ($shifts->exists()) {
            $windowStart = PHP_INT_MAX;
            $windowEnd = 0;
            foreach ($shifts as $shift) {
                [$s, $e] = $shift->window();
                $windowStart = min($windowStart, $s);
                $windowEnd = max($windowEnd, min(86399, $e));
            }
        } else {
            $windowStart = Clock::timeToSeconds(Clock::parseTime((string) static::config()->get('default_window_start')));
            $windowEnd = Clock::timeToSeconds(Clock::parseTime((string) static::config()->get('default_window_end')));
        }

        // Today: continue from where the caregiver is now.
        $startIndex = $homeIndex;
        if ($date === Clock::today()) {
            $windowStart = max($windowStart, Clock::secondsOfDay(Clock::now()));
            $last = $this->lastServedVisit($caregiver, $date);
            if ($last !== null) {
                $point = $last->Patient()->getGeoPoint();
                if ($point !== null) {
                    $startIndex = $this->locationFor($point);
                }
                if ($last->Status === PatientVisit::STATUS_IN_PROGRESS) {
                    $windowStart = max($windowStart, Clock::timeToSeconds($last->ScheduledEnd));
                }
            }
        }
        if ($windowStart >= $windowEnd) {
            $windowEnd = $windowStart + 1;
        }

        $level = $caregiver->getQualification();

        return new RoutingVehicle(
            id: (int) $caregiver->ID,
            startLocation: $startIndex,
            endLocation: $homeIndex,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            rank: $level->rank(),
            canBePrimary: $level->canActAsPrimary(),
            tourId: $tourID,
        );
    }

    /** @return Caregiver[] */
    private function caregiversOnDuty(string $date): array
    {
        $withShift = Shift::get()->filter(['ShiftDate' => $date, 'Status' => Shift::STATUS_SCHEDULED])->column('CaregiverID');
        $withOpenTour = DailyTour::get()->filter(['TourDate' => $date, 'Status' => DailyTour::OPEN_STATUSES])->column('CaregiverID');
        $interrupted = DailyTour::get()->filter(['TourDate' => $date, 'Status' => DailyTour::STATUS_INTERRUPTED])->column('CaregiverID');
        // A caregiver whose tour was interrupted today (illness, accident) only counts if they still have an open tour.
        $ids = array_values(array_unique(array_filter(array_merge(
            array_diff($withShift, $interrupted),
            $withOpenTour
        ))));
        if ($ids === []) {
            return [];
        }

        return Caregiver::get()->filter(['ID' => $ids, 'IsActive' => true])->toArray();
    }

    private function lastServedVisit(Caregiver $caregiver, string $date): ?PatientVisit
    {
        $tourIDs = DailyTour::get()->filter(['CaregiverID' => $caregiver->ID, 'TourDate' => $date])->column('ID');
        if ($tourIDs === []) {
            return null;
        }

        return PatientVisit::get()
            ->filter([
                'DailyTourID' => $tourIDs,
                'Status' => [PatientVisit::STATUS_IN_PROGRESS, PatientVisit::STATUS_COMPLETED],
            ])
            ->sort(['ActualStart' => 'DESC', 'ID' => 'DESC'])
            ->first();
    }

    /**
     * @param array<int|string> $ids
     * @return array<int, Patient>
     */
    private function patientsFor(array $ids): array
    {
        $out = [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (Patient::get()->byIDs($ids ?: [0]) as $patient) {
            $out[(int) $patient->ID] = $patient;
        }

        return $out;
    }

    private function depot(): ?GeoPoint
    {
        $lat = (float) (Environment::getEnv('CARE_DISPATCH_DEPOT_LAT') ?: static::config()->get('depot_lat'));
        $lng = (float) (Environment::getEnv('CARE_DISPATCH_DEPOT_LNG') ?: static::config()->get('depot_lng'));
        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        return new GeoPoint($lat, $lng);
    }

    private function resetLocations(): void
    {
        $this->locations = [];
        $this->locationIndex = [];
    }

    private function locationFor(GeoPoint $point): int
    {
        $key = $point->key();
        if (!isset($this->locationIndex[$key])) {
            $this->locationIndex[$key] = count($this->locations);
            $this->locations[] = $point;
        }

        return $this->locationIndex[$key];
    }
}
