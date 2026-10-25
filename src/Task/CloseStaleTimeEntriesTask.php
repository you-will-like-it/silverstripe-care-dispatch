<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Task;

use DateInterval;
use DateTimeImmutable;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;
use YouWillLikeIT\CareDispatch\Service\AuditLogger;

/**
 * Closes time bookings that were never checked out.
 *
 * CheckOut is set to the caregiver's last documented activity (end of the last visit),
 * otherwise CheckIn + max-hours. Entries are flagged AutoClosed for HR review.
 *
 *   vendor/bin/sake tasks:care-dispatch-close-stale-entries --max-hours=14
 */
class CloseStaleTimeEntriesTask extends BuildTask
{
    protected static string $commandName = 'care-dispatch-close-stale-entries';

    protected string $title = 'Pflege: Vergessene Zeitbuchungen schließen';

    protected static string $description = 'Schließt offene Zeitbuchungen, die älter als --max-hours sind, und markiert sie zur Prüfung.';

    public function getOptions(): array
    {
        return [
            new InputOption('max-hours', null, InputOption::VALUE_REQUIRED, 'Maximale Dauer einer offenen Buchung in Stunden', '14'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, nichts ändern'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $maxHours = max(1, min(48, (int) $input->getOption('max-hours')));
        $dryRun = (bool) $input->getOption('dry-run');
        $threshold = Clock::now()->sub(new DateInterval('PT' . $maxHours . 'H'))->format(Clock::DB_DATETIME);
        $entries = TimeEntry::get()->filter([
            'Status:not' => TimeEntry::STATUS_COMPLETED,
            'CheckIn:LessThan' => $threshold,
        ]);
        $closed = 0;
        foreach ($entries as $entry) {
            $checkIn = new DateTimeImmutable($entry->CheckIn);
            $limit = $checkIn->add(new DateInterval('PT' . $maxHours . 'H'));
            $lastActivity = $this->lastActivity($entry);
            $checkOut = $lastActivity !== null && $lastActivity > $checkIn && $lastActivity < $limit ? $lastActivity : $limit;

            $output->writeln(sprintf(
                '%s: Kommen %s → Gehen %s%s',
                $entry->Caregiver()->getTitle(),
                $checkIn->format('d.m. H:i'),
                $checkOut->format('d.m. H:i'),
                $dryRun ? ' (dry-run)' : ''
            ));
            if ($dryRun) {
                continue;
            }
            if ($entry->Status === TimeEntry::STATUS_BREAK && $entry->BreakStartedAt) {
                $entry->BreakSeconds = (int) $entry->BreakSeconds
                    + max(0, $checkOut->getTimestamp() - (new DateTimeImmutable($entry->BreakStartedAt))->getTimestamp());
                $entry->BreakStartedAt = null;
            }
            $entry->CheckOut = $checkOut->format(Clock::DB_DATETIME);
            $entry->Status = TimeEntry::STATUS_COMPLETED;
            $entry->AutoClosed = true;
            $entry->write();
            AuditLogger::singleton()->event($entry, 'time_entry_auto_closed', ['maxHours' => $maxHours], 'Automatisch geschlossen (Gehen nicht gebucht)');
            $closed++;
        }
        $output->writeln(sprintf('%d Buchung(en) %s.', $dryRun ? $entries->count() : $closed, $dryRun ? 'gefunden' : 'geschlossen'));

        return Command::SUCCESS;
    }

    private function lastActivity(TimeEntry $entry): ?DateTimeImmutable
    {
        $tourIDs = DailyTour::get()->filter('CaregiverID', $entry->CaregiverID)->column('ID');
        if ($tourIDs === []) {
            return null;
        }
        $last = PatientVisit::get()
            ->filter([
                'DailyTourID' => $tourIDs,
                'ActualEnd:GreaterThan' => $entry->CheckIn,
            ])
            ->sort('ActualEnd', 'DESC')
            ->first();

        return $last !== null ? new DateTimeImmutable($last->ActualEnd) : null;
    }
}
