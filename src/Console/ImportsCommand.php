<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\CLI\Console\Command;

/** php flo admin:imports — the imports of the last 30 days (0.25). */
final class ImportsCommand extends Command
{
    use BootsPanel;

    protected string $name = 'admin:imports';

    protected string $description = 'List the CSV imports of the last 30 days';

    protected function handle(): int
    {
        $this->bootPanel();
        $store = $this->importStore();
        $runs = $store->runs(array_keys(Admin::registry()->all()), 1, 100)['data'];
        if ($runs === []) { $this->note('No imports in the last 30 days.'); return self::SUCCESS; }
        $this->table(['#', 'Resource', 'File', 'By', 'When', 'Rows', 'Created', 'Updated', 'Skipped', 'Status'], array_map(static fn ($run): array => [
            (string) $run->id, $run->resource, $run->fileName, $run->userName, $run->createdAt, (string) $run->rowsTotal,
            (string) $run->count('created'), (string) $run->count('updated'), (string) ($run->count('errors') + $run->count('skipped')),
            $run->isPaused() ? 'paused' : $run->status . ($run->canUndo() ? ' (can undo)' : ''),
        ], $runs));
        return self::SUCCESS;
    }
}
