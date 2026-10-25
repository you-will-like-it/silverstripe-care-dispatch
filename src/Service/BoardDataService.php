<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Model\Absence;
use YouWillLikeIT\CareDispatch\Model\CareDispatchSettings;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\CareTask;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\Shift;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;
use YouWillLikeIT\CareDispatch\Model\VisitTask;

/**
 * Builds the complete PDL board payload for one day with a fixed number of queries
 * (no per-row lookups).
 */
class BoardDataService
{
    use Configurable;
    use Injectable;

    /** A pending visit is flagged LATE this many minutes after its scheduled start. */
    private static int $late_after_minutes = 15;

    /** Visible time range of the board timeline. */
    private static string $timeline_start = '05:00';

    private static string $timeline_end = '23:00';

    /** @return array<string, mixed> */
    public function build(string $date): array
    {
        $date = Clock::parseDate($date);

        $tours = DailyTour::get()->filter('TourDate', $date)->sort(['Title' => 'ASC', 'ID' => 'ASC'])->toArray();
        $visits = PatientVisit::get()
            ->filter('VisitDate', $date)
            ->exclude('Status', PatientVisit::STATUS_CANCELLED)
            ->sort(['ScheduledStart' => 'ASC', 'ID' => 'ASC'])
            ->toArray();

        $visitIDs = array_map(static fn (PatientVisit $v): int => (int) $v->ID, $visits);
        $patients = $this->indexByID(Patient::get()->byIDs(array_unique(array_map(static fn ($v): int => (int) $v->PatientID, $visits)) ?: [0]));
        $tasksByVisit = $this->tasksByVisit($visitIDs);

        $shiftsByCaregiver = [];
        foreach (Shift::get()->filter(['ShiftDate' => $date, 'Status' => Shift::STATUS_SCHEDULED])->sort('StartTime') as $shift) {
            $shiftsByCaregiver[(int) $shift->CaregiverID][] = $shift;
        }
        $attendance = $this->attendance($date);

        $companionsByPrimary = [];
        foreach ($visits as $visit) {
            if ($visit->isCompanion() && $visit->PrimaryVisitID) {
                $companionsByPrimary[(int) $visit->PrimaryVisitID][] = $visit;
            }
        }

        $visitDtos = [];
        $tourVisitIDs = [];
        $pool = [];
        $now = Clock::now();
        $isToday = $date === Clock::today();
        $nowSeconds = Clock::secondsOfDay($now);
        $settings = CareDispatchSettings::current();
        $lateAfter = (int) ($settings->LateAfterMinutes ?: static::config()->get('late_after_minutes')) * 60;

        foreach ($visits as $visit) {
            $patient = $patients[(int) $visit->PatientID] ?? null;
            $companions = $companionsByPrimary[(int) $visit->ID] ?? [];
            $warnings = [];
            if (!$visit->isCompanion() && (int) $visit->RequiredStaff >= 2) {
                $staffed = array_filter($companions, static fn (PatientVisit $c): bool => (int) $c->DailyTourID > 0);
                if ($staffed === []) {
                    $warnings[] = 'COMPANION_MISSING';
                }
            }
            if ($patient === null || $patient->getGeoPoint() === null) {
                $warnings[] = 'NO_GEO';
            }
            if ($isToday && $visit->Status === PatientVisit::STATUS_PENDING
                && $nowSeconds > Clock::timeToSeconds($visit->ScheduledStart) + $lateAfter) {
                $warnings[] = 'LATE';
            }

            $dto = VisitPresenter::visit($visit, $patient, $tasksByVisit[(int) $visit->ID] ?? [], $companions, $warnings);
            $visitDtos[] = $dto;
            if ($visit->DailyTourID) {
                $tourVisitIDs[(int) $visit->DailyTourID][] = (int) $visit->ID;
            } elseif ($visit->Status === PatientVisit::STATUS_UNASSIGNED) {
                $pool[] = (int) $visit->ID;
            }
        }

        $caregiverIDsOnDuty = array_unique(array_merge(
            array_keys($shiftsByCaregiver),
            array_keys($attendance),
            array_filter(array_map(static fn (DailyTour $t): int => (int) $t->CaregiverID, $tours))
        ));

        $absences = Absence::mapForDate($date);
        $caregiverDtos = [];
        foreach (Caregiver::get()->filterAny(['IsActive' => true, 'ID' => $caregiverIDsOnDuty ?: [0]]) as $caregiver) {
            $id = (int) $caregiver->ID;
            $caregiverDtos[] = [
                'id' => $id,
                'name' => $caregiver->getTitle(),
                'initials' => $this->initials($caregiver->getTitle()),
                'color' => $caregiver->BoardColor ?: null,
                'qualification' => $caregiver->getQualification()->value,
                'qualificationLabel' => $caregiver->getQualification()->label(),
                'rank' => $caregiver->getQualification()->rank(),
                'drivingLicense' => (bool) $caregiver->DrivingLicense,
                'isActive' => (bool) $caregiver->IsActive,
                'onDuty' => in_array($id, $caregiverIDsOnDuty, true) && !isset($absences[$id]),
                'absence' => isset($absences[$id]) ? [
                    'type' => (string) $absences[$id]->Type,
                    'label' => $absences[$id]->getTypeLabel(),
                    'until' => (string) $absences[$id]->DateTo,
                ] : null,
                'shifts' => array_map(static fn (Shift $s): array => [
                    'id' => (int) $s->ID,
                    'type' => $s->ShiftType,
                    'typeLabel' => $s->getShiftTypeLabel(),
                    'start' => Clock::short($s->StartTime),
                    'end' => Clock::short($s->EndTime),
                ], $shiftsByCaregiver[$id] ?? []),
                'attendance' => $attendance[$id] ?? null,
            ];
        }
        usort($caregiverDtos, static fn (array $a, array $b): int => [!$a['onDuty'], $a['name']] <=> [!$b['onDuty'], $b['name']]);

        $tourDtos = array_map(static fn (DailyTour $t): array => [
            'id' => (int) $t->ID,
            'title' => (string) $t->Title,
            'status' => (string) $t->Status,
            'caregiverId' => (int) $t->CaregiverID ?: null,
            'templateId' => (int) $t->TourTemplateID ?: null,
            'isAdHoc' => (bool) $t->IsAdHoc,
            'interruptedAt' => Clock::iso($t->InterruptedAt),
            'interruptReason' => $t->InterruptReason ?: null,
            'visitIds' => $tourVisitIDs[(int) $t->ID] ?? [],
        ], $tours);

        return [
            'date' => $date,
            'now' => $now->format(DATE_ATOM),
            'isToday' => $isToday,
            'timeline' => [
                'start' => (string) ($settings->TimelineStart ?: static::config()->get('timeline_start')),
                'end' => (string) ($settings->TimelineEnd ?: static::config()->get('timeline_end')),
            ],
            'qualifications' => array_map(static fn (QualificationLevel $q): array => [
                'value' => $q->value,
                'label' => $q->label(),
                'rank' => $q->rank(),
            ], QualificationLevel::cases()),
            'caregivers' => $caregiverDtos,
            'tours' => $tourDtos,
            'visits' => $visitDtos,
            'pool' => $pool,
        ];
    }

    /**
     * @param int[] $visitIDs
     * @return array<int, array<int, array{task: VisitTask, careTask: ?CareTask}>>
     */
    public function tasksByVisit(array $visitIDs): array
    {
        if ($visitIDs === []) {
            return [];
        }
        $tasks = VisitTask::get()->filter('VisitID', $visitIDs)->sort(['VisitID' => 'ASC', 'SortOrder' => 'ASC'])->toArray();
        $careTasks = $this->indexByID(CareTask::get()->byIDs(array_unique(array_map(static fn ($t): int => (int) $t->CareTaskID, $tasks)) ?: [0]));
        $out = [];
        foreach ($tasks as $task) {
            $out[(int) $task->VisitID][] = ['task' => $task, 'careTask' => $careTasks[(int) $task->CareTaskID] ?? null];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> caregiverID => attendance */
    private function attendance(string $date): array
    {
        $dayStart = $date . ' 00:00:00';
        $dayEnd = $date . ' 23:59:59';
        $entries = TimeEntry::get()
            ->filterAny([
                'CheckIn:GreaterThanOrEqual' => $dayStart,
                'Status:not' => TimeEntry::STATUS_COMPLETED,
            ])
            ->filter('CheckIn:LessThanOrEqual', $dayEnd)
            ->sort('CheckIn', 'ASC');
        $out = [];
        foreach ($entries as $entry) {
            // Later entries of the same day win (e.g. second check-in after a split shift).
            $out[(int) $entry->CaregiverID] = [
                'entryId' => (int) $entry->ID,
                'status' => (string) $entry->Status,
                'checkIn' => Clock::iso($entry->CheckIn),
                'checkOut' => Clock::iso($entry->CheckOut),
                'breakMinutes' => $entry->getBreakMinutes(),
                'autoClosed' => (bool) $entry->AutoClosed,
            ];
        }

        return $out;
    }

    /**
     * @template T of \SilverStripe\ORM\DataObject
     * @param iterable<T> $list
     * @return array<int, T>
     */
    private function indexByID(iterable $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $out[(int) $item->ID] = $item;
        }

        return $out;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(static fn (string $p): string => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters);
    }
}
