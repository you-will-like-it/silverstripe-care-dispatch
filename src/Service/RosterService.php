<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use DateInterval;
use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\Absence;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\Shift;

/**
 * Dienstplan: shifts, absences, copying weeks.
 */
class RosterService
{
    use Injectable;

    private static array $dependencies = [
        'audit' => '%$' . AuditLogger::class,
    ];

    public AuditLogger $audit;

    /**
     * @param array{caregiverId:int, date:string, type:string, start:string, end:string} $data
     */
    public function saveShift(?int $shiftID, array $data): Shift
    {
        return Transaction::run(function () use ($shiftID, $data): Shift {
            $shift = $shiftID ? (Shift::get()->byID($shiftID) ?? throw NotFoundException::for('Dienst', $shiftID)) : Shift::create();
            $caregiver = Caregiver::get()->byID($data['caregiverId']) ?? throw NotFoundException::for('Pflegekraft', $data['caregiverId']);
            $date = Clock::parseDate($data['date']);
            if (!array_key_exists($data['type'], Shift::TYPE_LABELS)) {
                throw BadRequestException::because('Unbekannte Dienstart.');
            }
            $absence = Absence::on((int) $caregiver->ID, $date);
            if ($absence !== null) {
                throw new DomainRuleException('CAREGIVER_ABSENT', sprintf('%s ist an diesem Tag abwesend (%s).', $caregiver->getTitle(), $absence->getTypeLabel()));
            }
            $shift->CaregiverID = (int) $caregiver->ID;
            $shift->ShiftDate = $date;
            $shift->ShiftType = $data['type'];
            $shift->StartTime = Clock::parseTime($data['start']);
            $shift->EndTime = Clock::parseTime($data['end']);
            $shift->Status = Shift::STATUS_SCHEDULED;
            $shift->write();

            return $shift;
        });
    }

    /** Future shifts are deleted, past/current ones only cancelled (documentation). */
    public function removeShift(int $shiftID): void
    {
        Transaction::run(function () use ($shiftID): void {
            $shift = Shift::get()->byID($shiftID) ?? throw NotFoundException::for('Dienst', $shiftID);
            if ((string) $shift->ShiftDate > Clock::today()) {
                $shift->delete();

                return;
            }
            $shift->Status = Shift::STATUS_CANCELLED;
            $shift->write();
        });
    }

    /**
     * Copies all scheduled shifts of the week starting $fromMonday to the week starting $toMonday.
     * Skips caregivers that are absent or already have an overlapping shift on the target day.
     *
     * @return array{created:int, skipped:int}
     */
    public function copyWeek(string $fromMonday, string $toMonday): array
    {
        $from = new DateTimeImmutable(Clock::parseDate($fromMonday));
        $to = new DateTimeImmutable(Clock::parseDate($toMonday));
        if ($from->format('N') !== '1' || $to->format('N') !== '1') {
            throw BadRequestException::because('Wochen müssen an einem Montag beginnen.');
        }
        if ($from == $to) {
            throw BadRequestException::because('Quell- und Zielwoche sind identisch.');
        }
        if ($to->format(Clock::DB_DATE) < (new DateTimeImmutable(Clock::today()))->modify('monday this week')->format(Clock::DB_DATE)) {
            throw new DomainRuleException('PAST_WEEK', 'In vergangene Wochen kann nicht kopiert werden.');
        }

        return Transaction::run(function () use ($from, $to): array {
            $created = 0;
            $skipped = 0;
            $shifts = Shift::get()->filter([
                'ShiftDate:GreaterThanOrEqual' => $from->format(Clock::DB_DATE),
                'ShiftDate:LessThanOrEqual' => $from->add(new DateInterval('P6D'))->format(Clock::DB_DATE),
                'Status' => Shift::STATUS_SCHEDULED,
                'Caregiver.IsActive' => true,
            ]);
            foreach ($shifts as $shift) {
                $offset = (int) (new DateTimeImmutable((string) $shift->ShiftDate))->diff($from)->days;
                $target = $to->add(new DateInterval('P' . $offset . 'D'))->format(Clock::DB_DATE);
                if (Absence::on((int) $shift->CaregiverID, $target) !== null) {
                    $skipped++;
                    continue;
                }
                $copy = Shift::create();
                $copy->CaregiverID = (int) $shift->CaregiverID;
                $copy->ShiftDate = $target;
                $copy->ShiftType = $shift->ShiftType;
                $copy->StartTime = $shift->StartTime;
                $copy->EndTime = $shift->EndTime;
                $copy->Status = Shift::STATUS_SCHEDULED;
                if (!$copy->validate()->isValid()) {
                    $skipped++;
                    continue;
                }
                $copy->write();
                $created++;
            }
            $this->audit->systemEvent(self::class, 'roster_week_copied', [
                'from' => $from->format(Clock::DB_DATE),
                'to' => $to->format(Clock::DB_DATE),
                'created' => $created,
                'skipped' => $skipped,
            ]);

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * Creates or updates an absence. Consequences for the period:
     * - scheduled shifts are cancelled
     * - future tours of the caregiver that have not started lose their caregiver (become unstaffed)
     * - tours of today that are already running are reported (use SOS)
     *
     * @param array{caregiverId:int, type:string, from:string, to:string, note?:string} $data
     * @return array{absence: Absence, cancelledShifts:int, unstaffedTours: string[], runningTours: string[]}
     */
    public function saveAbsence(?int $absenceID, array $data): array
    {
        return Transaction::run(function () use ($absenceID, $data): array {
            $absence = $absenceID ? (Absence::get()->byID($absenceID) ?? throw NotFoundException::for('Abwesenheit', $absenceID)) : Absence::create();
            $caregiver = Caregiver::get()->byID($data['caregiverId']) ?? throw NotFoundException::for('Pflegekraft', $data['caregiverId']);
            if (!array_key_exists($data['type'], Absence::TYPE_LABELS)) {
                throw BadRequestException::because('Unbekannte Abwesenheitsart.');
            }
            $absence->CaregiverID = (int) $caregiver->ID;
            $absence->Type = $data['type'];
            $absence->DateFrom = Clock::parseDate($data['from']);
            $absence->DateTo = Clock::parseDate($data['to']);
            $absence->Note = trim((string) ($data['note'] ?? ''));
            $absence->write();

            $cancelled = 0;
            foreach (Shift::get()->filter([
                'CaregiverID' => $caregiver->ID,
                'Status' => Shift::STATUS_SCHEDULED,
                'ShiftDate:GreaterThanOrEqual' => $absence->DateFrom,
                'ShiftDate:LessThanOrEqual' => $absence->DateTo,
            ]) as $shift) {
                $shift->Status = Shift::STATUS_CANCELLED;
                $shift->write();
                $cancelled++;
            }

            $unstaffed = [];
            $running = [];
            $today = Clock::today();
            $tours = DailyTour::get()->filter([
                'CaregiverID' => $caregiver->ID,
                'Status' => DailyTour::OPEN_STATUSES,
                'TourDate:GreaterThanOrEqual' => max($today, (string) $absence->DateFrom),
                'TourDate:LessThanOrEqual' => $absence->DateTo,
            ]);
            foreach ($tours as $tour) {
                $started = $tour->Visits()->filter('Status', [PatientVisit::STATUS_IN_PROGRESS, PatientVisit::STATUS_COMPLETED])->exists();
                $label = sprintf('%s (%s)', $tour->Title, date('d.m.', strtotime((string) $tour->TourDate)));
                if ($started) {
                    $running[] = $label;
                    continue;
                }
                $tour->CaregiverID = 0;
                $tour->refreshStatus();
                $tour->write();
                $unstaffed[] = $label;
            }

            $this->audit->event($absence, 'absence_saved', [
                'caregiverId' => (int) $caregiver->ID,
                'cancelledShifts' => $cancelled,
                'unstaffedTours' => $unstaffed,
                'runningTours' => $running,
            ]);

            return ['absence' => $absence, 'cancelledShifts' => $cancelled, 'unstaffedTours' => $unstaffed, 'runningTours' => $running];
        });
    }

    public function removeAbsence(int $absenceID): void
    {
        Transaction::run(function () use ($absenceID): void {
            $absence = Absence::get()->byID($absenceID) ?? throw NotFoundException::for('Abwesenheit', $absenceID);
            $absence->delete();
        });
    }
}
