<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\CLI\Console\Command;

/**
 * php flo admin:import-purge — remove upload files of imports that ended or were
 * left more than 'keep_hours' ago, the Undo data of imports past 'undo_days', and
 * imports older than 30 days (0.25). Panel pages do this too, at most once an hour.
 */
final class ImportPurgeCommand extends Command
{
    use BootsPanel;

    protected string $name = 'admin:import-purge';

    protected string $description = 'Remove old import files and Undo data';

    protected string $help = 'Run it from cron, e.g. once a day: php flo admin:import-purge.';

    protected function handle(): int
    {
        $this->bootPanel();
        $count = $this->importStore()->purge();
        $this->success('Files of ' . $count . ' import' . ($count === 1 ? '' : 's') . ' removed.');
        return self::SUCCESS;
    }
}
