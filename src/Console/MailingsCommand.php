<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\CLI\Console\Command;

/**
 * php flo admin:mailings — send the waiting emails of mailings (0.28), as many
 * as the hour allows ('mailings' => ['per_hour' => …]). Run it every minute
 * from cron, so mailings go out with no page open: * * * * * php flo admin:mailings
 */
final class MailingsCommand extends Command
{
    use BootsPanel;

    protected string $name = 'admin:mailings';

    protected string $description = 'Send waiting mailing emails (for cron)';

    protected string $help = 'Add to cron, every minute: php flo admin:mailings. Sends at most per_batch emails a step and per_hour an hour.';

    protected function handle(): int
    {
        $this->bootPanel();
        $status = \FloCMS\Admin\Mailings\Mailings::status();
        if ($status === 'off') { $this->line('Mailings are switched off in config/admin.php (\'mailings\' => false).'); return self::SUCCESS; }
        if ($status !== 'ready') { $this->fail('The mailing tables are missing: run php flo admin:install, then php flo migrate.'); }
        if (!\FloCMS\Admin\Mailings\Mailings::hasKey()) { $this->fail('Mailings need APP_KEY in .env (at least 16 characters) to sign unsubscribe links.'); }
        \FloCMS\Admin\Support\Actor::asSystem(function (): void {
            $sent = \FloCMS\Admin\Mailings\Mailings::run(50);
            $this->success($sent === 1 ? '1 email sent.' : $sent . ' emails sent.');
        });
        return self::SUCCESS;
    }
}
