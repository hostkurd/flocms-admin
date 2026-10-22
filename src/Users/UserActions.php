<?php
declare(strict_types=1);

namespace FloCMS\Admin\Users;

use FloCMS\Admin\Actions\Action;
use FloCMS\Admin\Actions\ActionContext;
use FloCMS\Admin\Actions\BulkAction;
use FloCMS\Admin\Actions\BulkActionContext;
use FloCMS\Admin\Actions\DeleteAction;
use FloCMS\Admin\Actions\DeleteBulkAction;
use FloCMS\Admin\Actions\EditAction;
use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Auth\PasswordReset;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Mail\MailException;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Core\Auth;
use FloCMS\Core\ValidationException;

/**
 * The Users list's actions (0.28): Suspend, Activate, Send password reset
 * link and Send email, for one user (the row's More menu) or a selection.
 * An action that doesn't apply to a user is greyed out with the reason; in
 * bulk, such users are skipped.
 *
 * @internal
 */
final class UserActions
{
    /** @return list<Action> */
    public static function row(): array
    {
        return [
            EditAction::make(),
            self::resetLink(Action::make('reset_link'))->group('account'),
            self::email(Action::make('email'))->group('account'),
            // Login security (0.35)
            self::verifyLink(Action::make('verify_link'))->group('account'),
            self::resetTwoFactor(Action::make('reset_two_factor'))->group('account'),
            self::activate(Action::make('activate'))->group('status'),
            self::suspend(Action::make('suspend'))->group('status'),
            DeleteAction::make(),
        ];
    }

    /** @return list<BulkAction> */
    public static function bulk(): array
    {
        return [
            self::activate(BulkAction::make('activate'))->skipUnavailable()->completedTitle(AdminLang::get('users.activated_count', ['count' => ':count']), AdminLang::get('users.activated_count_one')),
            self::suspend(BulkAction::make('suspend'))->skipUnavailable()->completedTitle(AdminLang::get('users.suspended_count', ['count' => ':count']), AdminLang::get('users.suspended_count_one')),
            self::resetLink(BulkAction::make('reset_link'))->skipUnavailable()->completedTitle(AdminLang::get('users.links_sent_count', ['count' => ':count']), AdminLang::get('users.links_sent_count_one')),
            BulkAction::make('email')->label(AdminLang::get('users.send_email'))->permission('users.email')
                ->bulkUrl(static fn (array $ids): string => Admin::url('mailings/compose', ['users' => implode(',', $ids)])),
            DeleteBulkAction::make(),
        ];
    }

    /** Why a user can't be changed by the signed-in person, or null. */
    public static function protectedReason(?object $record): ?string
    {
        if ($record === null) { return null; }
        if (UsersResource::isCurrentUser($record)) { return 'users.reason_self'; }
        if (!Auth::canManageUser((int) ($record->role ?? 0))) { return 'users.reason_role'; }
        return null;
    }

    private static function reason(?string $key): ?string
    {
        return $key === null ? null : AdminLang::get($key);
    }

    private static function name(?object $record): string
    {
        return $record === null ? '' : (trim((string) ($record->full_name ?? '')) ?: (string) ($record->email ?? ''));
    }

    private static function suspend(Action $action): Action
    {
        return $action->label(AdminLang::get('users.suspend'))->icon('ban')->permission('users.manage')
            ->disabled(static fn (?object $r): ?string => self::reason(self::protectedReason($r) ?? ((int) ($r->status ?? 0) === UsersResource::SUSPENDED ? 'users.reason_already_suspended' : null)))
            ->modalHeading(static fn (?object $r): string => $r !== null ? AdminLang::get('users.suspend_title', ['name' => self::name($r)]) : '')
            ->modalDescription(static fn (?object $r): string => AdminLang::get($r !== null ? 'users.suspend_text' : 'users.suspend_bulk_text', ['name' => self::name($r), 'count' => ':count']))
            ->color('danger')
            ->form(static fn (Form $form): Form => $form->columns(1)->schema([
                Textarea::make('reason')->label(AdminLang::get('users.reason'))->rows(3)->rules('max:500')->help(AdminLang::get('users.reason_help')),
                Toggle::make('notify')->label(AdminLang::get('users.tell_by_email')),
            ]))
            ->action(self::handler(static function (ActionContext $context, array $data): void {
                $context->update(['status' => UsersResource::SUSPENDED]);
                $reason = trim((string) ($data['reason'] ?? ''));
                Activity::record('user_suspended', 'accounts', (string) $context->record->id, self::name($context->record), meta: $reason !== '' ? ['reason' => mb_substr($reason, 0, 500)] : []);
                if (!empty($data['notify'])) { AccountMail::suspended($context->record); }
            }, 'users.suspended_one'));
    }

    private static function activate(Action $action): Action
    {
        return $action->label(AdminLang::get('users.activate'))->icon('check-circle')->permission('users.manage')
            ->disabled(static fn (?object $r): ?string => self::reason(self::protectedReason($r) ?? ((int) ($r->status ?? 0) === UsersResource::ACTIVE ? 'users.reason_already_active' : null)))
            ->modalHeading(static fn (?object $r): string => $r !== null ? AdminLang::get('users.activate_title', ['name' => self::name($r)]) : '')
            ->modalDescription(static fn (?object $r): string => $r === null ? AdminLang::get('users.activate_bulk_text')
                : AdminLang::get((int) ($r->status ?? 0) === UsersResource::PENDING ? 'users.activate_pending_text' : 'users.activate_text', ['name' => self::name($r)]))
            ->form(static fn (Form $form): Form => $form->columns(1)->schema([
                Toggle::make('notify')->label(AdminLang::get('users.tell_active')),
            ]))
            ->action(self::handler(static function (ActionContext $context, array $data): void {
                $pending = (int) ($context->record->status ?? 0) === UsersResource::PENDING;
                // An admin approved a pending account: its email counts as verified.
                $context->update(['status' => UsersResource::ACTIVE] + ($pending ? ['is_verified' => 1] : []));
                Activity::record('user_activated', 'accounts', (string) $context->record->id, self::name($context->record));
                if (!empty($data['notify'])) { AccountMail::activated($context->record); }
            }, 'users.activated_one'));
    }

    private static function resetLink(Action $action): Action
    {
        return $action->label(AdminLang::get('users.send_reset'))->icon('key')->permission('users.manage')
            ->disabled(static function (?object $r): ?string {
                if ($r === null) { return PasswordReset::available() ? null : AdminLang::get('users.reason_reset_off'); }
                return self::reason(self::protectedReason($r)
                    ?? (!PasswordReset::available() ? 'users.reason_reset_off' : null)
                    ?? ((int) ($r->status ?? 0) !== UsersResource::ACTIVE ? 'users.reason_activate_first' : null)
                    ?? (filter_var((string) ($r->email ?? ''), FILTER_VALIDATE_EMAIL) === false ? 'users.reason_no_email' : null));
            })
            ->modalHeading(static fn (?object $r): string => $r !== null ? AdminLang::get('users.reset_title', ['name' => self::name($r)]) : '')
            ->modalDescription(static fn (?object $r): string => $r !== null
                ? AdminLang::get('users.reset_text', ['email' => (string) ($r->email ?? ''), 'minutes' => Admin::config()->passwordReset()['expires']])
                : AdminLang::get('users.reset_bulk_text', ['minutes' => Admin::config()->passwordReset()['expires']]))
            ->form(static fn (Form $form): Form => $form->columns(1)->schema([
                Toggle::make('sign_out')->label(AdminLang::get('users.reset_sign_out'))->help(AdminLang::get('users.reset_sign_out_help')),
            ]))
            ->action(self::handler(static function (ActionContext $context, array $data): void {
                $issued = PasswordReset::issueFor($context->record);
                if ($issued === null) { throw new ValidationException(['_form' => AdminLang::get('users.reset_limited', ['name' => self::name($context->record)])]); }
                // The link goes out first: when it can't, the password is not changed either.
                try { PasswordReset::sendLink($issued['user'], $issued['token']); }
                catch (MailException $error) {
                    error_log('flocms-admin: reset link to user ' . $context->record->id . ' failed: ' . $error->getMessage());
                    throw new ValidationException(['_form' => AdminLang::get('users.mail_failed')]);
                }
                if (!empty($data['sign_out'])) {
                    // The current password stops working, and every session ends with it (0.26 stamp).
                    $context->update(['password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
                }
                Activity::record('password_link_sent', 'accounts', (string) $context->record->id, self::name($context->record), meta: !empty($data['sign_out']) ? ['signed_out' => 1] : []);
            }, 'users.link_sent_one'));
    }

    /** Send verification email (an invitation for a pending account that never confirmed). */
    private static function verifyLink(Action $action): Action
    {
        return $action->label(AdminLang::get('users.send_verification'))->icon('mail')->permission('users.manage')
            ->visible(static fn (?object $r = null): bool => \FloCMS\Admin\Auth\EmailVerification::available())
            ->disabled(static fn (?object $r): ?string => self::reason($r === null ? null
                : (self::protectedReason($r) ?? ((int) ($r->status ?? 0) === UsersResource::SUSPENDED ? 'users.reason_activate_first'
                : (\FloCMS\Admin\Auth\EmailVerification::isVerified($r) ? 'users.reason_verified'
                : (filter_var((string) ($r->email ?? ''), FILTER_VALIDATE_EMAIL) === false ? 'users.reason_no_email' : null))))))
            ->modalHeading(static fn (?object $r): string => $r !== null ? AdminLang::get((int) ($r->status ?? 0) === UsersResource::PENDING ? 'users.invite_title' : 'users.verify_title', ['name' => self::name($r)]) : '')
            ->modalDescription(static fn (?object $r): string => $r !== null ? AdminLang::get((int) ($r->status ?? 0) === UsersResource::PENDING ? 'users.invite_text' : 'users.verify_text',
                ['email' => (string) ($r->email ?? ''), 'hours' => max(1, intdiv(Admin::config()->emailVerification()['expires'], 60))]) : '')
            ->requiresConfirmation()
            ->action(self::handler(static function (ActionContext $context): void {
                $user = $context->record;
                try {
                    $sent = (int) ($user->status ?? 0) === UsersResource::PENDING
                        ? \FloCMS\Admin\Auth\EmailVerification::invite($user, \FloCMS\Core\Session::get('fullname') ?: null)
                        : \FloCMS\Admin\Auth\EmailVerification::sendVerification($user);
                } catch (MailException|\InvalidArgumentException $error) {
                    error_log('flocms-admin: verification email to user ' . $user->id . ' failed: ' . $error->getMessage());
                    throw new ValidationException(['_form' => AdminLang::get('users.mail_failed')]);
                }
                if (!$sent) { throw new ValidationException(['_form' => AdminLang::get('users.verify_limited', ['name' => self::name($user)])]); }
            }, 'users.verify_sent_one'));
    }

    /** Reset two-factor: for someone who lost their phone and their backup codes. */
    private static function resetTwoFactor(Action $action): Action
    {
        return $action->label(AdminLang::get('users.reset_two_factor'))->icon('shield')->permission('users.manage')
            ->visible(static fn (?object $r = null): bool => \FloCMS\Admin\Auth\TwoFactor::available())
            ->disabled(static fn (?object $r): ?string => self::reason(self::protectedReason($r)
                ?? ($r !== null && !\FloCMS\Admin\Auth\TwoFactor::isOn((int) $r->id) ? 'users.reason_two_factor_off' : null)))
            ->modalHeading(static fn (?object $r): string => $r !== null ? AdminLang::get('users.reset_two_factor_title', ['name' => self::name($r)]) : '')
            ->modalDescription(static fn (?object $r): string => $r !== null ? AdminLang::get(\FloCMS\Admin\Auth\TwoFactor::isRequired($r->role ?? null) ? 'users.reset_two_factor_required' : 'users.reset_two_factor_text', ['name' => self::name($r)]) : '')
            ->form(static fn (Form $form): Form => $form->columns(1)->schema([
                Toggle::make('notify')->label(AdminLang::get('users.reset_two_factor_notify'))->default('1'),
            ]))
            ->action(self::handler(static function (ActionContext $context, array $data): void {
                \FloCMS\Admin\Auth\TwoFactor::disable((int) $context->record->id, 'two_factor_reset');
                if (!empty($data['notify'])) {
                    try { \FloCMS\Admin\Auth\SecurityMail::twoFactorReset($context->record); }
                    catch (MailException|\InvalidArgumentException $error) {
                        error_log('flocms-admin: two-factor reset notice to user ' . $context->record->id . ' failed: ' . $error->getMessage());
                        Notification::make()->title(AdminLang::get('users.notice_failed', ['name' => self::name($context->record)]))->warning()->send();
                    }
                }
            }, 'users.two_factor_reset_one'));
    }

    private static function email(Action $action): Action
    {
        return $action->label(AdminLang::get('users.send_email'))->icon('mail')->permission('users.email')
            ->disabled(static fn (?object $r): ?string => self::reason($r === null ? null
                : ((int) ($r->status ?? 0) === UsersResource::SUSPENDED ? 'users.reason_activate_first'
                : (filter_var((string) ($r->email ?? ''), FILTER_VALIDATE_EMAIL) === false ? 'users.reason_no_email' : null))))
            ->url(static fn (?object $r): ?string => $r !== null ? Admin::url('mailings/compose', ['users' => (string) $r->id]) : null);
    }

    /**
     * A handler for both a row action (one context) and a bulk action (each
     * selected user), with the row's own success message.
     */
    private static function handler(\Closure $each, string $done): \Closure
    {
        return static function (ActionContext|BulkActionContext $context, array $data) use ($each, $done): void {
            if ($context instanceof BulkActionContext) {
                // One user who can't be done (an hour's limit, an email that won't go) doesn't stop the others.
                $failed = [];
                $context->each(static function (ActionContext $one) use ($each, $data, &$failed): void {
                    try { $each($one, $data); } catch (ValidationException) { $failed[] = self::name($one->record); }
                });
                if ($failed !== []) {
                    $names = implode(', ', array_slice($failed, 0, 5)) . (count($failed) > 5 ? ' …' : '');
                    Notification::make()->title(AdminLang::get('users.bulk_failed', ['count' => count($failed), 'names' => $names]))->warning()->send();
                }
                return;
            }
            $each($context, $data);
            Notification::make()->title(AdminLang::get($done, ['name' => self::name($context->record)]))->success()->send();
        };
    }
}
