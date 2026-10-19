<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\ImportExport\{ImportException, Importer, ImportStore};
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\{Argument, Definition, Option};

/**
 * php flo admin:import <resource> <file> — import a CSV file (0.25) with the
 * same checks as the Import page. Columns are matched by their headings; the
 * activity log records it as done by "System".
 */
final class ImportCommand extends Command
{
    use BootsPanel;

    protected string $name = 'admin:import';

    protected string $description = 'Import a CSV file into a resource';

    protected string $help = 'php flo admin:import listings listings.csv [--update] [--update-only] [--key=reference] [--dates=mdy] [--dry-run]. Columns are matched by their headings (field names such as title.en, or labels). --dry-run checks every row and saves nothing. php flo admin:import-undo <id> takes an import back.';

    protected function configure(Definition $definition): void
    {
        $definition->argument('resource', Argument::REQUIRED, 'The resource\'s slug, e.g. listings');
        $definition->argument('file', Argument::REQUIRED, 'The CSV file');
        $definition->option('update', null, Option::VALUE_NONE, 'Also update records that exist (found by id or --key)');
        $definition->option('update-only', null, Option::VALUE_NONE, 'Only update records that exist; skip the others');
        $definition->option('key', null, Option::VALUE_REQUIRED, 'The column that finds existing records (id, or the resource\'s import key)');
        $definition->option('dates', null, Option::VALUE_REQUIRED, 'dmy (default, 05/10 is 5 October) or mdy');
        $definition->option('dry-run', null, Option::VALUE_NONE, 'Check every row, save nothing');
    }

    protected function handle(): int
    {
        $this->bootPanel();
        $resource = $this->resourceFor($this->argument('resource'));
        if (!$resource::isImportable()) { $this->fail('Imports into ' . $resource::getSlug() . ' are off ($importable = false).'); }
        $store = $this->importStore();
        $file = (string) $this->argument('file');
        if (!is_file($file) || !is_readable($file)) { $this->fail('Cannot read ' . $file . '.'); }
        $max = \FloCMS\Admin\Admin::config()->import()['max_bytes'];
        if (filesize($file) > $max) { $this->fail('The file is larger than the import limit (' . round($max / 1048576, 1) . ' MB).'); }
        $folder = ImportStore::folder();
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) { $this->fail('Cannot create ' . $folder . '.'); }
        $stored = bin2hex(random_bytes(16)) . '.csv';
        if (!copy($file, $folder . '/' . $stored)) { $this->fail('Cannot copy the file to ' . $folder . '.'); }
        $importer = (new Importer($resource, $store))->fromConsole();
        try {
            $run = $importer->start($stored, basename($file), (int) filesize($file), null, 'System');
        } catch (ImportException $error) {
            @unlink($folder . '/' . $stored);
            @unlink($folder . '/' . $stored . '.utf8');
            $this->fail($error->getMessage());
        }
        $targets = $importer->targets();
        $this->line('Import #' . $run->id . ': ' . $run->rowsTotal . ' rows, ' . count($run->headings) . ' columns.');
        foreach ($run->headings as $index => $heading) {
            $this->line('  ' . $heading . '  →  ' . (isset($run->mapping[$index]) ? $targets[$run->mapping[$index]]['label'] : '(skipped)'));
        }
        $mode = $this->option('update-only') ? 'update' : ($this->option('update') ? 'both' : 'create');
        $key = is_string($this->option('key')) ? $this->option('key') : ($run->keyColumn ?? '');
        $errors = $importer->saveMatch($run, $run->mapping, $mode, $key, $this->option('dates') === 'mdy' ? 'mdy' : 'dmy');
        if ($errors !== []) {
            $importer->cancel($store->find($run->id) ?? $run);
            $this->fail(implode(' ', $errors));
        }
        $run = $this->drive($importer, $store->find($run->id) ?? $run, 'Checked.');
        $ready = $run->count('new') + $run->count('update');
        $this->line($ready . ' ready (' . $run->count('new') . ' new, ' . $run->count('update') . ' to update), ' . $run->count('errors') . ' with errors' . ($run->count('emptied') > 0 ? ', ' . $run->count('emptied') . ' fields would be emptied' : '') . '.');
        foreach ($importer->problems($run, 20) as $problem) {
            foreach ($problem['errors'] as $error) { $this->warn('  Row ' . $problem['line'] . ' · ' . ($error['field'] ?? '') . ': ' . ($error['message'] ?? '')); }
        }
        if ($this->option('dry-run') || $ready === 0) {
            $importer->cancel($run);
            $this->note($ready === 0 ? 'Nothing to import.' : 'Dry run: nothing was saved.');
            return $ready === 0 ? self::FAILURE : self::SUCCESS;
        }
        try {
            $run = $this->drive($importer, $importer->begin($run), 'Imported.');
        } catch (ImportException $error) {
            $this->fail($error->getMessage());
        }
        if ($run->status === 'failed') { $this->fail((string) $run->message); }
        $this->success($run->count('created') . ' created, ' . $run->count('updated') . ' updated, ' . ($run->count('errors') + $run->count('skipped')) . ' skipped. Undo with: php flo admin:import-undo ' . $run->id);
        return self::SUCCESS;
    }
}
