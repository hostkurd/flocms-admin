<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\CLI\Console\Command;

/**
 * php flo admin:activity-purge — remove activity entries older than the log's
 * days (config/admin.php 'activity' => ['days' => 365]). Panel pages do this
 * too, at most once an hour.
 */
final class ActivityPurgeCommand extends Command
{
    protected string $name = 'admin:activity-purge';

    protected string $description = 'Remove activity log entries older than its days';

    protected string $help = 'Run it from cron, e.g. once a day: php flo admin:activity-purge.';

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
        if (!Activity::enabled()) {
            $this->fail(Admin::config()->activity()['enabled']
                ? 'The activity table is missing: run php flo admin:install and php flo migrate.'
                : 'The activity log is switched off in config/admin.php.');
        }
        if (Activity::days() === 0) {
            $this->note('The activity log keeps entries forever (\'days\' => 0): nothing to do.');
            return self::SUCCESS;
        }
        $count = Activity::purgeExpired();
        $this->success($count . ' entr' . ($count === 1 ? 'y' : 'ies') . ' older than ' . Activity::days() . ' days removed.');
        return self::SUCCESS;
    }
}
