<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Domain\AssignmentCheck;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Domain\Violation;
use YouWillLikeIT\CareDispatch\Model\Absence;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\Shift;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;

/**
 * Validates whether a caregiver may take a visit at a given time.
 *
 * Hard rules (never overridable):
 *  - visit must be movable (Pending/Unassigned)
 *  - caregiver active and not absent, target tour open and on the visit's date
 *  - primary: rank(caregiver) >= rank(visit requirement); Azubi never primary
 *  - patient has not excluded the caregiver
 *  - companion and primary of a double visit are different people
 *
 * Soft rules (PDL may confirm with force + reason):
 *  - overlap with the caregiver's other visits that day
 *  - outside / without a scheduled shift
 *  - not checked in (only for today)
 *  - outside the care plan time window
 */
class AssignmentValidator
{
    use Injectable;

    public function check(
        PatientVisit $visit,
        Caregiver $caregiver,
        int $startSeconds,
        int $endSeconds,
        ?DailyTour $targetTour,
        bool $requireMovable = true,
    ): AssignmentCheck {
        $check = new AssignmentCheck();
        $visitID = (int) $visit->ID;
        $date = (string) $visit->VisitDate;

        if ($requireMovable && !$visit->isMovable()) {
            $check->add(Violation::hard(
                Violation::VISIT_NOT_MOVABLE,
                sprintf('Einsatz hat den Status "%s" und kann nicht verschoben werden.', $visit->Status),
                $visitID
            ));
        }
        if (!$caregiver->IsActive) {
            $check->add(Violation::hard(Violation::CAREGIVER_INACTIVE, $caregiver->getTitle() . ' ist nicht aktiv.', $visitID));
        }
        $this->checkAbsence($check, $visitID, $caregiver, $date);
        if ($targetTour !== null) {
            if (!$targetTour->isOpen()) {
                $check->add(Violation::hard(
                    Violation::TOUR_CLOSED,
                    sprintf('Tour "%s" ist %s und nimmt keine Einsätze mehr an.', $targetTour->Title, $targetTour->Status),
                    $visitID
                ));
            }
            if ((string) $targetTour->TourDate !== $date) {
                $check->add(Violation::hard(
                    Violation::DATE_MISMATCH,
                    sprintf('Tour ist am %s, Einsatz am %s.', $targetTour->TourDate, $date),
                    $visitID
                ));
            }
        }

        $this->checkQualification($check, $visit, $caregiver);
        $this->checkExclusion($check, $visit, $caregiver);
        $this->checkOverlap($check, $visit, $caregiver, $date, $startSeconds, $endSeconds);
        $this->checkShift($check, $visitID, $caregiver, $date, $startSeconds, $endSeconds);
        $this->checkAttendance($check, $visitID, $caregiver, $date);
        $this->checkTimeWindow($check, $visit, $startSeconds);

        return $check;
    }

    /**
     * Validates handing a whole tour (all its pending visits) to a caregiver.
     */
    public function checkTour(DailyTour $tour, Caregiver $caregiver): AssignmentCheck
    {
        $check = new AssignmentCheck();
        if (!$tour->isOpen()) {
            $check->add(Violation::hard(Violation::TOUR_CLOSED, sprintf('Tour "%s" ist %s.', $tour->Title, $tour->Status)));

            return $check;
        }
        if (!$caregiver->IsActive) {
            $check->add(Violation::hard(Violation::CAREGIVER_INACTIVE, $caregiver->getTitle() . ' ist nicht aktiv.'));

            return $check;
        }
        $this->checkAbsence($check, null, $caregiver, (string) $tour->TourDate);
        if ($check->hasHard()) {
            return $check;
        }
        $pending = $tour->Visits()->filter('Status', PatientVisit::STATUS_PENDING)->sort('ScheduledStart');
        foreach ($pending as $visit) {
            $start = Clock::timeToSeconds($visit->ScheduledStart);
            $end = Clock::timeToSeconds($visit->ScheduledEnd);
            $visitCheck = new AssignmentCheck();
            $this->checkQualification($visitCheck, $visit, $caregiver);
            $this->checkExclusion($visitCheck, $visit, $caregiver);
            $this->checkOverlap($visitCheck, $visit, $caregiver, (string) $tour->TourDate, $start, $end, (int) $tour->ID);
            $this->checkShift($visitCheck, (int) $visit->ID, $caregiver, (string) $tour->TourDate, $start, $end);
            $check->merge($visitCheck);
        }
        $this->checkAttendance($check, null, $caregiver, (string) $tour->TourDate);

        return $check;
    }

    private function checkAbsence(AssignmentCheck $check, ?int $visitID, Caregiver $caregiver, string $date): void
    {
        $absence = Absence::on((int) $caregiver->ID, $date);
        if ($absence !== null) {
            $check->add(Violation::hard(
                Violation::CAREGIVER_ABSENT,
                sprintf('%s ist abwesend (%s bis %s).', $caregiver->getTitle(), $absence->getTypeLabel(), date('d.m.', strtotime((string) $absence->DateTo))),
                $visitID
            ));
        }
    }

    private function checkQualification(AssignmentCheck $check, PatientVisit $visit, Caregiver $caregiver): void
    {
        if ($visit->isCompanion()) {
            $primary = $visit->PrimaryVisit();
            if ($primary->exists() && $primary->DailyTourID && (int) $primary->DailyTour()->CaregiverID === (int) $caregiver->ID) {
                $check->add(Violation::hard(
                    Violation::COMPANION_SAME_CAREGIVER,
                    'Begleitung und Haupteinsatz müssen von verschiedenen Personen übernommen werden.',
                    (int) $visit->ID
                ));
            }

            return;
        }

        $level = $caregiver->getQualification();
        $required = $visit->getRequiredLevel();
        if ($level === QualificationLevel::Azubi) {
            $check->add(Violation::hard(
                Violation::AZUBI_AS_PRIMARY,
                'Auszubildende dürfen Einsätze nur als Begleitung übernehmen.',
                (int) $visit->ID
            ));
        } elseif (!$level->satisfies($required)) {
            $check->add(Violation::hard(
                Violation::QUALIFICATION_INSUFFICIENT,
                sprintf(
                    '%s benötigt mindestens %s, %s ist %s.',
                    $visit->Patient()->getTitle(),
                    $required->label(),
                    $caregiver->getTitle(),
                    $level->label()
                ),
                (int) $visit->ID
            ));
        }
        // Companions of this visit must not be the new primary caregiver either.
        foreach ($visit->activeCompanions()->filter('DailyTourID:GreaterThan', 0) as $companion) {
            if ((int) $companion->DailyTour()->CaregiverID === (int) $caregiver->ID) {
                $check->add(Violation::hard(
                    Violation::COMPANION_SAME_CAREGIVER,
                    $caregiver->getTitle() . ' ist bereits als Begleitung für diesen Einsatz eingeplant.',
                    (int) $visit->ID
                ));
            }
        }
    }

    private function checkExclusion(AssignmentCheck $check, PatientVisit $visit, Caregiver $caregiver): void
    {
        $excluded = $visit->Patient()->ExcludedCaregivers()->filter('ID', $caregiver->ID)->exists();
        if ($excluded) {
            $check->add(Violation::hard(
                Violation::CAREGIVER_EXCLUDED,
                sprintf('%s hat %s als Pflegekraft ausgeschlossen.', $visit->Patient()->getTitle(), $caregiver->getTitle()),
                (int) $visit->ID
            ));
        }
    }

    private function checkOverlap(
        AssignmentCheck $check,
        PatientVisit $visit,
        Caregiver $caregiver,
        string $date,
        int $start,
        int $end,
        ?int $ignoreTourID = null,
    ): void {
        $tourIDs = DailyTour::get()
            ->filter(['CaregiverID' => $caregiver->ID, 'TourDate' => $date, 'Status' => DailyTour::OPEN_STATUSES])
            ->column('ID');
        if ($ignoreTourID !== null) {
            $tourIDs = array_values(array_diff($tourIDs, [$ignoreTourID]));
        }
        if ($tourIDs === []) {
            return;
        }
        $others = PatientVisit::get()
            ->filter([
                'DailyTourID' => $tourIDs,
                'Status' => [PatientVisit::STATUS_PENDING, PatientVisit::STATUS_IN_PROGRESS, PatientVisit::STATUS_COMPLETED],
            ])
            ->exclude('ID', $visit->ID ?: 0);
        foreach ($others as $other) {
            $oStart = Clock::timeToSeconds($other->ScheduledStart);
            $oEnd = Clock::timeToSeconds($other->ScheduledEnd);
            if ($start < $oEnd && $oStart < $end) {
                $check->add(Violation::soft(
                    Violation::TIME_OVERLAP,
                    sprintf(
                        'Überschneidung mit %s (%s–%s).',
                        $other->Patient()->getTitle(),
                        Clock::short($other->ScheduledStart),
                        Clock::short($other->ScheduledEnd)
                    ),
                    (int) $visit->ID
                ));
            }
        }
    }

    private function checkShift(AssignmentCheck $check, ?int $visitID, Caregiver $caregiver, string $date, int $start, int $end): void
    {
        $shifts = Shift::get()->filter([
            'CaregiverID' => $caregiver->ID,
            'ShiftDate' => $date,
            'Status' => Shift::STATUS_SCHEDULED,
        ]);
        if (!$shifts->exists()) {
            $check->add(Violation::soft(
                Violation::NO_SHIFT,
                sprintf('%s hat am %s keinen geplanten Dienst.', $caregiver->getTitle(), $date),
                $visitID
            ));

            return;
        }
        foreach ($shifts as $shift) {
            if ($shift->covers($start, $end)) {
                return;
            }
        }
        $check->add(Violation::soft(
            Violation::OUTSIDE_SHIFT,
            sprintf('%s–%s liegt außerhalb des Dienstes von %s.', Clock::short(Clock::secondsToTime($start)), Clock::short(Clock::secondsToTime($end)), $caregiver->getTitle()),
            $visitID
        ));
    }

    private function checkAttendance(AssignmentCheck $check, ?int $visitID, Caregiver $caregiver, string $date): void
    {
        if ($date !== Clock::today()) {
            return;
        }
        if (TimeEntry::openFor((int) $caregiver->ID) === null) {
            $check->add(Violation::soft(
                Violation::NOT_CHECKED_IN,
                $caregiver->getTitle() . ' ist aktuell nicht eingestempelt.',
                $visitID
            ));
        }
    }

    private function checkTimeWindow(AssignmentCheck $check, PatientVisit $visit, int $start): void
    {
        if (!$visit->WindowStart || !$visit->WindowEnd) {
            return;
        }
        $from = Clock::timeToSeconds($visit->WindowStart);
        $to = Clock::timeToSeconds($visit->WindowEnd);
        if ($start < $from || $start > $to) {
            $check->add(Violation::soft(
                Violation::OUTSIDE_TIME_WINDOW,
                sprintf('Beginn liegt außerhalb des Zeitfensters %s–%s.', Clock::short($visit->WindowStart), Clock::short($visit->WindowEnd)),
                (int) $visit->ID
            ));
        }
    }
}
