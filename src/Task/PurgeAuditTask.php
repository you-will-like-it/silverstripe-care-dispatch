<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Task;

use DateInterval;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\AuditEntry;

/**
 * Deletes audit entries older than the retention period (DSGVO Speicherbegrenzung).
 * Audit entries are immutable through the ORM; this task is the only deletion path.
 *
 *   vendor/bin/sake tasks:care-dispatch-purge-audit --dry-run
 *   vendor/bin/sake tasks:care-dispatch-purge-audit --years=10
 */
class PurgeAuditTask extends BuildTask
{
    protected static string $commandName = 'care-dispatch-purge-audit';

    protected string $title = 'Pflege: Änderungsprotokoll bereinigen';

    protected static string $description = 'Löscht Protokolleinträge, deren Aufbewahrungsfrist abgelaufen ist.';

    /** Default retention in years; align with your Pflegedokumentation retention policy. */
    private static int $retention_years = 10;

    /** Never run from the browser. */
    private static bool $can_run_in_browser = false;

    public function getOptions(): array
    {
        return [
            new InputOption('years', null, InputOption::VALUE_REQUIRED, 'Aufbewahrungsfrist in Jahren'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Nur zählen'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $years = (int) ($input->getOption('years') ?: static::config()->get('retention_years'));
        if ($years < 1) {
            $output->writeln('<error>--years muss mindestens 1 sein.</error>');

            return Command::INVALID;
        }
        $cutoff = Clock::now()->sub(new DateInterval('P' . $years . 'Y'))->format(Clock::DB_DATETIME);
        $table = DataObject::getSchema()->tableName(AuditEntry::class);
        $count = (int) SQLSelect::create('COUNT(*)', "\"$table\"", ['"Created" < ?' => $cutoff])->execute()->value();
        if ($input->getOption('dry-run')) {
            $output->writeln(sprintf('%d Einträge älter als %s würden gelöscht.', $count, $cutoff));

            return Command::SUCCESS;
        }
        SQLDelete::create("\"$table\"", ['"Created" < ?' => $cutoff])->execute();
        $output->writeln(sprintf('%d Einträge älter als %s gelöscht.', $count, $cutoff));

        return Command::SUCCESS;
    }
}
