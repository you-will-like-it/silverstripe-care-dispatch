<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api\Admin;

use DateInterval;
use DateTimeImmutable;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\Absence;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\Shift;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions as P;
use YouWillLikeIT\CareDispatch\Service\RosterService;

/**
 * /api/admin/roster – Dienstplan und Abwesenheiten (ROSTER; read also DISPATCH).
 */
class RosterApiController extends AdminApiController
{
    private static array $url_handlers = [
        'GET week' => 'week',
        'POST copy-week' => 'copyWeek',
        'POST shift/$ID!/delete' => 'deleteShift',
        'POST shift/$ID!' => 'updateShift',
        'POST shift' => 'createShift',
        'POST absence/$ID!/delete' => 'deleteAbsence',
        'POST absence/$ID!' => 'updateAbsence',
        'POST absence' => 'createAbsence',
    ];

    private static array $allowed_actions = ['week', 'copyWeek', 'deleteShift', 'updateShift', 'createShift', 'deleteAbsence', 'updateAbsence', 'createAbsence'];

    private static array $dependencies = [
        'roster' => '%$' . RosterService::class,
    ];

    public RosterService $roster;

    /** GET week?start=YYYY-MM-DD (any day; normalised to Monday) */
    public function week(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::ROSTER, P::DISPATCH);

            return $this->weekPayload($this->monday($request->getVar('start')));
        });
    }

    public function createShift(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(fn (): array => $this->saveShift(null), 201);
    }

    public function updateShift(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(fn (): array => $this->saveShift($this->routeID($request)));
    }

    public function deleteShift(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::ROSTER);
            $shift = Shift::get()->byID($this->routeID($request));
            $date = $shift !== null ? (string) $shift->ShiftDate : Clock::today();
            $this->roster->removeShift($this->routeID($request));

            return $this->weekPayload($this->monday($date));
        });
    }

    /** POST copy-week {from: Monday, to: Monday} */
    public function copyWeek(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::ROSTER);
            $body = $this->body();
            $to = (string) $this->dateParam($body, 'to');
            $result = $this->roster->copyWeek((string) $this->dateParam($body, 'from'), $to);

            return ['result' => $result] + $this->weekPayload($to);
        });
    }

    public function createAbsence(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(fn (): array => $this->saveAbsence(null), 201);
    }

    public function updateAbsence(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(fn (): array => $this->saveAbsence($this->routeID($request)));
    }

    public function deleteAbsence(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::ROSTER);
            $absence = Absence::get()->byID($this->routeID($request));
            $date = $absence !== null ? (string) $absence->DateFrom : Clock::today();
            $this->roster->removeAbsence($this->routeID($request));

            return $this->weekPayload($this->monday($date));
        });
    }

    /** @return array<string, mixed> */
    private function saveShift(?int $id): array
    {
        $this->requirePermission(P::ROSTER);
        $body = $this->body();
        $shift = $this->roster->saveShift($id, [
            'caregiverId' => (int) $this->intParam($body, 'caregiverId'),
            'date' => (string) $this->dateParam($body, 'date'),
            'type' => $this->stringParam($body, 'type', 20),
            'start' => $this->stringParam($body, 'start', 8),
            'end' => $this->stringParam($body, 'end', 8),
        ]);

        return $this->weekPayload($this->monday((string) $shift->ShiftDate));
    }

    /** @return array<string, mixed> */
    private function saveAbsence(?int $id): array
    {
        $this->requirePermission(P::ROSTER);
        $body = $this->body();
        $result = $this->roster->saveAbsence($id, [
            'caregiverId' => (int) $this->intParam($body, 'caregiverId'),
            'type' => $this->stringParam($body, 'type', 20),
            'from' => (string) $this->dateParam($body, 'from'),
            'to' => (string) $this->dateParam($body, 'to'),
            'note' => $this->stringParam($body, 'note', 255),
        ]);

        return [
            'result' => [
                'absenceId' => (int) $result['absence']->ID,
                'cancelledShifts' => $result['cancelledShifts'],
                'unstaffedTours' => $result['unstaffedTours'],
                'runningTours' => $result['runningTours'],
            ],
        ] + $this->weekPayload($this->monday((string) $result['absence']->DateFrom));
    }

    private function monday(mixed $date): string
    {
        $day = new DateTimeImmutable(Clock::parseDate(is_string($date) && $date !== '' ? $date : null));

        return $day->modify('monday this week')->format(Clock::DB_DATE);
    }

    /** @return array<string, mixed> */
    private function weekPayload(string $monday): array
    {
        $start = new DateTimeImmutable($monday);
        $end = $start->add(new DateInterval('P6D'))->format(Clock::DB_DATE);
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $start->add(new DateInterval('P' . $i . 'D'))->format(Clock::DB_DATE);
        }
        $caregivers = Caregiver::get()->filter('IsActive', true)->toArray();
        usort($caregivers, static fn (Caregiver $a, Caregiver $b): int => [$b->getQualification()->rank(), $a->getTitle()] <=> [$a->getQualification()->rank(), $b->getTitle()]);

        $shifts = Shift::get()->filter([
            'ShiftDate:GreaterThanOrEqual' => $monday,
            'ShiftDate:LessThanOrEqual' => $end,
            'Status' => Shift::STATUS_SCHEDULED,
        ])->sort(['ShiftDate' => 'ASC', 'StartTime' => 'ASC']);
        $absences = Absence::get()->filter([
            'DateFrom:LessThanOrEqual' => $end,
            'DateTo:GreaterThanOrEqual' => $monday,
        ]);

        return [
            'week' => [
                'start' => $monday,
                'end' => $end,
                'days' => $days,
                'isoWeek' => (int) $start->format('W'),
                'canEdit' => P::check(P::ROSTER),
            ],
            'caregivers' => array_map(static fn (Caregiver $c): array => [
                'id' => (int) $c->ID,
                'name' => $c->getTitle(),
                'qualification' => (string) $c->QualificationLevel,
                'qualificationLabel' => $c->getQualificationLabel(),
                'weeklyHours' => (float) $c->ContractWeeklyHours,
                'color' => $c->BoardColor ?: null,
            ], $caregivers),
            'shifts' => array_map(static function (Shift $s): array {
                [$from, $to] = $s->window();

                return [
                    'id' => (int) $s->ID,
                    'caregiverId' => (int) $s->CaregiverID,
                    'date' => (string) $s->ShiftDate,
                    'type' => (string) $s->ShiftType,
                    'typeLabel' => $s->getShiftTypeLabel(),
                    'start' => Clock::short($s->StartTime),
                    'end' => Clock::short($s->EndTime),
                    'minutes' => intdiv($to - $from, 60),
                ];
            }, $shifts->toArray()),
            'absences' => array_map(static fn (Absence $a): array => [
                'id' => (int) $a->ID,
                'caregiverId' => (int) $a->CaregiverID,
                'type' => (string) $a->Type,
                'typeLabel' => $a->getTypeLabel(),
                'from' => (string) $a->DateFrom,
                'to' => (string) $a->DateTo,
                'note' => (string) $a->Note,
            ], $absences->toArray()),
            'shiftTypes' => Shift::TYPE_LABELS,
            'absenceTypes' => Absence::TYPE_LABELS,
        ];
    }
}
