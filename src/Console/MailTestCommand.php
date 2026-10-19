<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Mail\MailException;
use FloCMS\Admin\Mail\Mailer;
use FloCMS\Admin\Mail\Message;
use FloCMS\CLI\Console\Input\{Argument, Definition};
use FloCMS\CLI\Console\Command;

/** php flo admin:mail-test you@example.com — send a test email with the MAIL_* settings (0.26). */
final class MailTestCommand extends Command
{
    protected string $name = 'admin:mail-test';

    protected string $description = 'Send a test email with the MAIL_* settings in .env';

    protected string $help = 'php flo admin:mail-test you@example.com. Uses MAIL_TRANSPORT (mail, smtp or log), MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM and MAIL_FROM_NAME from .env, as the "Forgot password?" emails do.';

    protected function configure(Definition $definition): void
    {
        $definition->argument('address', Argument::REQUIRED, 'Where to send the test email');
    }

    protected function handle(): int
    {
        $project = $this->project();
        $project->boot();
        Admin::setRoot($project->root());
        $to = trim((string) $this->argument('address'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('Give the address to send to: php flo admin:mail-test you@example.com');
        }
        $settings = Mailer::settings();
        $this->line('Sending with ' . match ($settings['transport']) {
            'smtp' => 'SMTP ' . $settings['host'] . ':' . $settings['port'] . ' (' . $settings['encryption'] . ($settings['username'] !== '' ? ', as ' . $settings['username'] : '') . ')',
            'log' => 'the log (storage/logs/admin-mail.log)',
            default => 'PHP mail()',
        } . ' from ' . ($settings['from'] !== '' ? $settings['from'] : '?') . ' …');
        $brand = Admin::config()->brand();
        try {
            Mailer::send(new Message($to, 'Test email from ' . $brand,
                "This is a test email from " . $brand . ".\nIf you can read it, password reset emails can be sent too.",
                '<p>This is a test email from <strong>' . htmlspecialchars($brand, ENT_QUOTES) . '</strong>.</p><p>If you can read it, password reset emails can be sent too.</p>'));
        } catch (MailException $error) {
            $this->fail('Not sent: ' . $error->getMessage());
        }
        $this->success($settings['transport'] === 'log' ? 'Written to storage/logs/admin-mail.log.' : 'Handed to the mail server. Check the inbox (and the spam folder) of ' . $to . '.');
        return self::SUCCESS;
    }
}
