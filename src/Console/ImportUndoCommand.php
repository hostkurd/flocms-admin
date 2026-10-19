<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\ImportExport\{ImportException, Importer};
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\{Argument, Definition};

/**
 * php flo admin:import-undo <id> — take an import back (0.25): its new records
 * go to the trash, updated ones get their values from before the import.
 */
final class ImportUndoCommand extends Command
{
    use BootsPanel;

    protected string $name = 'admin:import-undo';

    protected string $description = 'Undo a CSV import';

    protected string $help = 'php flo admin:imports lists the imports and their numbers. An import can be undone for the configured days, unless its records were edited since.';

    protected function configure(Definition $definition): void
    {
        $definition->argument('id', Argument::REQUIRED, 'The import\'s number');
    }

    protected function handle(): int
    {
        $this->bootPanel();
        $store = $this->importStore();
        $run = ctype_digit((string) $this->argument('id')) ? $store->find((int) $this->argument('id')) : null;
        if ($run === null) { $this->fail('No import #' . $this->argument('id') . '.'); }
        $resource = $this->resourceFor($run->resource);
        $importer = (new Importer($resource, $store))->fromConsole();
        if (!$this->confirm('Undo import #' . $run->id . ' of ' . $run->fileName . ' (' . $run->count('created') . ' created, ' . $run->count('updated') . ' updated)?', false)) {
            return self::SUCCESS;
        }
        try {
            $run = $this->drive($importer, $importer->undo($run), 'Done.');
        } catch (ImportException $error) {
            $this->fail($error->getMessage());
        }
        $this->success($run->count('removed') . ' records moved to the trash, ' . $run->count('restored') . ' records restored.');
        if ($run->count('kept') > 0) { $this->warn($run->count('kept') . ' records kept as they are: edited during the Undo, still used by other records, or not allowed to be deleted.'); }
        return self::SUCCESS;
    }
}
