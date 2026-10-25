<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;

/**
 * Kommen / Pause / Gehen of field staff (Ist-Anwesenheit).
 */
class AttendanceService
{
    use Injectable;

    private static array $dependencies = [
        'audit' => '%$' . AuditLogger::class,
    ];

    public AuditLogger $audit;

    public function checkIn(Caregiver $caregiver): TimeEntry
    {
        return Transaction::run(function () use ($caregiver): TimeEntry {
            Transaction::lock(Caregiver::class, [(int) $caregiver->ID]);
            if (TimeEntry::openFor((int) $caregiver->ID) !== null) {
                throw new DomainRuleException('ALREADY_CHECKED_IN', 'Du bist bereits eingestempelt.');
            }
            $entry = TimeEntry::create();
            $entry->CaregiverID = (int) $caregiver->ID;
            $entry->CheckIn = Clock::nowDb();
            $entry->Status = TimeEntry::STATUS_ACTIVE;
            $entry->write();
            $this->audit->event($entry, 'check_in', ['caregiverId' => (int) $caregiver->ID]);

            return $entry;
        });
    }

    public function checkOut(Caregiver $caregiver): TimeEntry
    {
        return Transaction::run(function () use ($caregiver): TimeEntry {
            Transaction::lock(Caregiver::class, [(int) $caregiver->ID]);
            $entry = $this->requireOpen($caregiver);
            $running = $this->runningVisit($caregiver);
            if ($running !== null) {
                throw new DomainRuleException(
                    'VISIT_RUNNING',
                    sprintf('Einsatz bei %s läuft noch. Bitte zuerst abschließen oder SOS melden.', $running->Patient()->getTitle())
                );
            }
            $now = Clock::nowDb();
            $this->closeBreak($entry, $now);
            $entry->CheckOut = $now;
            $entry->Status = TimeEntry::STATUS_COMPLETED;
            $entry->write();
            $this->audit->event($entry, 'check_out', ['caregiverId' => (int) $caregiver->ID, 'breakMinutes' => $entry->getBreakMinutes()]);

            return $entry;
        });
    }

    public function toggleBreak(Caregiver $caregiver, string $action): TimeEntry
    {
        if (!in_array($action, ['start', 'end'], true)) {
            throw BadRequestException::because('action muss "start" oder "end" sein.');
        }

        return Transaction::run(function () use ($caregiver, $action): TimeEntry {
            Transaction::lock(Caregiver::class, [(int) $caregiver->ID]);
            $entry = $this->requireOpen($caregiver);
            $now = Clock::nowDb();
            if ($action === 'start') {
                if ($entry->Status === TimeEntry::STATUS_BREAK) {
                    throw new DomainRuleException('ALREADY_ON_BREAK', 'Pause läuft bereits.');
                }
                $running = $this->runningVisit($caregiver);
                if ($running !== null) {
                    throw new DomainRuleException('VISIT_RUNNING', 'Während eines laufenden Einsatzes ist keine Pause möglich.');
                }
                $entry->Status = TimeEntry::STATUS_BREAK;
                $entry->BreakStartedAt = $now;
            } else {
                if ($entry->Status !== TimeEntry::STATUS_BREAK) {
                    throw new DomainRuleException('NOT_ON_BREAK', 'Es läuft keine Pause.');
                }
                $this->closeBreak($entry, $now);
                $entry->Status = TimeEntry::STATUS_ACTIVE;
            }
            $entry->write();
            $this->audit->event($entry, 'break_' . $action, ['caregiverId' => (int) $caregiver->ID]);

            return $entry;
        });
    }

    public function requireOpen(Caregiver $caregiver): TimeEntry
    {
        return TimeEntry::openFor((int) $caregiver->ID)
            ?? throw new DomainRuleException('NOT_CHECKED_IN', 'Du bist nicht eingestempelt.');
    }

    public function runningVisit(Caregiver $caregiver): ?PatientVisit
    {
        $tourIDs = DailyTour::get()->filter('CaregiverID', $caregiver->ID)->filter('Status', DailyTour::OPEN_STATUSES)->column('ID');
        if ($tourIDs === []) {
            return null;
        }

        return PatientVisit::get()->filter(['DailyTourID' => $tourIDs, 'Status' => PatientVisit::STATUS_IN_PROGRESS])->first();
    }

    private function closeBreak(TimeEntry $entry, string $now): void
    {
        if ($entry->Status !== TimeEntry::STATUS_BREAK || !$entry->BreakStartedAt) {
            return;
        }
        $seconds = (new DateTimeImmutable($now))->getTimestamp() - (new DateTimeImmutable($entry->BreakStartedAt))->getTimestamp();
        $entry->BreakSeconds = (int) $entry->BreakSeconds + max(0, $seconds);
        $entry->BreakStartedAt = null;
    }
}
