<?php
declare(strict_types=1);

namespace FloCMS\Admin\Auth;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Mail\Mailer;
use FloCMS\Admin\Mail\Message;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;

/**
 * The emails of login security (0.35): verification, invitation and email
 * change links, and notices about two-factor sign-in. Each throws
 * MailException when it can't be sent, so the caller decides what to tell.
 *
 * @internal
 */
final class SecurityMail
{
    public static function verify(object $user, string $url): void
    {
        self::send($user, (string) $user->email, 'verify', ['url' => $url, 'label' => AdminLang::get('mail.verify_button')],
            ['hours' => self::hours()]);
    }

    public static function invite(object $user, string $url, ?string $by): void
    {
        $role = \FloCMS\Admin\Users\UsersResource::roles()[(int) ($user->role ?? -1)] ?? '';
        self::send($user, (string) $user->email, 'invite', ['url' => $url, 'label' => AdminLang::get('mail.invite_button')],
            ['by' => $by ?? Admin::config()->brand(), 'role' => $role, 'hours' => self::hours()]);
    }

    /** The link to confirm a new address goes to the new address. */
    public static function change(object $user, string $newEmail, string $url): void
    {
        self::send($user, $newEmail, 'change', ['url' => $url, 'label' => AdminLang::get('mail.change_button')],
            ['email' => $newEmail, 'hours' => self::hours()]);
    }

    /** The old address hears that a change was asked for (it stays until the link is used). */
    public static function changeNotice(object $user, string $newEmail): void
    {
        self::send($user, (string) $user->email, 'change_notice', null, ['email' => $newEmail]);
    }

    public static function twoFactorLocked(object $user): void
    {
        self::send($user, (string) $user->email, 'two_factor_locked', null, ['minutes' => TwoFactor::LOCK_MINUTES]);
    }

    public static function twoFactorReset(object $user): void
    {
        self::send($user, (string) $user->email, 'two_factor_reset', ['url' => PasswordReset::absolute('users/login'), 'label' => AdminLang::get('mail.activated_button')], []);
    }

    /** Send after the answer has gone out; a failure is logged. */
    public static function later(callable $send): void
    {
        PasswordReset::later($send);
    }

    /**
     * @param array{url: string, label: string}|null $button
     * @param array<string, string|int> $replace
     */
    private static function send(object $user, string $to, string $kind, ?array $button, array $replace): void
    {
        $brand = Admin::config()->brand();
        $name = trim((string) ($user->full_name ?? '')) ?: $to;
        $replace += ['brand' => $brand, 'name' => $name];
        $text = static fn (string $key): string => AdminLang::get('mail.' . $kind . '_' . $key, $replace);
        $lang = AdminLang::language();
        $note = $text('note');
        $note = $note !== 'mail.' . $kind . '_note' ? $note : '';
        $body = '<p style="margin:0 0 12px">' . Str::e($text('greeting')) . '</p><p style="margin:0">' . Str::e($text('body')) . '</p>'
            . ($note !== '' ? '<p style="margin:16px 0 0;color:#667085;font-size:13px">' . Str::e($note) . '</p>' : '')
            . ($button !== null ? '<p style="margin:16px 0 0;color:#667085;font-size:12px;word-break:break-all">' . Str::e(AdminLang::get('mail.fallback')) . '<br>' . Str::e($button['url']) . '</p>' : '');
        $html = \FloCMS\Core\TemplateEngine::renderFile(dirname(__DIR__, 2) . '/resources/mail/message.html', [
            'lang' => $lang, 'dir' => \FloCMS\Admin\Http\PanelView::direction($lang), 'subject' => $text('subject'), 'heading' => $text('heading'),
            'color' => Admin::config()->primaryColor() ?? '#0b6e91', 'brand' => $brand, 'initial' => mb_strtoupper(mb_substr(trim($brand), 0, 1)),
            'body' => $body, 'button' => $button, 'footer' => Str::e(AdminLang::get('mail.footer', $replace)),
        ]);
        $plain = $text('greeting') . "\n\n" . $text('body') . ($button !== null ? "\n\n" . $button['label'] . ":\n" . $button['url'] : '')
            . ($note !== '' ? "\n\n" . $note : '') . "\n\n-- \n" . AdminLang::get('mail.footer', $replace);
        Mailer::send(new Message($to, $text('subject'), $plain, $html, toName: $name !== $to ? $name : null));
    }

    private static function hours(): int
    {
        return max(1, intdiv(Admin::config()->emailVerification()['expires'], 60));
    }
}
