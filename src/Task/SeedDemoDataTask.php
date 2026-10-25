<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Task;

use SilverStripe\Control\Director;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use YouWillLikeIT\CareDispatch\Dev\DemoDataset;
use YouWillLikeIT\CareDispatch\Dev\DemoSeeder;
use YouWillLikeIT\CareDispatch\Domain\Clock;

/**
 * Fills an empty installation with a presentable demo (Pflegedienst in Berlin).
 *
 *   vendor/bin/sake tasks:care-dispatch-seed-demo                         # today, live state up to now
 *   vendor/bin/sake tasks:care-dispatch-seed-demo --progress-until=09:40  # fixed demo moment
 *   vendor/bin/sake tasks:care-dispatch-seed-demo --reset                 # wipe and seed again
 *
 * CLI only. Refuses to run in live mode unless --allow-live is given.
 */
class SeedDemoDataTask extends BuildTask
{
    protected static string $commandName = 'care-dispatch-seed-demo';

    protected string $title = 'Pflege: Demo-Daten erzeugen';

    protected static string $description = 'Erzeugt einen vollständigen Demo-Pflegedienst (Konten, Patienten, Pflegepläne, Touren, Tagesverlauf).';

    private static bool $can_run_in_browser = false;

    public function getOptions(): array
    {
        return [
            new InputOption('date', null, InputOption::VALUE_REQUIRED, 'Präsentationstag (YYYY-MM-DD), Standard: heute'),
            new InputOption('past-days', null, InputOption::VALUE_REQUIRED, 'Vollständig durchgeführte Vortage', '2'),
            new InputOption('future-days', null, InputOption::VALUE_REQUIRED, 'Geplante Folgetage', '2'),
            new InputOption(
                'progress-until',
                null,
                InputOption::VALUE_REQUIRED,
                'Tagesverlauf am Präsentationstag bis HH:MM abspielen; "now" (Standard, nur wenn Präsentationstag = heute), "none" oder "full"',
                'now'
            ),
            new InputOption('password', null, InputOption::VALUE_REQUIRED, 'Passwort aller Demo-Konten', 'Pflege-Demo-2026!'),
            new InputOption('seed', null, InputOption::VALUE_REQUIRED, 'Zufalls-Seed (reproduzierbare Daten)', '2026'),
            new InputOption('reset', null, InputOption::VALUE_NONE, 'ALLE Einsatzplanungs-Daten und Demo-Konten vorher löschen'),
            new InputOption('allow-live', null, InputOption::VALUE_NONE, 'Auch im Live-Modus ausführen'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (Director::isLive() && !$input->getOption('allow-live')) {
            $output->writeln('<error>Live-Umgebung erkannt. Demo-Daten nur mit --allow-live.</error>');

            return Command::FAILURE;
        }

        try {
            $date = Clock::parseDate($input->getOption('date') ? (string) $input->getOption('date') : null);
            $until = $this->progressUntil((string) $input->getOption('progress-until'), $date);
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::INVALID;
        }
        $past = max(0, min(14, (int) $input->getOption('past-days')));
        $future = max(0, min(14, (int) $input->getOption('future-days')));

        $seeder = new DemoSeeder(
            static function (string $line) use ($output): void {
                $output->writeln($line);
            },
            (int) $input->getOption('seed')
        );

        if (DemoSeeder::hasData()) {
            if (!$input->getOption('reset')) {
                $output->writeln('<error>Es sind bereits Einsatzplanungs-Daten vorhanden. Mit --reset werden ALLE gelöscht und neu erzeugt.</error>');

                return Command::FAILURE;
            }
            $seeder->reset();
        } elseif ($input->getOption('reset')) {
            $seeder->reset();
        }

        try {
            $result = $seeder->run($date, $past, $future, $until, (string) $input->getOption('password'));
        } catch (ValidationException $e) {
            $output->writeln('<error>Validierung fehlgeschlagen: ' . $e->getMessage() . '</error>');
            $output->writeln('Tipp: Das Passwort muss die Passwort-Richtlinie erfüllen (--password=...).');

            return Command::FAILURE;
        }

        $output->writeln(['', '<options=bold>Demo-Konten</> (Passwort: ' . $input->getOption('password') . ')']);
        foreach ($result['accounts'] as $account) {
            $output->writeln(sprintf('  %-22s %-46s %s', $account['name'], $account['email'], $account['role']));
        }
        $output->writeln([
            '',
            '<options=bold>Für die Präsentation</>',
            '  PDL-Board:   /pflege/board',
            '  Mobil:       /pflege/mobil  (als Pflegekraft anmelden, am besten im Handy-Browser)',
            '  Stammdaten:  /admin/einsatzplanung',
            sprintf(
                '  Routenoptimierung braucht den Firmenstandort in .env: CARE_DISPATCH_DEPOT_LAT="%s" CARE_DISPATCH_DEPOT_LNG="%s"',
                DemoDataset::DEPOT['lat'],
                DemoDataset::DEPOT['lng']
            ),
        ]);
        if ($result['warnings'] > 0) {
            $output->writeln(sprintf('<comment>%d Hinweis(e) beim Abspielen des Tagesverlaufs (siehe oben).</comment>', $result['warnings']));
        }

        return Command::SUCCESS;
    }

    /** @return int|null seconds of day, null = no progress */
    private function progressUntil(string $value, string $date): ?int
    {
        return match (strtolower(trim($value))) {
            'none' => null,
            'full' => 86399,
            'now', '' => $date === Clock::today() ? Clock::secondsOfDay(Clock::now()) : null,
            default => Clock::timeToSeconds(Clock::parseTime($value)),
        };
    }
}
