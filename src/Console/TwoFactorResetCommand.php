<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Auth\TwoFactor;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\{Argument, Definition};

/**
 * php flo admin:two-factor-reset sara@example.com (0.35): turn off someone's
 * two-factor sign-in from the server, for the last super admin who lost their
 * phone and their backup codes. They sign in with their password and set it up
 * again (at once, when their role requires it).
 */
final class TwoFactorResetCommand extends Command
{
    use BootsPanel;

    protected string $name = 'admin:two-factor-reset';

    protected string $description = "Turn off a user's two-factor sign-in (lost phone and backup codes)";

    protected string $help = "php flo admin:two-factor-reset sara@example.com. Removes the user's authenticator app, backup codes and remembered devices. Recorded in the activity log.";

    protected function configure(Definition $definition): void
    {
        $definition->argument('email', Argument::REQUIRED, 'The account\'s email address');
    }

    protected function handle(): int
    {
        $this->bootPanel();
        $status = TwoFactor::status();
        if ($status === 'off') { $this->fail("Two-factor sign-in is not turned on in config/admin.php ('two_factor' => true)."); }
        if ($status === 'missing') { $this->fail('The two-factor tables are missing: run php flo admin:install, then php flo migrate.'); }
        $db = \FloCMS\Core\App::db() ?? $this->fail('No database.');
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = $db->table('users')->whereRaw('LOWER(`email`) = ?', [$email])->first();
        if ($user === null) { $this->fail('No account with the address ' . $email . '.'); }
        $name = trim((string) ($user->full_name ?? '')) ?: (string) $user->email;
        if ($db->table(TwoFactor::table())->where('user_id', '=', (int) $user->id)->first() === null) {
            $this->line($name . ' does not use two-factor sign-in. Nothing to do.');
            return self::SUCCESS;
        }
        \FloCMS\Admin\Support\Actor::asSystem(static fn () => TwoFactor::disable((int) $user->id, 'two_factor_reset', $db));
        $this->success('Two-factor sign-in of ' . $name . ' was reset. They can sign in with their password'
            . (TwoFactor::isRequired($user->role ?? null, $db) ? ' and will set it up again right away (their role requires it).' : '.'));
        return self::SUCCESS;
    }
}
