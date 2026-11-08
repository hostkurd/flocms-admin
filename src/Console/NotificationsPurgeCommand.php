<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Notifications\Inbox;
use FloCMS\CLI\Console\Command;

/**
 * php flo admin:notifications-purge — remove notifications older than their
 * days (config/admin.php 'notifications' => ['days' => 90]). Panel pages do
 * this too, at most once an hour.
 */
final class NotificationsPurgeCommand extends Command
{
    protected string $name = 'admin:notifications-purge';

    protected string $description = 'Remove notifications older than their days';

    protected string $help = 'Run it from cron, e.g. once a day: php flo admin:notifications-purge.';

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
        if (!Inbox::enabled()) {
            $this->fail(Admin::config()->notifications()['enabled']
                ? 'The notifications table is missing: run php flo admin:install and php flo migrate.'
                : 'Notifications are switched off in config/admin.php.');
        }
        if (Inbox::days() === 0) {
            $this->note('Notifications are kept forever (\'days\' => 0): nothing to do.');
            return self::SUCCESS;
        }
        $count = Inbox::purgeExpired();
        $this->success($count . ' notification' . ($count === 1 ? '' : 's') . ' older than ' . Inbox::days() . ' days removed.');
        return self::SUCCESS;
    }
}
