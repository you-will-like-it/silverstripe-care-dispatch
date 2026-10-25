<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api\Admin;

use DateInterval;
use DateTimeImmutable;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions as P;
use YouWillLikeIT\CareDispatch\Service\AuditLogger;
use YouWillLikeIT\CareDispatch\Service\Transaction;

/**
 * /api/admin/time – Zeitbuchungen einsehen und korrigieren (ROSTER). Every correction needs a reason.
 */
class TimeApiController extends AdminApiController
{
    private static array $url_handlers = [
        'GET list' => 'listEntries',
        'POST create' => 'createEntry',
        'POST $ID!' => 'updateEntry',
    ];

    private static array $allowed_actions = ['listEntries', 'createEntry', 'updateEntry'];

    private static array $dependencies = [
        'audit' => '%$' . AuditLogger::class,
    ];

    public AuditLogger $audit;

    /** GET list?from=&to=&caregiver=&autoClosed=1 */
    public function listEntries(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::ROSTER);
            $to = Clock::parseDate($request->getVar('to') ?: null);
            $from = $request->getVar('from')
                ? Clock::parseDate($request->getVar('from'))
                : (new DateTimeImmutable($to))->sub(new DateInterval('P6D'))->format(Clock::DB_DATE);
            if ($from > $to) {
                throw BadRequestException::because('„Von“ liegt nach „Bis“.');
            }
            $list = TimeEntry::get()->filter([
                'CheckIn:GreaterThanOrEqual' => $from . ' 00:00:00',
                'CheckIn:LessThanOrEqual' => $to . ' 23:59:59',
            ])->sort('CheckIn', 'DESC');
            if ((int) $request->getVar('caregiver') > 0) {
                $list = $list->filter('CaregiverID', (int) $request->getVar('caregiver'));
            }
            if ($request->getVar('autoClosed')) {
                $list = $list->filter('AutoClosed', true);
            }

            return [
                'from' => $from,
                'to' => $to,
                'entries' => array_map(fn (TimeEntry $e): array => $this->present($e), $list->limit(2000)->toArray()),
            ];
        });
    }

    /** POST create {caregiverId, checkIn: "YYYY-MM-DD HH:MM", checkOut, breakMinutes, reason} */
    public function createEntry(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::ROSTER);
            $body = $this->body();
            $reason = $this->reason($body);
            $caregiverID = (int) $this->intParam($body, 'caregiverId');
            $caregiver = Caregiver::get()->byID($caregiverID) ?? throw NotFoundException::for('Pflegekraft', $caregiverID);
            $entry = Transaction::run(function () use ($caregiver, $body, $reason): TimeEntry {
                $entry = TimeEntry::create();
                $entry->CaregiverID = (int) $caregiver->ID;
                $this->apply($entry, $body);
                $entry->write();
                $this->audit->event($entry, 'time_entry_created_manually', ['caregiverId' => (int) $caregiver->ID], $reason);

                return $entry;
            });

            return ['entry' => $this->present($entry)];
        }, 201);
    }

    public function updateEntry(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::ROSTER);
            $id = $this->routeID($request);
            $entry = TimeEntry::get()->byID($id) ?? throw NotFoundException::for('Zeitbuchung', $id);
            $body = $this->body();
            $reason = $this->reason($body);
            Transaction::run(function () use ($entry, $body, $reason): void {
                $before = ['checkIn' => $entry->CheckIn, 'checkOut' => $entry->CheckOut, 'breakSeconds' => (int) $entry->BreakSeconds];
                $this->apply($entry, $body);
                $entry->AutoClosed = false;
                $entry->write();
                $this->audit->event($entry, 'time_entry_corrected', [
                    'before' => $before,
                    'after' => ['checkIn' => $entry->CheckIn, 'checkOut' => $entry->CheckOut, 'breakSeconds' => (int) $entry->BreakSeconds],
                ], $reason);
            });

            return ['entry' => $this->present($entry)];
        });
    }

    /** @param array<string, mixed> $body */
    private function apply(TimeEntry $entry, array $body): void
    {
        $checkIn = $this->datetime($body, 'checkIn', true);
        $checkOut = $this->datetime($body, 'checkOut', false);
        $entry->CheckIn = $checkIn;
        $entry->CheckOut = $checkOut;
        $entry->BreakSeconds = max(0, (int) ($body['breakMinutes'] ?? 0)) * 60;
        $entry->BreakStartedAt = null;
        $entry->Status = $checkOut !== null ? TimeEntry::STATUS_COMPLETED : TimeEntry::STATUS_ACTIVE;
        if ($checkOut !== null) {
            $minutes = intdiv(strtotime($checkOut) - strtotime($checkIn), 60);
            if ($minutes > 16 * 60) {
                throw BadRequestException::because('Eine Buchung darf höchstens 16 Stunden lang sein.');
            }
            if ((int) ($body['breakMinutes'] ?? 0) >= $minutes) {
                throw BadRequestException::because('Die Pause ist länger als die Anwesenheit.');
            }
        }
    }

    /** @param array<string, mixed> $body */
    private function datetime(array $body, string $key, bool $required): ?string
    {
        $value = trim((string) ($body[$key] ?? ''));
        if ($value === '') {
            if ($required) {
                throw BadRequestException::because(sprintf('%s fehlt.', $key === 'checkIn' ? 'Kommen' : 'Gehen'));
            }

            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr(str_replace('T', ' ', $value), 0, 16));
        if ($dt === false) {
            throw BadRequestException::because(sprintf('Ungültiger Zeitpunkt "%s" (erwartet YYYY-MM-DD HH:MM).', $value));
        }

        return $dt->format(Clock::DB_DATETIME);
    }

    /** @param array<string, mixed> $body */
    private function reason(array $body): string
    {
        $reason = $this->stringParam($body, 'reason', 500);
        if (mb_strlen($reason) < 3) {
            throw BadRequestException::because('Für Korrekturen ist eine Begründung Pflicht.');
        }

        return $reason;
    }

    /** @return array<string, mixed> */
    private function present(TimeEntry $entry): array
    {
        $minutes = $entry->CheckOut ? intdiv(strtotime((string) $entry->CheckOut) - strtotime((string) $entry->CheckIn), 60) : null;

        return [
            'id' => (int) $entry->ID,
            'caregiverId' => (int) $entry->CaregiverID,
            'caregiverName' => $entry->Caregiver()->getTitle(),
            'checkIn' => Clock::iso($entry->CheckIn),
            'checkOut' => Clock::iso($entry->CheckOut),
            'status' => (string) $entry->Status,
            'breakMinutes' => $entry->getBreakMinutes(),
            'workMinutes' => $minutes !== null ? max(0, $minutes - $entry->getBreakMinutes()) : null,
            'autoClosed' => (bool) $entry->AutoClosed,
        ];
    }
}
