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
    protected array $actionPermissions = ['admin_index' => null];

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
        $html = TemplateEngine::renderFile(PageView::viewPath('form'), [
            't' => PageView::translator(),
            'title' => $title,
            'action' => Admin::url('profile'),
            'fields' => (new FormRenderer($state, $errors, [], Languages::default(), true))->render($form),
            'csrf' => \FloCMS\Core\Csrf::field(),
            'listUrl' => Admin::url('dashboard'),
            'listLabel' => AdminLang::get('nav.dashboard'),
            'deleteUrl' => null,
            'isEdit' => true,
            'canContinue' => false,
            'hasErrors' => $errors !== [],
        ]);

        return $this->panel($title, $html, 'profile');
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
