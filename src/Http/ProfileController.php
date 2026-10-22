<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\Placeholder;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Users\UsersResource;
use FloCMS\Admin\Validation\FormValidator;
use FloCMS\Core\App;
use FloCMS\Core\Database;
use FloCMS\Core\HttpException;
use FloCMS\Core\Session;
use FloCMS\Core\TemplateEngine;

/**
 * /admin/profile: the logged-in user edits their own name, email, login and
 * password. Changing the email or the password asks for the current password.
 * Role and status are shown, not editable.
 */
final class ProfileController extends PanelController
{
    protected array $actionPermissions = ['admin_index' => null, 'admin_preferences' => null, 'admin_security' => null];

    private const SETUP_KEY = 'admin_2fa_profile_setup';
    private const CODES_KEY = 'admin_2fa_profile_codes';
    private const SETUP_SECONDS = 900;

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        $db = App::db() ?? throw new \RuntimeException('flocms-admin needs a database: set DB_NAME, DB_USERNAME and DB_PASSWORD in .env.');
        $id = Session::get('user_id');
        if ($id === null || $id === '' || !is_numeric($id)) {
            throw new HttpException(404);
        }

        $user = $db->table('users')->where('id', '=', (int) $id)->first() ?? throw new HttpException(404);
        $form = $this->form($user);
        $errors = [];

        $users = new UsersResource();
        if ($this->isPost()) {
            // The photo (0.28) arrives with the page, like a resource's upload field.
            $input = \FloCMS\Admin\Uploads\Uploads::receive($form, $this->request->all(), $_FILES['_uploads'] ?? [], $users, $user);
            $state = [];
            foreach ($form->fields() as $name => $field) {
                $state[$name] = $field instanceof Placeholder
                    ? $field->stateFromRecord($user, Languages::default())
                    : $field->stateFromInput($input, []);
            }
            $state = \FloCMS\Admin\Uploads\Uploads::state($form, $state, $users, $user);

            $state = UsersResource::normalizeLogin($state);

            $taken = static fn (Field $field, string $value): bool => $db->table('users')
                ->where($field->getName(), '=', $value)->where('id', '!=', (int) $id)->count() > 0;
            $skip = $state['password'] === '' ? ['password'] : [];
            $errors = (new FormValidator([], Languages::default(), $taken, true))->validate($form, $state, $skip);

            $changesEmail = strtolower((string) $state['email']) !== strtolower((string) ($user->email ?? ''));
            $changesPassword = $state['password'] !== '';
            if ($errors === [] && ($changesEmail || $changesPassword)) {
                if ($state['current_password'] === '') {
                    $errors['current_password'] = AdminLang::get('profile.current_password_required');
                } elseif (!password_verify((string) $state['current_password'], (string) ($user->password ?? ''))) {
                    $errors['current_password'] = AdminLang::get('profile.current_password_wrong');
                }
            }

            // Email verification (0.35): a new address applies once the link sent to it is opened.
            $newEmail = null;
            if ($errors === [] && $changesEmail && \FloCMS\Admin\Auth\EmailVerification::available($db)) {
                $newEmail = (string) $state['email'];
                $state['email'] = (string) ($user->email ?? '');
            }

            if ($errors === []) {
                $before = \FloCMS\Admin\Activity\Activity::enabled() ? \FloCMS\Admin\Activity\ActivityChanges::state($form, $user) : [];
                $this->save($db, (int) $id, $state, $form, $users, $user);
                if (\FloCMS\Admin\Activity\Activity::enabled()) {
                    $saved = $db->table('users')->where('id', '=', (int) $id)->first();
                    $changes = $saved !== null ? \FloCMS\Admin\Activity\ActivityChanges::diff($form, $before, \FloCMS\Admin\Activity\ActivityChanges::state($form, $saved)) : [];
                    if ($changes !== []) {
                        \FloCMS\Admin\Activity\Activity::record('profile_updated', 'accounts', (string) (int) $id, (string) ($saved->full_name ?? ''), $changes, db: $db);
                    }
                }
                Session::setFlash(AdminLang::get('profile.saved'), 'success');
                if ($newEmail !== null) {
                    $saved = $db->table('users')->where('id', '=', (int) $id)->first() ?? $user;
                    try {
                        $sent = \FloCMS\Admin\Auth\EmailVerification::requestChange($saved, $newEmail, $db);
                        Session::setFlash(AdminLang::get($sent ? 'security.change_sent' : 'security.limited', ['email' => $newEmail]), $sent ? 'success' : 'warning');
                    } catch (\FloCMS\Admin\Mail\MailException|\InvalidArgumentException $error) {
                        error_log('flocms-admin: email change link for user ' . $id . ' failed: ' . $error->getMessage());
                        Session::setFlash(AdminLang::get('security.mail_failed'), 'danger');
                    }
                    $this->redirect(Admin::url('profile/security'));
                }
                $this->redirect(Admin::url('profile'));
            }

            Session::setFlash(AdminLang::get('flash.fix_errors'), 'danger');
            $state['password'] = '';
            $state['current_password'] = '';
        } else {
            $state = [];
            foreach ($form->fields() as $name => $field) {
                $state[$name] = $field->stateFromRecord($user, Languages::default());
            }
        }

        $title = AdminLang::get('profile.title');
        $html = self::page($title, null, Admin::url('profile'),
            (new FormRenderer($state, $errors, [], Languages::default(), true, fileUrl: \FloCMS\Admin\Uploads\Uploads::url($users, $user)))->render($form), 'account', $errors !== []);

        return $this->panel($title, $html, 'profile');
    }

    /**
     * /admin/profile/preferences: the user's own theme, panel language and
     * table density. "Panel default" (empty) follows the panel settings.
     */
    public function admin_preferences(): string|\FloCMS\Core\Http\Response
    {
        $preferences = \FloCMS\Admin\Preferences\Preferences::current() ?? throw new HttpException(404);
        $form = self::preferencesForm();
        $errors = [];
        if ($this->isPost()) {
            $state = [];
            foreach ($form->fields() as $name => $field) {
                $state[$name] = $field->stateFromInput($this->request->all(), []);
            }
            $errors = (new FormValidator([], Languages::default(), null, true))->validate($form, $state);
            if ($errors === []) {
                foreach ($form->fields() as $name => $field) {
                    $preferences->set($name, (string) $state[$name]);
                }
                Session::setFlash(AdminLang::get('preferences.saved'), 'success');
                $language = (string) ($state['language'] ?? '');
                $this->redirect($language !== '' ? self::inLanguage(Admin::url('profile/preferences'), $language) : Admin::url('profile/preferences'));
            }
            Session::setFlash(AdminLang::get('flash.fix_errors'), 'danger');
        } else {
            $state = [];
            foreach ($form->fields() as $name => $field) {
                $value = $preferences->get($name);
                $state[$name] = is_string($value) ? $value : '';
            }
        }

        $title = AdminLang::get('preferences.title');
        $html = self::page($title, AdminLang::get('preferences.description'), Admin::url('profile/preferences'),
            (new FormRenderer($state, $errors, [], Languages::default(), true))->render($form), 'preferences', $errors !== [],
            $preferences->persistent() ? null : AdminLang::get('preferences.session_only'));

        return $this->panel($title, $html, 'profile');
    }

    private static function preferencesForm(): Form
    {
        $default = static fn (string $value): string => AdminLang::get('preferences.panel_default', ['value' => $value]);
        $choices = static fn (array $values, string $prefix): array => array_combine($values, array_map(static fn (string $v): string => AdminLang::get($prefix . $v), $values));
        $fields = [
            \FloCMS\Admin\Form\Fields\Select::make('theme')->label(AdminLang::get('preferences.theme'))
                ->options($choices(\FloCMS\Admin\AdminConfig::THEMES, 'theme.'))
                ->placeholder($default(AdminLang::get('theme.' . \FloCMS\Admin\Support\Appearance::panelDefault('theme')))),
        ];
        if (count(Languages::all()) > 1) {
            $names = [];
            foreach (Languages::all() as $code) { $names[$code] = Languages::name($code); }
            $fields[] = \FloCMS\Admin\Form\Fields\Select::make('language')->label(AdminLang::get('preferences.language'))
                ->options($names)->placeholder(AdminLang::get('preferences.language_default'))->help(AdminLang::get('preferences.language_help'));
        }
        $fields[] = \FloCMS\Admin\Form\Fields\Select::make('density')->label(AdminLang::get('preferences.density'))
            ->options($choices(\FloCMS\Admin\Settings\PanelSettings::DENSITIES, 'density.'))
            ->placeholder($default(AdminLang::get('density.' . \FloCMS\Admin\Support\Appearance::panelDefault('density'))));

        return (new Form())->columns(1)->schema($fields);
    }

    /** The same admin URL in another language: /en/admin/x → /ku/admin/x. */
    public static function inLanguage(string $url, string $lang): string
    {
        return (string) preg_replace('~/[a-z]{2,3}(-[a-z0-9]{2,8})?/admin(?=/|$|\?)~', '/' . rawurlencode($lang) . '/admin', $url, 1);
    }

    /** The profile's two tabs around a form (same layout as the settings pages). */
    private static function page(string $title, ?string $description, string $action, string $fields, string $current, bool $dirty, ?string $notice = null): string
    {
        $tabs = [];
        foreach (self::tabs() as $name => [$path, $label, $sub, $icon]) {
            $tabs[] = ['name' => $name, 'label' => AdminLang::get($label), 'description' => AdminLang::get($sub), 'url' => Admin::url($path), 'icon' => \FloCMS\Admin\Support\Icons::svg($icon), 'active' => $name === $current];
        }
        return TemplateEngine::renderFile(PageView::viewPath('settings'), [
            't' => PageView::translator(), 'title' => $title, 'description' => $description, 'action' => $action,
            'fields' => $fields, 'csrf' => \FloCMS\Core\Csrf::field(), 'pages' => $tabs, 'pagesLabel' => AdminLang::get('nav.profile'),
            'switch' => false, 'languages' => [], 'editing' => Languages::default(), 'defaultLang' => Languages::default(),
            'defaultName' => Languages::name(Languages::default()), 'ready' => true, 'dirty' => $dirty, 'notice' => $notice,
        ]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    private static function tabs(): array
    {
        $tabs = ['account' => ['profile', 'profile.account', 'profile.account_description', 'user']];
        if (self::securityTab()) {
            $tabs['security'] = ['profile/security', 'security.title', 'security.tab_description', 'shield'];
        }
        $tabs['preferences'] = ['profile/preferences', 'preferences.title', 'preferences.tab_description', 'settings'];
        return $tabs;
    }

    private static function securityTab(): bool
    {
        return Admin::config()->twoFactor()['enabled'] || Admin::config()->emailVerification()['enabled'];
    }

    /**
     * /admin/profile/security (0.35): two-factor sign-in (set up, backup codes,
     * remembered devices, turn off) and the email address (confirm it, a change
     * waiting for confirmation).
     */
    public function admin_security(): string|\FloCMS\Core\Http\Response
    {
        if (!self::securityTab()) { throw new HttpException(404); }
        $db = App::db() ?? throw new \RuntimeException('flocms-admin needs a database: set DB_NAME, DB_USERNAME and DB_PASSWORD in .env.');
        $id = Session::get('user_id');
        if ($id === null || $id === '' || !is_numeric($id)) { throw new HttpException(404); }
        $id = (int) $id;
        $user = $db->table('users')->where('id', '=', $id)->first() ?? throw new HttpException(404);
        $errors = [];
        if ($this->isPost()) {
            $errors = $this->securityAction($db, $user, (string) ($_POST['_action'] ?? ''));
            if ($errors === []) { $this->redirect(Admin::url('profile/security')); }
            Session::setFlash(AdminLang::get('flash.fix_errors'), 'danger');
        }

        $title = AdminLang::get('security.title');
        $html = TemplateEngine::renderFile(PageView::viewPath('security'), self::securityView($db, $user, $errors) + [
            't' => PageView::translator(), 'title' => $title, 'csrf' => \FloCMS\Core\Csrf::field(), 'action' => Admin::url('profile/security'),
            'pages' => self::tabItems('security'), 'pagesLabel' => AdminLang::get('nav.profile'),
        ]);
        return $this->panel($title, $html, 'profile');
    }

    /** @return list<array{name: string, label: string, description: string, url: string, icon: string, active: bool}> */
    private static function tabItems(string $current): array
    {
        $tabs = [];
        foreach (self::tabs() as $name => [$path, $label, $sub, $icon]) {
            $tabs[] = ['name' => $name, 'label' => AdminLang::get($label), 'description' => AdminLang::get($sub), 'url' => Admin::url($path), 'icon' => \FloCMS\Admin\Support\Icons::svg($icon), 'active' => $name === $current];
        }
        return $tabs;
    }

    /** @return array<string, string> errors by form; empty when the action was done */
    private function securityAction(Database $db, object $user, string $action): array
    {
        $id = (int) $user->id;
        $twoFactor = \FloCMS\Admin\Auth\TwoFactor::available($db);
        $proof = is_string($_POST['proof'] ?? null) ? (string) $_POST['proof'] : '';
        $on = $twoFactor && \FloCMS\Admin\Auth\TwoFactor::isOn($id, $db);
        switch ($action) {
            case 'start':
                if (!$twoFactor || $on) { return []; }
                if (!password_verify($proof, (string) ($user->password ?? ''))) { return ['start' => AdminLang::get('profile.current_password_wrong')]; }
                Session::set(self::SETUP_KEY, ['user' => $id, 'secret' => \FloCMS\Admin\Auth\SecretBox::encrypt(\FloCMS\Admin\Auth\Totp::newSecret()), 'at' => time()]);
                return [];
            case 'cancel_setup':
                Session::delete(self::SETUP_KEY);
                return [];
            case 'confirm':
                $secret = self::pendingSecret($id);
                if (!$twoFactor || $on || $secret === null) { Session::delete(self::SETUP_KEY); return []; }
                if (\FloCMS\Admin\Auth\Totp::verify($secret, is_string($_POST['code'] ?? null) ? $_POST['code'] : '') === null) {
                    return ['confirm' => AdminLang::get('two_factor.setup_wrong')];
                }
                $codes = \FloCMS\Admin\Auth\TwoFactor::enable($id, $secret, $db);
                Session::delete(self::SETUP_KEY);
                Session::set(self::CODES_KEY, ['user' => $id, 'codes' => $codes, 'first' => true]);
                return [];
            case 'codes_done':
                Session::delete(self::CODES_KEY);
                return [];
            case 'new_codes':
                if (!$on) { return []; }
                if (!self::proves($db, $user, $proof)) { return ['new_codes' => AdminLang::get('security.proof_wrong')]; }
                Session::set(self::CODES_KEY, ['user' => $id, 'codes' => \FloCMS\Admin\Auth\TwoFactor::regenerateCodes($id, $db), 'first' => false]);
                return [];
            case 'disable':
                if (!$on) { return []; }
                if (\FloCMS\Admin\Auth\TwoFactor::isRequired($user->role ?? null, $db)) { return ['disable' => AdminLang::get('security.required_cannot_off')]; }
                if (!self::proves($db, $user, $proof)) { return ['disable' => AdminLang::get('security.proof_wrong')]; }
                \FloCMS\Admin\Auth\TwoFactor::disable($id, db: $db);
                Session::setFlash(AdminLang::get('security.turned_off'), 'success');
                return [];
            case 'forget_device':
                if ($twoFactor) { \FloCMS\Admin\Auth\TrustedDevices::forget($id, (int) ($_POST['device'] ?? 0), $db); }
                Session::setFlash(AdminLang::get('security.device_forgotten'), 'success');
                return [];
            case 'forget_devices':
                if ($twoFactor) { \FloCMS\Admin\Auth\TrustedDevices::forgetAll($id, $db); }
                Session::setFlash(AdminLang::get('security.devices_forgotten'), 'success');
                return [];
            case 'send_verification':
            case 'resend_change':
                if (!\FloCMS\Admin\Auth\EmailVerification::available($db)) { return []; }
                try {
                    if ($action === 'resend_change') {
                        $pending = \FloCMS\Admin\Auth\EmailVerification::pendingChange($id, $db);
                        $sent = $pending !== null && \FloCMS\Admin\Auth\EmailVerification::requestChange($user, (string) $pending->email, $db);
                    } else {
                        $sent = !\FloCMS\Admin\Auth\EmailVerification::isVerified($user) && \FloCMS\Admin\Auth\EmailVerification::sendVerification($user, $db);
                    }
                    Session::setFlash(AdminLang::get($sent ? 'security.link_sent' : 'security.limited'), $sent ? 'success' : 'warning');
                } catch (\FloCMS\Admin\Mail\MailException|\InvalidArgumentException $error) {
                    error_log('flocms-admin: email link for user ' . $id . ' failed: ' . $error->getMessage());
                    Session::setFlash(AdminLang::get('security.mail_failed'), 'danger');
                }
                return [];
            case 'cancel_change':
                if (\FloCMS\Admin\Auth\EmailVerification::available($db)) { \FloCMS\Admin\Auth\EmailVerification::cancelChange($id, $db); }
                Session::setFlash(AdminLang::get('security.change_cancelled'), 'success');
                return [];
        }
        return [];
    }

    /** The current password, or a code from the app. */
    private static function proves(Database $db, object $user, string $proof): bool
    {
        if ($proof === '') { return false; }
        if (password_verify($proof, (string) ($user->password ?? ''))) { return true; }
        $digits = \FloCMS\Admin\Auth\Totp::clean($proof);
        return strlen($digits) === \FloCMS\Admin\Auth\Totp::DIGITS && strlen(trim($proof)) <= 12
            && \FloCMS\Admin\Auth\TwoFactor::verifyCode((int) $user->id, $digits, $db) === 'ok';
    }

    private static function pendingSecret(int $id): ?string
    {
        $pending = Session::get(self::SETUP_KEY);
        if (!is_array($pending) || (int) ($pending['user'] ?? 0) !== $id || time() - (int) ($pending['at'] ?? 0) > self::SETUP_SECONDS) { return null; }
        return \FloCMS\Admin\Auth\SecretBox::decrypt((string) ($pending['secret'] ?? ''));
    }

    /**
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private static function securityView(Database $db, object $user, array $errors): array
    {
        $id = (int) $user->id;
        $status = \FloCMS\Admin\Auth\TwoFactor::status($db);
        $record = $status === 'ready' ? \FloCMS\Admin\Auth\TwoFactor::record($id, $db) : null;
        $secret = $status === 'ready' && $record === null ? self::pendingSecret($id) : null;
        $shown = Session::get(self::CODES_KEY);
        $codes = is_array($shown) && (int) ($shown['user'] ?? 0) === $id ? array_values(array_map('strval', (array) ($shown['codes'] ?? []))) : [];
        $uri = $secret !== null ? \FloCMS\Admin\Auth\Totp::uri($secret, Admin::config()->twoFactor()['issuer'], (string) ($user->email ?? '')) : '';
        $devices = $record !== null ? \FloCMS\Admin\Auth\TrustedDevices::list($id, $db) : [];
        $date = static fn (string $utc): string => $utc !== '' ? \FloCMS\Admin\Support\Dates::date((int) strtotime($utc . ' UTC')) : '';
        foreach ($devices as &$device) {
            $device['last_used_label'] = $date($device['last_used']);
            $device['expires_label'] = $date($device['expires']);
        }
        unset($device);
        $verification = \FloCMS\Admin\Auth\EmailVerification::available($db);
        $pending = $verification ? \FloCMS\Admin\Auth\EmailVerification::pendingChange($id, $db) : null;

        return [
            'errors' => $errors,
            'twoFactor' => Admin::config()->twoFactor()['enabled'], 'twoFactorStatus' => $status,
            'on' => $record !== null, 'since' => $record !== null ? $date((string) $record->confirmed_at) : '',
            'codesLeft' => $record !== null ? \FloCMS\Admin\Auth\TwoFactor::codesLeft($record) : 0,
            'total' => \FloCMS\Admin\Auth\TwoFactor::BACKUP_CODES,
            'required' => $status === 'ready' && \FloCMS\Admin\Auth\TwoFactor::isRequired($user->role ?? null, $db),
            'setup' => $secret !== null, 'qr' => $secret !== null ? \FloCMS\Admin\Auth\QrCode::encode($uri)->svg(AdminLang::get('two_factor.qr_label'), 184) : '',
            'key' => $secret !== null ? \FloCMS\Admin\Auth\Totp::displayKey($secret) : '', 'uri' => $uri,
            'codes' => $codes, 'codesFirst' => (bool) ($shown['first'] ?? false), 'codesFile' => $codes !== [] ? TwoFactorController::codesFile($codes) : '',
            'devices' => $devices, 'remembers' => \FloCMS\Admin\Auth\TrustedDevices::enabled(), 'days' => Admin::config()->twoFactor()['remember_days'],
            'verification' => Admin::config()->emailVerification()['enabled'] && $verification,
            'email' => (string) ($user->email ?? ''), 'verified' => \FloCMS\Admin\Auth\EmailVerification::isVerified($user),
            'pendingEmail' => $pending !== null ? (string) $pending->email : null,
            'hours' => max(1, intdiv(Admin::config()->emailVerification()['expires'], 60)),
            'accountUrl' => Admin::url('profile'),
            'icons' => ['phone' => \FloCMS\Admin\Support\Icons::svg('smartphone'), 'key' => \FloCMS\Admin\Support\Icons::svg('key'),
                'device' => \FloCMS\Admin\Support\Icons::svg('monitor'), 'mail' => \FloCMS\Admin\Support\Icons::svg('mail'), 'check' => \FloCMS\Admin\Support\Icons::svg('check')],
        ];
    }

    private function form(object $user): Form
    {
        $roles = UsersResource::roles();
        $statuses = UsersResource::statuses();

        // 0.28: the same layout as Users: details and password, photo and access in the sidebar.
        return (new Form())->schema([
            \FloCMS\Admin\Form\Layout\Section::make('profile')->label(AdminLang::get('users.section_profile'))->schema([
                TextInput::make('full_name')->label(AdminLang::get('users.full_name'))->rules('required|max:190'),
                TextInput::make('email')->email()->label(AdminLang::get('users.email'))->rules('required|max:190')->unique(),
                TextInput::make('login')->label(AdminLang::get('users.login'))->rules('max:190')->unique()
                    ->help(AdminLang::get('users.login_help')),
                ...(UsersResource::hasColumn('bio') ? [\FloCMS\Admin\Form\Fields\Textarea::make('bio')->label(AdminLang::get('users.bio'))->rows(4)->rules('max:2000')->nullable()->columnSpan('full')] : []),
            ]),
            \FloCMS\Admin\Form\Layout\Section::make('security')->label(AdminLang::get('users.section_password'))->schema([
                TextInput::make('password')->password(false)->label(AdminLang::get('profile.new_password'))
                    ->rules('min:' . UsersResource::MIN_PASSWORD)->help(AdminLang::get('profile.new_password_help')),
                TextInput::make('current_password')->password(false)->label(AdminLang::get('profile.current_password'))
                    ->help(AdminLang::get('profile.current_password_help')),
            ]),
        ])->aside([
            ...(UsersResource::hasImageColumn() ? [\FloCMS\Admin\Form\Layout\Section::make('photo')->label(AdminLang::get('users.photo'))->columns(1)->schema([
                \FloCMS\Admin\Form\Fields\ImageUpload::make('image')->label(AdminLang::get('users.photo'))->avatar()->help(AdminLang::get('users.photo_help')),
            ])] : []),
            \FloCMS\Admin\Form\Layout\Section::make('access')->label(AdminLang::get('users.section_access'))->columns(1)->schema([
                Placeholder::make('role_info')->label(AdminLang::get('users.role'))
                    ->content(static fn (?object $r): string => $roles[(int) ($r->role ?? -1)] ?? (string) ($r->role ?? '')),
                Placeholder::make('status_info')->label(AdminLang::get('users.status'))
                    ->content(static fn (?object $r): string => $statuses[(int) ($r->status ?? -1)] ?? (string) ($r->status ?? '')),
            ]),
        ]);
    }

    /** @param array<string, string|array<string, string>> $state */
    private function save(Database $db, int $id, array $state, Form $form, UsersResource $users, object $user): void
    {
        $email = (string) $state['email'];
        $login = (string) $state['login'];
        $data = ['full_name' => (string) $state['full_name'], 'email' => $email, 'login' => $login];
        if ($form->field('bio') !== null) { $bio = trim((string) ($state['bio'] ?? '')); $data['bio'] = $bio !== '' ? $bio : null; }
        if ($state['password'] !== '') {
            $data['password'] = password_hash((string) $state['password'], PASSWORD_DEFAULT);
        }

        // The photo: stored (cropped square) before the row is saved; the old one deleted after.
        $batch = new \FloCMS\Admin\Uploads\UploadBatch();
        if ($form->field('image') !== null) {
            $data['image'] = (string) ($batch->prepare($form, ['image' => $state['image'] ?? ''] + $state, $users, $user)['image'] ?? '');
        }
        try { $db->table('users')->where('id', '=', $id)->update($data); }
        catch (\Throwable $error) { $batch->rollback(); throw $error; }
        $batch->commit();
        \FloCMS\Admin\Support\Avatar::reset();
        if (isset($data['password'])) {
            // Other sessions of this account end; this one stays signed in (0.26)
            \FloCMS\Admin\Auth\PasswordStamp::restamp($data['password']);
        }

        // The top bar and later requests show the new values at once
        Session::set('fullname', $data['full_name']);
        Session::set('email', $email);
        Session::set('username', $login);
    }
}
