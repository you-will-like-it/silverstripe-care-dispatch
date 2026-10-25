<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Task;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use YouWillLikeIT\CareDispatch\Model\Patient;

/**
 * (Re-)geocodes patient addresses, e.g. after an import or when switching geocoders.
 *
 *   vendor/bin/sake tasks:care-dispatch-geocode           # only patients without coordinates
 *   vendor/bin/sake tasks:care-dispatch-geocode --all
 */
class GeocodePatientsTask extends BuildTask
{
    protected static string $commandName = 'care-dispatch-geocode';

    protected string $title = 'Pflege: Patientenadressen geokodieren';

    protected static string $description = 'Ermittelt Koordinaten für Patientenadressen (Voraussetzung für die Routenoptimierung).';

    public function getOptions(): array
    {
        return [
            new InputOption('all', null, InputOption::VALUE_NONE, 'Alle aktiven Patienten neu geokodieren'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (Patient::geocoder() === null) {
            $output->writeln('<error>Kein Geocoder konfiguriert (GeocoderInterface).</error>');

            return Command::FAILURE;
        }
        $patients = Patient::get()->filter('IsActive', true);
        if (!$input->getOption('all')) {
            $patients = $patients->filter(['Lat' => 0, 'Lng' => 0]);
        }
        $ok = 0;
        $failed = 0;
        foreach ($patients as $patient) {
            if ($patient->geocode()) {
                // Coordinates only; address unchanged, so onBeforeWrite will not geocode again.
                $patient->write();
                $ok++;
            } else {
                $failed++;
                $output->writeln(sprintf('<comment>Nicht gefunden: #%d %s</comment>', $patient->ID, $patient->getAddressLine()));
            }
        }
        $output->writeln(sprintf('%d geokodiert, %d nicht gefunden.', $ok, $failed));

        return $failed > 0 && $ok === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
