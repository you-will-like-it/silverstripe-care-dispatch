<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\CarePlanItem;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\Shift;
use YouWillLikeIT\CareDispatch\Model\TourTemplate;
use YouWillLikeIT\CareDispatch\Model\VisitTask;

/**
 * Builds a day: tours from templates, visits and their tasks from the patients' care plans.
 *
 * - One visit per patient and slot (all care plan positions of that slot are grouped).
 * - Visits whose patient/slot is on a template become Pending on that tour (at the template's time).
 * - All other due visits land Unassigned in the pool (start = beginning of the time window).
 * - Double visits (RequiredStaff = 2) get an unassigned companion visit in the pool.
 * - The template's Bezugspflegekraft is assigned if they have a shift and pass all hard rules.
 * - Idempotent: existing tours (template + date) and visits (patient + date + slot) are reused/skipped.
 */
class TourGenerationService
{
    use Configurable;
    use Injectable;

    /** Minimum planned visit duration in minutes. */
    private static int $min_visit_minutes = 10;

    private static array $dependencies = [
        'validator' => '%$' . AssignmentValidator::class,
        'audit' => '%$' . AuditLogger::class,
    ];

    public AssignmentValidator $validator;

    public AuditLogger $audit;

    /**
     * @return array{date:string, toursCreated:int, toursStaffed:int, visitsOnTours:int, visitsInPool:int, companionsInPool:int, skippedExisting:int, notes:string[]}
     */
    public function generate(string $date): array
    {
        $date = Clock::parseDate($date);
        $weekday = Clock::isoWeekday($date);

        return Transaction::run(fn (): array => $this->audit->suspended(function () use ($date, $weekday): array {
            $summary = [
                'date' => $date,
                'toursCreated' => 0,
                'toursStaffed' => 0,
                'visitsOnTours' => 0,
                'visitsInPool' => 0,
                'companionsInPool' => 0,
                'skippedExisting' => 0,
                'notes' => [],
            ];

            $groups = $this->dueGroups($date);
            $existingKeys = $this->existingVisitKeys($date);

            /** @var array<int, DailyTour> $touchedTours */
            $touchedTours = [];
            $templates = TourTemplate::get()->filter('IsActive', true)->sort('Title');
            foreach ($templates as $template) {
                if (!$template->appliesOn($weekday)) {
                    continue;
                }
                $tour = DailyTour::get()->filter(['TourTemplateID' => $template->ID, 'TourDate' => $date])->first();
                if ($tour === null) {
                    $tour = DailyTour::create();
                    $tour->TourTemplateID = (int) $template->ID;
                    $tour->TourDate = $date;
                    $tour->Title = (string) $template->Title;
                    $tour->Status = DailyTour::STATUS_DRAFT;
                    $tour->write();
                    $summary['toursCreated']++;
                }

                foreach ($template->Items()->sort('SortOrder') as $item) {
                    $key = $item->PatientID . '|' . $item->Slot;
                    if (isset($existingKeys[$key])) {
                        $summary['skippedExisting']++;
                        unset($groups[$key]);
                        continue;
                    }
                    if (!isset($groups[$key])) {
                        continue; // nothing due for this patient/slot today
                    }
                    $start = Clock::timeToSeconds($item->PlannedStart);
                    $duration = (int) $item->DurationMinutes > 0
                        ? (int) $item->DurationMinutes * 60
                        : $this->durationOf($groups[$key]);
                    $visit = $this->createVisit($date, $groups[$key], $start, $duration, $tour);
                    $existingKeys[$key] = true;
                    unset($groups[$key]);
                    $summary['visitsOnTours']++;
                    if ((int) $visit->RequiredStaff === 2) {
                        $this->createCompanion($visit);
                        $summary['companionsInPool']++;
                    }
                }
                $touchedTours[(int) $tour->ID] = $tour;
            }

            foreach ($groups as $key => $items) {
                if (isset($existingKeys[$key])) {
                    $summary['skippedExisting']++;
                    continue;
                }
                $start = min(array_map(static fn (CarePlanItem $i): int => Clock::timeToSeconds($i->WindowStart), $items));
                $visit = $this->createVisit($date, $items, $start, $this->durationOf($items), null);
                $summary['visitsInPool']++;
                if ((int) $visit->RequiredStaff === 2) {
                    $this->createCompanion($visit);
                    $summary['companionsInPool']++;
                }
            }

            foreach ($touchedTours as $tour) {
                $tour->resequence();
                if (!$tour->CaregiverID && $this->staffFromTemplate($tour, $date, $summary['notes'])) {
                    $summary['toursStaffed']++;
                }
                $tour->refreshStatus();
                $tour->write();
            }

            $this->audit->systemEvent(self::class, 'day_generated', $summary);

            return $summary;
        }));
    }

    /**
     * Care plan positions due on $date, grouped by "patientID|slot".
     *
     * @return array<string, CarePlanItem[]>
     */
    private function dueGroups(string $date): array
    {
        $items = CarePlanItem::get()
            ->filter([
                'Patient.IsActive' => true,
                'CareTask.IsActive' => true,
            ])
            ->sort(['PatientID' => 'ASC', 'Slot' => 'ASC', 'WindowStart' => 'ASC']);
        $groups = [];
        foreach ($items as $item) {
            if (!$item->appliesOn($date)) {
                continue;
            }
            $groups[$item->PatientID . '|' . $item->Slot][] = $item;
        }

        return $groups;
    }

    /** @return array<string, true> */
    private function existingVisitKeys(string $date): array
    {
        $keys = [];
        $existing = PatientVisit::get()->filter([
            'VisitDate' => $date,
            'Role' => PatientVisit::ROLE_PRIMARY,
        ]);
        foreach ($existing as $visit) {
            $keys[$visit->PatientID . '|' . $visit->Slot] = true;
        }

        return $keys;
    }

    /** @param CarePlanItem[] $items */
    private function durationOf(array $items): int
    {
        $minutes = 0;
        foreach ($items as $item) {
            $minutes += (int) $item->CareTask()->DurationMinutes;
        }

        return max((int) static::config()->get('min_visit_minutes'), $minutes) * 60;
    }

    /** @param CarePlanItem[] $items */
    private function createVisit(string $date, array $items, int $start, int $duration, ?DailyTour $tour): PatientVisit
    {
        $first = $items[0];
        $end = min(86399, $start + $duration);
        $visit = PatientVisit::create();
        $visit->PatientID = (int) $first->PatientID;
        $visit->VisitDate = $date;
        $visit->Slot = $first->Slot;
        $visit->ScheduledStart = Clock::secondsToTime($start);
        $visit->ScheduledEnd = Clock::secondsToTime($end);
        $visit->WindowStart = Clock::secondsToTime(min(array_map(static fn (CarePlanItem $i): int => Clock::timeToSeconds($i->WindowStart), $items)));
        $visit->WindowEnd = Clock::secondsToTime(max(array_map(static fn (CarePlanItem $i): int => Clock::timeToSeconds($i->WindowEnd), $items)));
        $visit->RequiredStaff = max(array_map(static fn (CarePlanItem $i): int => (int) $i->RequiredStaff, $items));
        $visit->Role = PatientVisit::ROLE_PRIMARY;
        if ($tour !== null) {
            $visit->DailyTourID = (int) $tour->ID;
            $visit->Status = PatientVisit::STATUS_PENDING;
        } else {
            $visit->Status = PatientVisit::STATUS_UNASSIGNED;
        }
        $visit->write();

        $sort = 1;
        foreach ($items as $item) {
            $task = VisitTask::create();
            $task->VisitID = (int) $visit->ID;
            $task->CareTaskID = (int) $item->CareTaskID;
            $task->CarePlanItemID = (int) $item->ID;
            $task->Instruction = (string) $item->Instruction;
            $task->SortOrder = $sort++;
            $task->write();
        }

        return $visit;
    }

    private function createCompanion(PatientVisit $primary): PatientVisit
    {
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

        return $companion;
    }

    /**
     * Assigns the template's Bezugspflegekraft when they have a shift that day and
     * no hard rule is violated. Soft warnings are accepted (planning happens ahead of time).
     *
     * @param string[] $notes
     */
    private function staffFromTemplate(DailyTour $tour, string $date, array &$notes): bool
    {
        $template = $tour->TourTemplate();
        if (!$template->DefaultCaregiverID) {
            return false;
        }
        $caregiver = $template->DefaultCaregiver();
        $hasShift = Shift::get()->filter([
            'CaregiverID' => $caregiver->ID,
            'ShiftDate' => $date,
            'Status' => Shift::STATUS_SCHEDULED,
        ])->exists();
        if (!$hasShift) {
            $notes[] = sprintf('%s: %s hat keinen Dienst, Tour bleibt unbesetzt.', $tour->Title, $caregiver->getTitle());

            return false;
        }
        $check = $this->validator->checkTour($tour, $caregiver);
        if ($check->hasHard()) {
            $notes[] = sprintf(
                '%s: %s nicht zugewiesen – %s',
                $tour->Title,
                $caregiver->getTitle(),
                implode(' ', array_map(static fn ($v): string => $v->message, $check->hard()))
            );

            return false;
        }
        $tour->CaregiverID = (int) $caregiver->ID;

        return true;
    }
}
