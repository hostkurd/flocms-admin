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
    protected array $actionPermissions = ['admin_index' => null, 'admin_preferences' => null];

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

        if ($this->isPost()) {
            $state = [];
            foreach ($form->fields() as $name => $field) {
                $state[$name] = $field instanceof Placeholder
                    ? $field->stateFromRecord($user, Languages::default())
                    : $field->stateFromInput($this->request->all(), []);
            }

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

            if ($errors === []) {
                $this->save($db, (int) $id, $state);
                Session::setFlash(AdminLang::get('profile.saved'), 'success');
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
            (new FormRenderer($state, $errors, [], Languages::default(), true))->render($form), 'account', $errors !== []);

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
        foreach (['account' => ['profile', 'profile.account', 'profile.account_description', 'user'], 'preferences' => ['profile/preferences', 'preferences.title', 'preferences.tab_description', 'settings']] as $name => [$path, $label, $sub, $icon]) {
            $tabs[] = ['name' => $name, 'label' => AdminLang::get($label), 'description' => AdminLang::get($sub), 'url' => Admin::url($path), 'icon' => \FloCMS\Admin\Support\Icons::svg($icon), 'active' => $name === $current];
        }
        return TemplateEngine::renderFile(PageView::viewPath('settings'), [
            't' => PageView::translator(), 'title' => $title, 'description' => $description, 'action' => $action,
            'fields' => $fields, 'csrf' => \FloCMS\Core\Csrf::field(), 'pages' => $tabs, 'pagesLabel' => AdminLang::get('nav.profile'),
            'switch' => false, 'languages' => [], 'editing' => Languages::default(), 'defaultLang' => Languages::default(),
            'defaultName' => Languages::name(Languages::default()), 'ready' => true, 'dirty' => $dirty, 'notice' => $notice,
        ]);
    }

    private function form(object $user): Form
    {
        $roles = UsersResource::roles();
        $statuses = UsersResource::statuses();

        return (new Form())->schema([
            TextInput::make('full_name')->label(AdminLang::get('users.full_name'))->rules('required|max:190'),
            TextInput::make('email')->email()->label(AdminLang::get('users.email'))->rules('required|max:190')->unique(),
            TextInput::make('login')->label(AdminLang::get('users.login'))->rules('max:190')->unique()
                ->help(AdminLang::get('users.login_help')),
            Placeholder::make('role_info')->label(AdminLang::get('users.role'))
                ->content(static fn (?object $r): string => $roles[(int) ($r->role ?? -1)] ?? (string) ($r->role ?? '')),
            TextInput::make('password')->password(false)->label(AdminLang::get('profile.new_password'))
                ->rules('min:' . UsersResource::MIN_PASSWORD)->help(AdminLang::get('profile.new_password_help')),
            TextInput::make('current_password')->password(false)->label(AdminLang::get('profile.current_password'))
                ->help(AdminLang::get('profile.current_password_help')),
            Placeholder::make('status_info')->label(AdminLang::get('users.status'))
                ->content(static fn (?object $r): string => $statuses[(int) ($r->status ?? -1)] ?? (string) ($r->status ?? '')),
        ]);
    }

    /** @param array<string, string|array<string, string>> $state */
    private function save(Database $db, int $id, array $state): void
    {
        $email = (string) $state['email'];
        $login = (string) $state['login'];
        $data = ['full_name' => (string) $state['full_name'], 'email' => $email, 'login' => $login];
        if ($state['password'] !== '') {
            $data['password'] = password_hash((string) $state['password'], PASSWORD_DEFAULT);
        }

        $db->table('users')->where('id', '=', $id)->update($data);

        // The top bar and later requests show the new values at once
        Session::set('fullname', $data['full_name']);
        Session::set('email', $email);
        Session::set('username', $login);
    }
}
