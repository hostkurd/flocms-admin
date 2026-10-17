<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Trash\Trash;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo admin:trash-purge — delete for good the records that have been in the
 * trash longer than its days (config/admin.php 'trash' => ['days' => 30]), with
 * their upload files. Panel pages do this too, at most once an hour; a cron job
 * keeps it on time on quiet sites.
 */
final class TrashPurgeCommand extends Command
{
    protected string $name = 'admin:trash-purge';

    protected string $description = 'Delete for good the records older than the trash\'s days';

    protected string $help = 'Run it from cron, e.g. once a day: php flo admin:trash-purge. With --all, empties the whole trash (asks first).';

    protected function configure(Definition $definition): void
    {
        $definition->option('all', null, Option::VALUE_NONE, 'Empty the whole trash now (asks first)');
    }

    protected function handle(): int
    {
        $project = $this->project();
        $project->boot();
        Admin::setRoot($project->root());
        try {
            \FloCMS\Core\App::$db = $project->database();
        } catch (\Throwable $error) {
            $this->fail('No database: ' . $error->getMessage());
        }
        if (!Trash::enabled()) {
            $this->fail(Admin::config()->trash()['enabled']
                ? 'The trash table is missing: run php flo admin:install and php flo migrate.'
                : 'The trash is switched off in config/admin.php.');
        }
        if ($this->option('all')) {
            if (!$this->confirm('Delete every record in the trash for good?')) { $this->note('Nothing was deleted.'); return self::SUCCESS; }
            $count = 0;
            $db = \FloCMS\Core\App::$db;
            do {
                $rows = $db->table(Trash::table())->orderBy('id', 'asc')->limit(200)->get();
                foreach ($rows as $row) { Trash::purge(\FloCMS\Admin\Trash\TrashEntry::fromRow($row), $db); $count++; }
            } while (count($rows) === 200);
            $this->success($count . ' record(s) deleted for good.');
            return self::SUCCESS;
        }
        if (Trash::days() === 0) {
            $this->note('The trash keeps records until it is emptied (\'days\' => 0): nothing to do.');
            return self::SUCCESS;
        }
        $total = 0;
        do { $count = Trash::purgeExpired(); $total += $count; } while ($count === 500);
        $this->success($total . ' record(s) older than ' . Trash::days() . ' days deleted for good.');
        return self::SUCCESS;
    }
}
