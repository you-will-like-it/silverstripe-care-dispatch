<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\CareTask;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\VisitTask;

/**
 * JSON representations shared by the board and the mobile API.
 */
final class VisitPresenter
{
    /**
     * @param array<int, array{task: VisitTask, careTask: ?CareTask}> $tasks
     * @param PatientVisit[] $companions
     * @param string[] $warnings
     * @return array<string, mixed>
     */
    public static function visit(
        PatientVisit $visit,
        ?Patient $patient,
        array $tasks,
        array $companions = [],
        array $warnings = [],
        bool $includeCareDetails = false,
    ): array {
        $required = $visit->getRequiredLevel();
        $point = $patient?->getGeoPoint();

        $dto = [
            'id' => (int) $visit->ID,
            'date' => (string) $visit->VisitDate,
            'slot' => (string) $visit->Slot,
            'start' => Clock::short($visit->ScheduledStart),
            'end' => Clock::short($visit->ScheduledEnd),
            'windowStart' => Clock::short($visit->WindowStart),
            'windowEnd' => Clock::short($visit->WindowEnd),
            'actualStart' => Clock::iso($visit->ActualStart),
            'actualEnd' => Clock::iso($visit->ActualEnd),
            'status' => (string) $visit->Status,
            'role' => (string) $visit->Role,
            'primaryVisitId' => (int) $visit->PrimaryVisitID ?: null,
            'requiredStaff' => (int) $visit->RequiredStaff,
            'requiredQualification' => $required->value,
            'requiredQualificationLabel' => $required->label(),
            'requiredRank' => $required->rank(),
            'tourId' => (int) $visit->DailyTourID ?: null,
            'sortOrder' => (int) $visit->SortOrder,
            'notes' => $visit->Notes ?: null,
            'patient' => $patient === null ? null : [
                'id' => (int) $patient->ID,
                'name' => $patient->getTitle(),
                'address' => $patient->getAddressLine(),
                'lat' => $point?->lat,
                'lng' => $point?->lng,
            ],
            'tasks' => array_map(static fn (array $row): array => self::task($row['task'], $row['careTask']), $tasks),
            'companions' => array_map(static fn (PatientVisit $c): array => [
                'visitId' => (int) $c->ID,
                'tourId' => (int) $c->DailyTourID ?: null,
                'status' => (string) $c->Status,
            ], $companions),
            'warnings' => array_values($warnings),
        ];

        if ($includeCareDetails && $patient !== null) {
            $dto['patient']['phone'] = $patient->Phone ?: null;
            $dto['patient']['accessNotes'] = $patient->AccessNotes ?: null;
            $dto['patient']['careNotes'] = $patient->CareNotes ?: null;
        }

        return $dto;
    }

    /** @return array<string, mixed> */
    public static function task(VisitTask $task, ?CareTask $careTask): array
    {
        return [
            'id' => (int) $task->ID,
            'code' => $careTask?->Code,
            'title' => $careTask?->Title ?? 'Leistung',
            'category' => $careTask?->Category,
            'minQualification' => $careTask?->MinQualification,
            'durationMinutes' => $careTask !== null ? (int) $careTask->DurationMinutes : null,
            'instruction' => $task->Instruction ?: null,
            'status' => (string) $task->Status,
            'doneAt' => Clock::iso($task->DoneAt),
            'note' => $task->Note ?: null,
        ];
    }
}
