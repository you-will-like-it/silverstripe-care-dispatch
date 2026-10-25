<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\AccessDeniedException;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;
use YouWillLikeIT\CareDispatch\Service\AttendanceService;
use YouWillLikeIT\CareDispatch\Service\TourInterruptService;
use YouWillLikeIT\CareDispatch\Service\VisitExecutionService;
use YouWillLikeIT\CareDispatch\Service\VisitPresenter;

/**
 * Field staff API. Routed at /api/mobile. Every action operates exclusively on the
 * caregiver linked to the logged-in member.
 *
 * Mutating endpoints return {"result": ..., "state": <fresh my-tour payload>}.
 */
class CaregiverMobileController extends JsonApiController
{
    private static string $required_permission = CareDispatchPermissions::MOBILE;

    private static array $url_handlers = [
        'GET my-tour' => 'myTour',
        'POST check-in' => 'checkIn',
        'POST check-out' => 'checkOut',
        'POST break' => 'toggleBreak',
        'POST visit/$ID!/status' => 'visitStatus',
        'POST task/$ID!' => 'updateTask',
        'POST sos' => 'sos',
    ];

    private static array $allowed_actions = [
        'myTour',
        'checkIn',
        'checkOut',
        'toggleBreak',
        'visitStatus',
        'updateTask',
        'sos',
    ];

    private static array $dependencies = [
        'attendance' => '%$' . AttendanceService::class,
        'execution' => '%$' . VisitExecutionService::class,
        'interrupt' => '%$' . TourInterruptService::class,
    ];

    public AttendanceService $attendance;

    public VisitExecutionService $execution;

    public TourInterruptService $interrupt;

    /** GET /api/mobile/my-tour?date=YYYY-MM-DD (default today) */
    public function myTour(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(fn (): array => $this->execution->myTour($this->caregiver(), Clock::parseDate($request->getVar('date'))));
    }

    /** POST /api/mobile/check-in */
    public function checkIn(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $caregiver = $this->caregiver();

            return $this->withState($caregiver, ['timeEntry' => $this->entryDto($this->attendance->checkIn($caregiver))]);
        });
    }

    /** POST /api/mobile/check-out */
    public function checkOut(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $caregiver = $this->caregiver();

            return $this->withState($caregiver, ['timeEntry' => $this->entryDto($this->attendance->checkOut($caregiver))]);
        });
    }

    /** POST /api/mobile/break  {action: "start"|"end"} */
    public function toggleBreak(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $caregiver = $this->caregiver();
            $entry = $this->attendance->toggleBreak($caregiver, $this->stringParam($this->body(), 'action', 10));

            return $this->withState($caregiver, ['timeEntry' => $this->entryDto($entry)]);
        });
    }

    /**
     * POST /api/mobile/visit/{id}/status
     * {action: "start"} or {action: "complete", tasks: [{id, status: "Done"|"NotDone", note?}], notes?}
     */
    public function visitStatus(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $caregiver = $this->caregiver();
            $body = $this->body();
            $visitID = $this->routeID($request);
            $action = $this->stringParam($body, 'action', 10);
            $visit = match ($action) {
                'start' => $this->execution->start($caregiver, $visitID),
                'complete' => $this->execution->complete(
                    $caregiver,
                    $visitID,
                    $this->taskUpdates($body),
                    $this->stringParam($body, 'notes', 5000)
                ),
                default => throw BadRequestException::because('action muss "start" oder "complete" sein.'),
            };

            return $this->withState($caregiver, [
                'visitId' => (int) $visit->ID,
                'status' => $visit->Status,
                'actualStart' => Clock::iso($visit->ActualStart),
                'actualEnd' => Clock::iso($visit->ActualEnd),
            ]);
        });
    }

    /** POST /api/mobile/task/{id}  {status: "Open"|"Done"|"NotDone", note?} – incremental save during a visit. */
    public function updateTask(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $body = $this->body();
            $task = $this->execution->updateTask(
                $this->caregiver(),
                $this->routeID($request),
                $this->stringParam($body, 'status', 10),
                $this->stringParam($body, 'note', 2000)
            );

            return ['task' => VisitPresenter::task($task, $task->CareTask())];
        });
    }

    /**
     * POST /api/mobile/sos  {reason, tour_id?, current_visit_outcome?: "completed"|"not_completed"}
     * Same split logic as the PDL board; the PDL sees the pooled visits immediately.
     */
    public function sos(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $caregiver = $this->caregiver();
            $body = $this->body();
            $tour = $this->execution->ownOpenTour($caregiver, $this->intParam($body, 'tour_id', false));
            $result = $this->interrupt->interrupt(
                (int) $tour->ID,
                $this->stringParam($body, 'reason'),
                $this->stringParam($body, 'current_visit_outcome', 20) ?: null,
                'mobile'
            );

            return $this->withState($caregiver, [
                'tourId' => (int) $result['tour']->ID,
                'pooledVisits' => count($result['pooledVisitIds']),
            ]);
        });
    }

    private function caregiver(): Caregiver
    {
        return Caregiver::forMember($this->currentMember())
            ?? throw AccessDeniedException::because('Deinem Benutzerkonto ist keine aktive Pflegekraft zugeordnet.');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<int, array{id:int, status:string, note:string}>
     */
    private function taskUpdates(array $body): array
    {
        $tasks = $body['tasks'] ?? [];
        if (!is_array($tasks)) {
            throw BadRequestException::because('tasks muss eine Liste sein.');
        }
        $out = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                throw BadRequestException::because('Ungültiger Eintrag in tasks.');
            }
            $out[] = [
                'id' => (int) $this->intParam($task, 'id'),
                'status' => $this->stringParam($task, 'status', 10),
                'note' => $this->stringParam($task, 'note', 2000),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function entryDto(TimeEntry $entry): array
    {
        return [
            'id' => (int) $entry->ID,
            'status' => (string) $entry->Status,
            'checkIn' => Clock::iso($entry->CheckIn),
            'checkOut' => Clock::iso($entry->CheckOut),
            'breakMinutes' => $entry->getBreakMinutes(),
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function withState(Caregiver $caregiver, array $result): array
    {
        return ['result' => $result, 'state' => $this->execution->myTour($caregiver, Clock::today())];
    }
}
