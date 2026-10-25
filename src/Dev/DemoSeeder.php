<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Dev;

use DateInterval;
use DateTimeImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use YouWillLikeIT\CareDispatch\Admin\CareDispatchAdmin;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\CareDispatchException;
use YouWillLikeIT\CareDispatch\Model\AuditEntry;
use YouWillLikeIT\CareDispatch\Model\CarePlanItem;
use YouWillLikeIT\CareDispatch\Model\CareTask;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\Shift;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;
use YouWillLikeIT\CareDispatch\Model\TourTemplate;
use YouWillLikeIT\CareDispatch\Model\TourTemplateItem;
use YouWillLikeIT\CareDispatch\Model\VisitTask;
use YouWillLikeIT\CareDispatch\Routing\RoutePlanner;
use YouWillLikeIT\CareDispatch\Routing\RouteProposalApplier;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;
use YouWillLikeIT\CareDispatch\Service\AttendanceService;
use YouWillLikeIT\CareDispatch\Service\TourGenerationService;
use YouWillLikeIT\CareDispatch\Service\VisitAssignmentService;
use YouWillLikeIT\CareDispatch\Service\VisitExecutionService;

/**
 * Builds a complete, presentable demo installation:
 *
 * - accounts (PDL + 8 caregivers) with groups and permissions
 * - service catalogue, patients with care plans, tour templates, shifts
 * - past days: generated, distributed (route planner), fully executed
 * - presentation day: generated and executed up to a given time (live state on the board)
 * - future days: generated only (planning view)
 *
 * Field work is replayed through the real services with a mocked clock and the caregiver
 * as current user, so statuses, time entries and the audit trail are consistent.
 */
final class DemoSeeder
{
    private Randomizer $rng;

    /** @var callable(string): void */
    private $log;

    private Member $pdl;

    /** @var array<string, Caregiver> */
    private array $caregivers = [];

    /** @var array<string, CareTask> */
    private array $tasks = [];

    /** @var array<string, Patient> */
    private array $patients = [];

    /** @var array<string, TourTemplate> */
    private array $templates = [];

    /** @var array<int, string> caregiverID => key */
    private array $caregiverKeys = [];

    private int $warnings = 0;

    /** @param (callable(string): void)|null $log */
    public function __construct(?callable $log = null, int $seed = 2026)
    {
        $this->log = $log ?? static function (string $line): void {
        };
        $this->rng = new Randomizer(new Mt19937($seed));
    }

    public static function hasData(): bool
    {
        return Caregiver::get()->exists() || Patient::get()->exists() || CareTask::get()->exists();
    }

    /**
     * Deletes ALL care dispatch data (not only demo records) plus the demo accounts and groups.
     */
    public function reset(): void
    {
        $schema = DataObject::getSchema();
        $tables = [];
        foreach ([
            VisitTask::class, PatientVisit::class, DailyTour::class, TourTemplateItem::class, TourTemplate::class,
            CarePlanItem::class, TimeEntry::class, Shift::class, Patient::class, CareTask::class, Caregiver::class,
            AuditEntry::class,
        ] as $class) {
            $tables[] = $schema->tableName($class);
        }
        foreach (['PreferredCaregivers', 'ExcludedCaregivers'] as $relation) {
            $component = $schema->manyManyComponent(Patient::class, $relation);
            if (is_array($component) && is_string($component['join'] ?? null) && !class_exists($component['join'])) {
                $tables[] = $component['join'];
            }
        }
        foreach ($tables as $table) {
            SQLDelete::create('"' . $table . '"')->execute();
        }
        foreach (Member::get()->filter('Email:EndsWith', '@' . DemoDataset::EMAIL_DOMAIN) as $member) {
            $member->delete();
        }
        foreach (Group::get()->filter('Code', [DemoDataset::GROUP_PDL, DemoDataset::GROUP_FIELD]) as $group) {
            $group->delete();
        }
        $this->say(sprintf('Zurückgesetzt: %d Tabellen geleert, Demo-Konten entfernt.', count($tables)));
    }

    /**
     * @param string      $date          presentation day (YYYY-MM-DD)
     * @param int|null    $progressUntil seconds of day up to which the presentation day is executed; null = not at all
     * @return array{accounts: array<int, array{name:string, email:string, role:string}>, days: array<string, string>, warnings:int}
     */
    public function run(string $date, int $pastDays, int $futureDays, ?int $progressUntil, string $password): array
    {
        $date = Clock::parseDate($date);
        $day = new DateTimeImmutable($date . ' 12:00:00');
        $first = $day->sub(new DateInterval('P' . $pastDays . 'D'))->format(Clock::DB_DATE);
        $realNow = Clock::now();

        try {
            $this->ensureDepot();

            // Master data "two weeks ago".
            $this->mock((new DateTimeImmutable($first))->sub(new DateInterval('P14D'))->format(Clock::DB_DATE), 9 * 3600);
            $accounts = $this->createAccounts($password);
            Security::setCurrentUser($this->pdl);
            $this->createTasks();
            $this->createCaregivers();
            $this->createPatients($date);
            $this->createTemplates();
            $this->say(sprintf(
                'Stammdaten: %d Leistungen, %d Pflegekräfte, %d Patienten, %d Tourvorlagen.',
                count($this->tasks),
                count($this->caregivers),
                count($this->patients),
                count($this->templates)
            ));

            // Care plan changes a few days later (visible in the patient history).
            $this->mock((new DateTimeImmutable($first))->sub(new DateInterval('P3D'))->format(Clock::DB_DATE), 10 * 3600 + 15 * 60);
            $this->carePlanChanges();

            $days = [];
            $cursor = new DateTimeImmutable($first . ' 12:00:00');
            $last = $day->add(new DateInterval('P' . $futureDays . 'D'));
            while ($cursor <= $last) {
                $d = $cursor->format(Clock::DB_DATE);
                $this->createShifts($d);
                $cursor = $cursor->add(new DateInterval('P1D'));
            }

            $cursor = new DateTimeImmutable($first . ' 12:00:00');
            while ($cursor <= $last) {
                $d = $cursor->format(Clock::DB_DATE);
                Security::setCurrentUser($this->pdl);
                $evening = (new DateTimeImmutable($d . ' 18:00:00'))->sub(new DateInterval('P1D'));
                $genAt = $evening < $realNow ? $evening : $realNow;
                $this->mockAt($genAt);
                $summary = TourGenerationService::singleton()->generate($d);
                $label = sprintf('%d Einsätze auf Touren, %d im Pool', $summary['visitsOnTours'], $summary['visitsInPool'] + $summary['companionsInPool']);

                if ($d < $date) {
                    $this->planPastDay($d);
                    $this->simulateDay($d, null, false);
                    $label .= ' – verteilt und vollständig durchgeführt';
                } elseif ($d === $date) {
                    if ($progressUntil !== null) {
                        $this->simulateDay($d, $progressUntil, true);
                        $label .= sprintf(' – Live-Stand bis %s', Clock::short(Clock::secondsToTime($progressUntil)));
                    } else {
                        $label .= ' – ohne Fortschritt';
                    }
                } else {
                    $label .= ' – Planung';
                }
                $days[$d] = $label;
                $this->say(sprintf('%s: %s', $d, $label));
                $cursor = $cursor->add(new DateInterval('P1D'));
            }
        } finally {
            DBDatetime::clear_mock_now();
            Security::setCurrentUser(null);
        }

        return ['accounts' => $accounts, 'days' => $days, 'warnings' => $this->warnings];
    }

    // ------------------------------------------------------------------ master data

    /** @return array<int, array{name:string, email:string, role:string}> */
    private function createAccounts(string $password): array
    {
        $pdlGroup = $this->group(DemoDataset::GROUP_PDL, 'Pflegedienstleitung (Demo)', array_merge(
            [CareDispatchPermissions::DISPATCH, CareDispatchPermissions::MASTERDATA, CareDispatchPermissions::AUDIT],
            $this->adminAccessCodes()
        ));
        $fieldGroup = $this->group(DemoDataset::GROUP_FIELD, 'Pflegekräfte (Demo)', [CareDispatchPermissions::MOBILE]);

        $accounts = [];
        $this->pdl = $this->member(DemoDataset::PDL['first'], DemoDataset::PDL['last'], DemoDataset::PDL['email'], $password, $pdlGroup);
        $accounts[] = ['name' => DemoDataset::PDL['first'] . ' ' . DemoDataset::PDL['last'], 'email' => $this->pdl->Email, 'role' => 'PDL → /pflege/board, /admin/einsatzplanung'];

        foreach (DemoDataset::CAREGIVERS as $key => [$firstName, $lastName, $qualification]) {
            $member = $this->member($firstName, $lastName, null, $password, $fieldGroup);
            $caregiver = Caregiver::create();
            $caregiver->MemberID = (int) $member->ID;
            $this->caregivers[$key] = $caregiver;
            $accounts[] = ['name' => $firstName . ' ' . $lastName, 'email' => $member->Email, 'role' => $qualification . ' → /pflege/mobil'];
        }

        return $accounts;
    }

    /** @param string[] $codes */
    private function group(string $code, string $title, array $codes): Group
    {
        $group = Group::get()->filter('Code', $code)->first() ?? Group::create();
        $group->Title = $title;
        $group->Code = $code;
        $group->write();
        foreach (array_unique($codes) as $permission) {
            Permission::grant((int) $group->ID, $permission);
        }

        return $group;
    }

    /** @return string[] */
    private function adminAccessCodes(): array
    {
        $codes = CareDispatchAdmin::getRequiredPermissions();
        if ($codes === false || $codes === '' || $codes === []) {
            return ['CMS_ACCESS_' . CareDispatchAdmin::class];
        }

        return array_values((array) $codes);
    }

    private function member(string $firstName, string $lastName, ?string $localPart, string $password, Group $group): Member
    {
        $local = $localPart ?? $this->slug($firstName) . '.' . $this->slug($lastName);
        $member = Member::create();
        $member->FirstName = $firstName;
        $member->Surname = $lastName;
        $member->Email = $local . '@' . DemoDataset::EMAIL_DOMAIN;
        $member->Password = $password;
        $member->Locale = 'de_DE';
        $member->write();
        $group->Members()->add($member);

        return $member;
    }

    private function createTasks(): void
    {
        foreach (DemoDataset::TASKS as $code => [$title, $category, $minQualification, $minutes]) {
            $task = CareTask::create();
            $task->Code = $code;
            $task->Title = $title;
            $task->Category = $category;
            $task->MinQualification = $minQualification;
            $task->DurationMinutes = $minutes;
            $task->IsActive = true;
            $task->write();
            $this->tasks[$code] = $task;
        }
    }

    private function createCaregivers(): void
    {
        foreach (DemoDataset::CAREGIVERS as $key => [, , $qualification, $hours, $licence, $color, , $start]) {
            $caregiver = $this->caregivers[$key];
            $caregiver->QualificationLevel = $qualification;
            $caregiver->ContractWeeklyHours = $hours;
            $caregiver->DrivingLicense = $licence;
            $caregiver->BoardColor = $color;
            $caregiver->IsActive = true;
            if ($start !== null) {
                $caregiver->StartStreet = 'Florastraße ' . $this->rng->getInt(2, 90);
                $caregiver->StartPostalCode = '13187';
                $caregiver->StartCity = 'Berlin';
                $caregiver->StartLat = $start[0];
                $caregiver->StartLng = $start[1];
            }
            $caregiver->write();
            $this->caregiverKeys[(int) $caregiver->ID] = $key;
        }
    }

    private function createPatients(string $date): void
    {
        foreach (DemoDataset::PATIENTS as $key => [$firstName, $lastName, $district, $streetIndex, $phone, $access, $notes, $plan, $options]) {
            [$lat, $lng, $streets] = DemoDataset::DISTRICTS[$district];
            [$street, $postcode] = $streets[$streetIndex];
            $patient = Patient::create();
            $patient->FirstName = $firstName;
            $patient->Surname = $lastName;
            $patient->Street = $street . ' ' . $this->rng->getInt(1, 120);
            $patient->PostalCode = $postcode;
            $patient->City = 'Berlin';
            $patient->Country = 'DE';
            $patient->Phone = $phone;
            $patient->AccessNotes = $access;
            $patient->CareNotes = $notes;
            $patient->IsActive = true;
            if (empty($options['noGeo'])) {
                $patient->Lat = round($lat + $this->rng->getFloat(-0.006, 0.006), 7);
                $patient->Lng = round($lng + $this->rng->getFloat(-0.009, 0.009), 7);
                $patient->GeocodedAt = Clock::nowDb();
            }
            $patient->write();
            $this->patients[$key] = $patient;

            foreach ($plan as [$code, $slot, $weekdays, $instruction, $staff, $window]) {
                [$from, $to] = $window ?? DemoDataset::SLOT_WINDOWS[$slot];
                $item = CarePlanItem::create();
                $item->PatientID = (int) $patient->ID;
                $item->CareTaskID = (int) $this->tasks[$code]->ID;
                $item->Slot = $slot;
                $item->Weekdays = $weekdays ?? [1, 2, 3, 4, 5, 6, 7];
                $item->WindowStart = Clock::parseTime($from);
                $item->WindowEnd = Clock::parseTime($to);
                $item->Instruction = $instruction;
                $item->RequiredStaff = $staff;
                if ($key === 'p15') {
                    // "new this week": prescription starts two days before the presentation day
                    $item->ValidFrom = (new DateTimeImmutable($date))->sub(new DateInterval('P2D'))->format(Clock::DB_DATE);
                }
                $item->write();
            }
            foreach ($options['preferred'] ?? [] as $caregiverKey) {
                $patient->PreferredCaregivers()->add($this->caregivers[$caregiverKey]);
            }
            foreach ($options['excluded'] ?? [] as $caregiverKey) {
                $patient->ExcludedCaregivers()->add($this->caregivers[$caregiverKey]);
            }
        }
    }

    private function createTemplates(): void
    {
        foreach (DemoDataset::TOURS as $key => [$title, $shiftType, $defaultCaregiver, $startTime, $stops]) {
            $template = TourTemplate::create();
            $template->Title = $title;
            $template->DefaultShiftType = $shiftType;
            $template->IsActive = true;
            $template->DefaultCaregiverID = $defaultCaregiver !== null ? (int) $this->caregivers[$defaultCaregiver]->ID : 0;
            $template->write();

            $time = Clock::timeToSeconds(Clock::parseTime($startTime));
            $begin = $time;
            $sort = 1;
            foreach ($stops as [$patientKey, $slot]) {
                $item = TourTemplateItem::create();
                $item->TourTemplateID = (int) $template->ID;
                $item->PatientID = (int) $this->patients[$patientKey]->ID;
                $item->Slot = $slot;
                $item->PlannedStart = Clock::secondsToTime($time);
                $item->DurationMinutes = 0;
                $item->SortOrder = $sort++;
                $item->write();
                $time += $this->maxDuration($patientKey, $slot) + DemoDataset::TEMPLATE_TRAVEL_MINUTES * 60;
                $time = (int) (ceil($time / 300) * 300);
            }
            $template->EstimatedDurationMinutes = intdiv($time - $begin, 60);
            $template->write();
            $this->templates[$key] = $template;
        }
    }

    /** Longest possible duration of a patient's slot (all weekday-specific tasks included). */
    private function maxDuration(string $patientKey, string $slot): int
    {
        $minutes = 0;
        foreach (DemoDataset::PATIENTS[$patientKey][7] as [$code, $itemSlot]) {
            if ($itemSlot === $slot) {
                $minutes += DemoDataset::TASKS[$code][3];
            }
        }

        return max(10, $minutes) * 60;
    }

    private function carePlanChanges(): void
    {
        $dragica = $this->patients['p13'];
        foreach ($dragica->CarePlanItems()->filter(['CareTask.Code' => 'HKP 01']) as $item) {
            $item->Instruction = $item->Slot === 'Spaet'
                ? 'Metformin 1000 mg 1-0-1 (abends) – Dosis erhöht laut Arztbrief'
                : 'Metformin 1000 mg 1-0-1 – Dosis erhöht laut Arztbrief';
            $item->write();
        }
        $werner = $this->patients['p05'];
        foreach ($werner->CarePlanItems()->filter(['CareTask.Code' => 'HKP 06']) as $item) {
            $item->Instruction .= '; Wundgröße 3 × 2 cm';
            $item->write();
        }
        $gerda = $this->patients['p04'];
        $gerda->CareNotes = trim((string) $gerda->CareNotes . ' Rötung Steißbein beobachten.');
        $gerda->write();
    }

    private function createShifts(string $date): void
    {
        foreach (DemoDataset::CAREGIVERS as $key => $definition) {
            foreach ($definition[6] as [$type, $start, $end]) {
                $shift = Shift::create();
                $shift->CaregiverID = (int) $this->caregivers[$key]->ID;
                $shift->ShiftDate = $date;
                $shift->ShiftType = $type;
                $shift->StartTime = Clock::parseTime($start);
                $shift->EndTime = Clock::parseTime($end);
                $shift->Status = Shift::STATUS_SCHEDULED;
                $shift->write();
            }
        }
    }

    // ------------------------------------------------------------------ planning of past days

    private function planPastDay(string $date): void
    {
        $this->mock($date, 5 * 3600 + 30 * 60);
        Security::setCurrentUser($this->pdl);

        foreach (DemoDataset::PAST_STAFF_FOR_UNSTAFFED as $templateKey => $caregiverKey) {
            $tour = DailyTour::get()->filter([
                'TourTemplateID' => $this->templates[$templateKey]->ID,
                'TourDate' => $date,
                'CaregiverID' => 0,
            ])->first();
            if ($tour === null) {
                continue;
            }
            $this->attempt(fn () => VisitAssignmentService::singleton()->assignTourCaregiver(
                (int) $tour->ID,
                (int) $this->caregivers[$caregiverKey]->ID,
                true,
                'Tagesplanung (Demo)'
            ), "Tour {$tour->Title}");
        }

        $proposal = RoutePlanner::singleton()->distributePool($date);
        RouteProposalApplier::singleton()->apply($date, $proposal->jsonSerialize(), 'Routenvorschlag übernommen (Demo)');

        foreach (PatientVisit::get()->filter(['VisitDate' => $date, 'Status' => PatientVisit::STATUS_UNASSIGNED]) as $visit) {
            $visit->Status = PatientVisit::STATUS_CANCELLED;
            $visit->Notes = 'Nicht eingeplant (Demo-Daten)';
            $visit->write();
        }
    }

    // ------------------------------------------------------------------ replay of field work

    /**
     * @param int|null $until seconds of day; null = whole day
     */
    private function simulateDay(string $date, ?int $until, bool $isPresentationDay): void
    {
        foreach ($this->caregivers as $key => $caregiver) {
            $events = $this->eventsFor($key, $caregiver, $date, $isPresentationDay);
            if ($events === []) {
                continue;
            }
            Security::setCurrentUser($caregiver->Member());
            foreach ($events as [$time, $type, $visitID]) {
                if ($until !== null && $time > $until) {
                    break;
                }
                $this->mock($date, $time);
                $label = sprintf('%s %s %s', $caregiver->getTitle(), Clock::short(Clock::secondsToTime($time)), $type);
                $this->attempt(fn () => $this->apply($caregiver, $type, $visitID), $label);
            }
        }
        Security::setCurrentUser($this->pdl);
    }

    /**
     * Chronological events of one caregiver: [seconds, type, visitID|null].
     *
     * @return array<int, array{0:int, 1:string, 2:int|null}>
     */
    private function eventsFor(string $key, Caregiver $caregiver, string $date, bool $isPresentationDay): array
    {
        $shifts = Shift::get()
            ->filter(['CaregiverID' => $caregiver->ID, 'ShiftDate' => $date, 'Status' => Shift::STATUS_SCHEDULED])
            ->sort('StartTime')
            ->toArray();
        if ($shifts === []) {
            return [];
        }
        $tourIDs = DailyTour::get()
            ->filter(['CaregiverID' => $caregiver->ID, 'TourDate' => $date, 'Status' => DailyTour::OPEN_STATUSES])
            ->column('ID');
        $visits = $tourIDs === [] ? [] : PatientVisit::get()
            ->filter(['DailyTourID' => $tourIDs, 'Status' => PatientVisit::STATUS_PENDING])
            ->sort(['ScheduledStart' => 'ASC', 'ID' => 'ASC'])
            ->toArray();

        // Mehmed runs late on the presentation day -> "Verspätet" on the board.
        $delay = $this->rng->getInt(0, 8) * 60 + ($isPresentationDay && $key === 'mehmed' ? 22 * 60 : 0);

        $schedule = [];
        $previousEnd = 0;
        foreach ($visits as $visit) {
            $start = max(
                Clock::timeToSeconds($visit->ScheduledStart) + $delay + $this->rng->getInt(0, 4) * 60,
                $previousEnd + $this->rng->getInt(4, 9) * 60
            );
            $duration = max(300, $visit->getDurationSeconds() + $this->rng->getInt(-3, 6) * 60);
            $end = min(86000, $start + $duration);
            $schedule[] = [(int) $visit->ID, $start, $end, $visit->Tasks()->count()];
            $previousEnd = $end;
        }

        // Presence intervals from the shifts, extended to cover every visit.
        $intervals = [];
        foreach ($shifts as $shift) {
            [$s, $e] = $shift->window();
            $intervals[] = [max(0, $s - $this->rng->getInt(2, 8) * 60), min(86300, $e + $this->rng->getInt(0, 8) * 60)];
        }
        foreach ($schedule as [, $start, $end]) {
            $best = 0;
            $bestDistance = PHP_INT_MAX;
            foreach ($intervals as $i => [$in, $out]) {
                $distance = $start < $in ? $in - $start : ($start > $out ? $start - $out : 0);
                if ($distance < $bestDistance) {
                    $best = $i;
                    $bestDistance = $distance;
                }
            }
            $intervals[$best][0] = min($intervals[$best][0], $start - 600);
            $intervals[$best][1] = max($intervals[$best][1], $end + 300);
        }

        $events = [];
        foreach ($intervals as [$in, $out]) {
            $events[] = [$in, 'check_in', null];
            $inside = array_values(array_filter($schedule, static fn (array $v): bool => $v[1] >= $in && $v[1] <= $out));
            // 30 min break in the first sufficient gap after 4 hours (or after the last visit).
            if ($out - $in >= 6 * 3600) {
                $breakAt = null;
                $cursor = $in;
                foreach ($inside as [, $start, $end]) {
                    if ($cursor >= $in + 4 * 3600 && $start - $cursor >= 40 * 60) {
                        $breakAt = $cursor + 300;
                        break;
                    }
                    $cursor = $end;
                }
                if ($breakAt === null && $out - $cursor >= 40 * 60) {
                    $breakAt = max($cursor + 300, $in + 4 * 3600);
                }
                if ($breakAt !== null && $breakAt + 1800 < $out) {
                    $events[] = [$breakAt, 'break_start', null];
                    $events[] = [$breakAt + 1800, 'break_end', null];
                }
            }
            $events[] = [$out, 'check_out', null];
        }
        foreach ($schedule as [$visitID, $start, $end, $taskCount]) {
            $events[] = [$start, 'start', $visitID];
            if ($taskCount > 1) {
                $events[] = [intdiv($start + $end, 2), 'task', $visitID];
            }
            $events[] = [$end, 'complete', $visitID];
        }
        $order = ['check_in' => 0, 'break_end' => 1, 'start' => 2, 'task' => 3, 'complete' => 4, 'break_start' => 5, 'check_out' => 6];
        usort($events, static fn (array $a, array $b): int => [$a[0], $order[$a[1]]] <=> [$b[0], $order[$b[1]]]);

        return $events;
    }

    private function apply(Caregiver $caregiver, string $type, ?int $visitID): void
    {
        $attendance = AttendanceService::singleton();
        $execution = VisitExecutionService::singleton();
        match ($type) {
            'check_in' => $attendance->checkIn($caregiver),
            'check_out' => $attendance->checkOut($caregiver),
            'break_start' => $attendance->toggleBreak($caregiver, 'start'),
            'break_end' => $attendance->toggleBreak($caregiver, 'end'),
            'start' => $execution->start($caregiver, (int) $visitID),
            'task' => $this->documentFirstTask($caregiver, (int) $visitID),
            'complete' => $this->complete($caregiver, (int) $visitID),
        };
    }

    private function documentFirstTask(Caregiver $caregiver, int $visitID): void
    {
        $task = VisitTask::get()->filter(['VisitID' => $visitID, 'Status' => VisitTask::STATUS_OPEN])->sort('SortOrder')->first();
        if ($task !== null) {
            VisitExecutionService::singleton()->updateTask($caregiver, (int) $task->ID, VisitTask::STATUS_DONE, '');
        }
    }

    private function complete(Caregiver $caregiver, int $visitID): void
    {
        $updates = [];
        $tasks = VisitTask::get()->filter(['VisitID' => $visitID, 'Status' => VisitTask::STATUS_OPEN])->toArray();
        foreach ($tasks as $task) {
            $refusable = in_array($task->CareTask()->Code, ['LK 05', 'LK 06', 'LK 07'], true);
            $refused = $refusable && $this->rng->getInt(1, 12) === 1;
            $updates[] = [
                'id' => (int) $task->ID,
                'status' => $refused ? VisitTask::STATUS_NOT_DONE : VisitTask::STATUS_DONE,
                'note' => $refused ? 'Patient lehnt heute ab' : '',
            ];
        }
        $notes = $this->rng->getInt(1, 15) === 1 ? 'Patient wirkt müde, Angehörige telefonisch informiert.' : '';
        VisitExecutionService::singleton()->complete($caregiver, $visitID, $updates, $notes);
    }

    // ------------------------------------------------------------------ helpers

    private function ensureDepot(): void
    {
        $configured = (float) (Environment::getEnv('CARE_DISPATCH_DEPOT_LAT') ?: RoutePlanner::config()->get('depot_lat'));
        if ($configured === 0.0) {
            Config::modify()->set(RoutePlanner::class, 'depot_lat', DemoDataset::DEPOT['lat']);
            Config::modify()->set(RoutePlanner::class, 'depot_lng', DemoDataset::DEPOT['lng']);
        }
    }

    private function attempt(callable $action, string $label): void
    {
        try {
            $action();
        } catch (CareDispatchException $e) {
            $this->warnings++;
            $this->say(sprintf('  ! %s: %s', $label, $e->getMessage()));
        } catch (ValidationException $e) {
            $this->warnings++;
            $this->say(sprintf('  ! %s: %s', $label, $e->getMessage()));
        }
    }

    private function mock(string $date, int $secondsOfDay): void
    {
        DBDatetime::set_mock_now($date . ' ' . Clock::secondsToTime($secondsOfDay));
    }

    private function mockAt(DateTimeImmutable $moment): void
    {
        DBDatetime::set_mock_now($moment->format(Clock::DB_DATETIME));
    }

    private function slug(string $value): string
    {
        $value = strtr($value, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'č' => 'c', 'ć' => 'c', 'ž' => 'z', 'š' => 's', 'đ' => 'dj', 'Č' => 'c', 'Ć' => 'c', 'Ž' => 'z', 'Š' => 's', 'Đ' => 'dj',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }

    private function say(string $line): void
    {
        ($this->log)($line);
    }
}
