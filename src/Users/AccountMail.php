<?php
declare(strict_types=1);

namespace FloCMS\Admin\Users;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Mail\Mailer;
use FloCMS\Admin\Mail\MailException;
use FloCMS\Admin\Mail\Message;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;

/**
 * The short notes a user gets when an admin activates or suspends their
 * account and ticks "Tell them by email" (0.28). A note that can't be sent
 * doesn't undo the change: the admin is told instead.
 *
 * @internal
 */
final class AccountMail
{
    public static function activated(object $user): void
    {
        self::send($user, 'activated', ['url' => \FloCMS\Admin\Auth\PasswordReset::absolute('users/login'), 'label' => AdminLang::get('mail.activated_button')]);
    }

    public static function suspended(object $user): void
    {
        self::send($user, 'suspended', null);
    }

    /** @param array{url: string, label: string}|null $button */
    private static function send(object $user, string $kind, ?array $button): void
    {
        $email = (string) ($user->email ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) { return; }
        $brand = Admin::config()->brand();
        $name = trim((string) ($user->full_name ?? '')) ?: $email;
        $replace = ['brand' => $brand, 'name' => $name];
        $text = static fn (string $key): string => AdminLang::get('mail.' . $kind . '_' . $key, $replace);
        $lang = AdminLang::language();
        try {
            $body = '<p style="margin:0 0 12px">' . Str::e($text('greeting')) . '</p><p style="margin:0">' . Str::e($text('body')) . '</p>';
            $html = \FloCMS\Core\TemplateEngine::renderFile(dirname(__DIR__, 2) . '/resources/mail/message.html', [
                'lang' => $lang, 'dir' => \FloCMS\Admin\Http\PanelView::direction($lang), 'subject' => $text('subject'), 'heading' => $text('heading'),
                'color' => Admin::config()->primaryColor() ?? '#0b6e91', 'brand' => $brand, 'initial' => mb_strtoupper(mb_substr(trim($brand), 0, 1)),
                'body' => $body, 'button' => $button, 'footer' => Str::e(AdminLang::get('mail.footer', $replace)),
            ]);
            $plain = $text('greeting') . "\n\n" . $text('body') . ($button !== null ? "\n\n" . $button['label'] . ': ' . $button['url'] : '') . "\n\n-- \n" . AdminLang::get('mail.footer', $replace);
            Mailer::send(new Message($email, $text('subject'), $plain, $html, toName: $name !== $email ? $name : null));
        } catch (MailException|\InvalidArgumentException $error) {
            error_log('flocms-admin: account email to user ' . ($user->id ?? '?') . ' failed: ' . $error->getMessage());
            Notification::make()->title(AdminLang::get('users.notice_failed', ['name' => $name]))->warning()->send();
        }
    }
}
