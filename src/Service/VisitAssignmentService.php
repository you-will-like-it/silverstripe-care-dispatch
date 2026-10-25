<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Domain\AssignmentCheck;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Domain\Violation;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;

/**
 * Moves visits between tours and the unassigned pool, hands tours to caregivers and
 * creates companion visits for double visits. All operations are transactional, locked
 * and audited.
 */
class VisitAssignmentService
{
    use Injectable;

    private static array $dependencies = [
        'validator' => '%$' . AssignmentValidator::class,
        'audit' => '%$' . AuditLogger::class,
    ];

    public AssignmentValidator $validator;

    public AuditLogger $audit;

    /**
     * Drag & drop target: an existing tour, or a caregiver (their open tour that day or a new ad-hoc tour).
     *
     * @return array{visit: PatientVisit, tour: DailyTour, createdTour: bool, overridden: string[]}
     */
    public function assignVisit(
        int $visitID,
        ?int $targetTourID,
        ?int $targetCaregiverID,
        ?string $targetTime,
        bool $force = false,
        string $reason = '',
    ): array {
        if (!$targetTourID && !$targetCaregiverID) {
            throw BadRequestException::because('target_tour_id oder target_caregiver_id ist erforderlich.');
        }

        return Transaction::run(function () use ($visitID, $targetTourID, $targetCaregiverID, $targetTime, $force, $reason): array {
            Transaction::lock(PatientVisit::class, [$visitID]);
            $visit = $this->loadVisit($visitID);

            $tour = null;
            $caregiver = null;
            if ($targetTourID) {
                Transaction::lock(DailyTour::class, [$targetTourID]);
                $tour = DailyTour::get()->byID($targetTourID) ?? throw NotFoundException::for('Tour', $targetTourID);
                if ($tour->CaregiverID) {
                    $caregiver = $tour->Caregiver();
                }
            } else {
                $caregiver = Caregiver::get()->byID((int) $targetCaregiverID)
                    ?? throw NotFoundException::for('Pflegekraft', (int) $targetCaregiverID);
                $tour = $this->findOpenTour($caregiver, (string) $visit->VisitDate);
            }

            [$start, $end] = $this->resolveTimes($visit, $targetTime);

            $check = $caregiver !== null
                ? $this->validator->check($visit, $caregiver, $start, $end, $tour)
                : $this->checkUnstaffedTour($visit, $tour);
            $check->assertAllowed($force, $reason);

            $createdTour = false;
            if ($tour === null) {
                $tour = $this->createAdHocTour($caregiver, (string) $visit->VisitDate);
                $createdTour = true;
            }

            $fromTourID = (int) $visit->DailyTourID;
            $visit->DailyTourID = (int) $tour->ID;
            $visit->Status = PatientVisit::STATUS_PENDING;
            $visit->ScheduledStart = Clock::secondsToTime($start);
            $visit->ScheduledEnd = Clock::secondsToTime($end);
            $visit->write();

            $this->afterTourChange($tour);
            if ($fromTourID && $fromTourID !== (int) $tour->ID) {
                $this->afterTourChange(DailyTour::get()->byID($fromTourID));
            }

            $overridden = array_map(static fn (Violation $v): string => $v->code, $check->soft());
            $this->audit->event($visit, 'visit_assigned', [
                'fromTourId' => $fromTourID ?: null,
                'toTourId' => (int) $tour->ID,
                'caregiverId' => $caregiver?->ID,
                'start' => Clock::short($visit->ScheduledStart),
                'end' => Clock::short($visit->ScheduledEnd),
                'adHocTourCreated' => $createdTour,
                'overriddenWarnings' => $overridden,
            ], $overridden !== [] ? $reason : '');

            return ['visit' => $visit, 'tour' => $tour, 'createdTour' => $createdTour, 'overridden' => $overridden];
        });
    }

    /** Drag back into the pool. Companions of a primary visit follow it into the pool. */
    public function unassignVisit(int $visitID, string $reason = ''): PatientVisit
    {
        return Transaction::run(function () use ($visitID, $reason): PatientVisit {
            Transaction::lock(PatientVisit::class, [$visitID]);
            $visit = $this->loadVisit($visitID);
            if ($visit->Status !== PatientVisit::STATUS_PENDING) {
                throw new DomainRuleException(
                    'VISIT_NOT_MOVABLE',
                    sprintf('Nur geplante Einsätze können zurück in den Pool (Status: %s).', $visit->Status)
                );
            }
            $affectedTours = [(int) $visit->DailyTourID];
            $this->moveToPool($visit);
            if (!$visit->isCompanion()) {
                foreach ($visit->activeCompanions()->filter('Status', PatientVisit::STATUS_PENDING) as $companion) {
                    $affectedTours[] = (int) $companion->DailyTourID;
                    $this->moveToPool($companion);
                }
            }
            foreach (array_unique(array_filter($affectedTours)) as $tourID) {
                $this->afterTourChange(DailyTour::get()->byID($tourID));
            }
            $this->audit->event($visit, 'visit_unassigned', ['fromTourId' => $affectedTours[0] ?: null], $reason);

            return $visit;
        });
    }

    /**
     * Hands a whole tour to a caregiver (or removes the caregiver with $caregiverID = null).
     */
    public function assignTourCaregiver(int $tourID, ?int $caregiverID, bool $force = false, string $reason = ''): DailyTour
    {
        return Transaction::run(function () use ($tourID, $caregiverID, $force, $reason): DailyTour {
            Transaction::lock(DailyTour::class, [$tourID]);
            $tour = DailyTour::get()->byID($tourID) ?? throw NotFoundException::for('Tour', $tourID);
            if (!$tour->isOpen()) {
                throw new DomainRuleException('TOUR_CLOSED', sprintf('Tour ist %s.', $tour->Status));
            }
            $previous = (int) $tour->CaregiverID;
            $overridden = [];

            if ($caregiverID === null) {
                if ($tour->Visits()->filter('Status', [PatientVisit::STATUS_IN_PROGRESS, PatientVisit::STATUS_COMPLETED])->exists()) {
                    throw new DomainRuleException(
                        'TOUR_STARTED',
                        'Die Tour hat bereits begonnene Einsätze. Bitte die Tour stattdessen unterbrechen (SOS).'
                    );
                }
                $tour->CaregiverID = 0;
            } else {
                $caregiver = Caregiver::get()->byID($caregiverID) ?? throw NotFoundException::for('Pflegekraft', $caregiverID);
                if ($previous && $previous !== $caregiverID
                    && $tour->Visits()->filter('Status', [PatientVisit::STATUS_IN_PROGRESS, PatientVisit::STATUS_COMPLETED])->exists()) {
                    throw new DomainRuleException(
                        'TOUR_STARTED',
                        'Eine begonnene Tour kann nicht umbesetzt werden. Tour unterbrechen (SOS) und Einsätze neu verteilen.'
                    );
                }
                $check = $this->validator->checkTour($tour, $caregiver);
                $check->assertAllowed($force, $reason);
                $overridden = array_map(static fn (Violation $v): string => $v->code, $check->soft());
                $tour->CaregiverID = $caregiverID;
            }
            $tour->refreshStatus();
            $tour->write();

            $this->audit->event($tour, 'tour_caregiver_changed', [
                'fromCaregiverId' => $previous ?: null,
                'toCaregiverId' => $caregiverID,
                'overriddenWarnings' => array_values(array_unique($overridden)),
            ], $overridden !== [] ? $reason : '');

            return $tour;
        });
    }

    /**
     * Creates a companion visit (Begleitung) for a double visit and assigns it to $caregiverID.
     *
     * @return array{visit: PatientVisit, tour: DailyTour, createdTour: bool, overridden: string[]}
     */
    public function addCompanion(int $primaryVisitID, int $caregiverID, bool $force = false, string $reason = ''): array
    {
        $companionID = Transaction::run(function () use ($primaryVisitID): int {
            Transaction::lock(PatientVisit::class, [$primaryVisitID]);
            $primary = $this->loadVisit($primaryVisitID);
            if ($primary->isCompanion()) {
                throw new DomainRuleException('INVALID_PRIMARY', 'Eine Begleitung kann keine weitere Begleitung haben.');
            }
            if (in_array($primary->Status, [PatientVisit::STATUS_COMPLETED, PatientVisit::STATUS_CANCELLED], true)) {
                throw new DomainRuleException('VISIT_CLOSED', 'Für abgeschlossene Einsätze kann keine Begleitung geplant werden.');
            }
            $existing = $primary->activeCompanions()->filter('DailyTourID', 0)->first();
            if ($existing !== null) {
                return (int) $existing->ID;
            }
            $companion = PatientVisit::create();
            $companion->Role = PatientVisit::ROLE_COMPANION;
            $companion->PrimaryVisitID = (int) $primary->ID;
            $companion->PatientID = (int) $primary->PatientID;
            $companion->VisitDate = $primary->VisitDate;
            $companion->Slot = $primary->Slot;
            $companion->ScheduledStart = $primary->ScheduledStart;
            $companion->ScheduledEnd = $primary->ScheduledEnd;
            $companion->WindowStart = $primary->WindowStart;
            $companion->WindowEnd = $primary->WindowEnd;
            $companion->Status = PatientVisit::STATUS_UNASSIGNED;
            $companion->write();

            return (int) $companion->ID;
        });

        return $this->assignVisit($companionID, null, $caregiverID, null, $force, $reason);
    }

    /** The caregiver's open tour on $date, preferring planned tours over ad-hoc tours. */
    public function findOpenTour(Caregiver $caregiver, string $date): ?DailyTour
    {
        return DailyTour::get()
            ->filter(['CaregiverID' => $caregiver->ID, 'TourDate' => $date, 'Status' => DailyTour::OPEN_STATUSES])
            ->sort(['IsAdHoc' => 'ASC', 'ID' => 'ASC'])
            ->first();
    }

    public function createAdHocTour(Caregiver $caregiver, string $date): DailyTour
    {
        $tour = DailyTour::create();
        $tour->TourDate = $date;
        $tour->CaregiverID = (int) $caregiver->ID;
        $tour->IsAdHoc = true;
        $tour->Title = sprintf('Ad-hoc – %s – %s', $caregiver->getTitle(), date('d.m.', strtotime($date)));
        $tour->Status = DailyTour::STATUS_ASSIGNED;
        $tour->write();
        $this->audit->event($tour, 'tour_adhoc_created', ['caregiverId' => (int) $caregiver->ID, 'date' => $date]);

        return $tour;
    }

    /** Moves a visit into the unassigned pool without validation (caller ensures preconditions). */
    public function moveToPool(PatientVisit $visit): void
    {
        $visit->DailyTourID = 0;
        $visit->Status = PatientVisit::STATUS_UNASSIGNED;
        $visit->write();
    }

    public function afterTourChange(?DailyTour $tour): void
    {
        if ($tour === null || !$tour->exists()) {
            return;
        }
        $tour->resequence();
        $tour->refreshStatus();
        $tour->write();
    }

    private function loadVisit(int $visitID): PatientVisit
    {
        return PatientVisit::get()->byID($visitID) ?? throw NotFoundException::for('Einsatz', $visitID);
    }

    /** @return array{0:int,1:int} */
    private function resolveTimes(PatientVisit $visit, ?string $targetTime): array
    {
        $duration = max(300, $visit->getDurationSeconds());
        $start = $targetTime !== null && $targetTime !== ''
            ? Clock::timeToSeconds(Clock::parseTime($targetTime))
            : Clock::timeToSeconds($visit->ScheduledStart);
        $end = $start + $duration;
        if ($end > 86399) {
            throw new DomainRuleException('INVALID_TIME', 'Der Einsatz würde über Mitternacht hinausgehen.');
        }

        return [$start, $end];
    }

    /** Tour without caregiver: only structural checks; qualification is checked when the tour gets a caregiver. */
    private function checkUnstaffedTour(PatientVisit $visit, ?DailyTour $tour): AssignmentCheck
    {
        $check = new AssignmentCheck();
        if (!$visit->isMovable()) {
            $check->add(Violation::hard(Violation::VISIT_NOT_MOVABLE, sprintf('Einsatz hat den Status "%s".', $visit->Status), (int) $visit->ID));
        }
        if ($tour !== null && !$tour->isOpen()) {
            $check->add(Violation::hard(Violation::TOUR_CLOSED, sprintf('Tour "%s" ist %s.', $tour->Title, $tour->Status), (int) $visit->ID));
        }
        if ($tour !== null && (string) $tour->TourDate !== (string) $visit->VisitDate) {
            $check->add(Violation::hard(Violation::DATE_MISMATCH, 'Tour und Einsatz liegen an unterschiedlichen Tagen.', (int) $visit->ID));
        }

        return $check;
    }
}
