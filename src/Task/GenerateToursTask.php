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
use Throwable;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Service\TourGenerationService;

/**
 * Builds tours and visits from templates and care plans.
 *
 *   vendor/bin/sake tasks:care-dispatch-generate-tours                 # tomorrow
 *   vendor/bin/sake tasks:care-dispatch-generate-tours --date=2026-11-02 --days=7
 *
 * Idempotent – safe to run repeatedly (e.g. nightly cron at 18:00 for the next day).
 */
class GenerateToursTask extends BuildTask
{
    protected static string $commandName = 'care-dispatch-generate-tours';

    protected string $title = 'Pflege: Touren und Einsätze generieren';

    protected static string $description = 'Erzeugt Tagestouren aus Tourvorlagen und Einsätze aus den Pflegeplänen (Standard: morgen).';

    public function getOptions(): array
    {
        return [
            new InputOption('date', null, InputOption::VALUE_REQUIRED, 'Erster Tag (YYYY-MM-DD), Standard: morgen'),
            new InputOption('days', null, InputOption::VALUE_REQUIRED, 'Anzahl Tage ab --date', '1'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        try {
            $first = $input->getOption('date')
                ? Clock::parseDate((string) $input->getOption('date'))
                : Clock::now()->add(new DateInterval('P1D'))->format(Clock::DB_DATE);
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::INVALID;
        }
        $days = max(1, min(31, (int) $input->getOption('days')));
        $service = TourGenerationService::singleton();
        $date = new DateTimeImmutable($first);

        for ($i = 0; $i < $days; $i++) {
            $day = $date->add(new DateInterval('P' . $i . 'D'))->format(Clock::DB_DATE);
            try {
                $summary = $service->generate($day);
            } catch (Throwable $e) {
                $output->writeln(sprintf('<error>%s: %s</error>', $day, $e->getMessage()));

                return Command::FAILURE;
            }
            $output->writeln(sprintf(
                '%s: %d Touren neu, %d besetzt, %d Einsätze auf Touren, %d im Pool, %d Begleitungen offen, %d übersprungen',
                $day,
                $summary['toursCreated'],
                $summary['toursStaffed'],
                $summary['visitsOnTours'],
                $summary['visitsInPool'],
                $summary['companionsInPool'],
                $summary['skippedExisting']
            ));
            foreach ($summary['notes'] as $note) {
                $output->writeln('  <comment>' . $note . '</comment>');
            }
        }

        return Command::SUCCESS;
    }
}
