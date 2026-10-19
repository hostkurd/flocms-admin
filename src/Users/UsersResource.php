<?php
declare(strict_types=1);

namespace FloCMS\Admin\Users;

use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Table\Columns\BadgeColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Auth;
use FloCMS\Core\Config;
use FloCMS\Core\Session;

/**
 * Users management at /admin/accounts, for the skeleton's `users` table.
 *
 * The rules are the skeleton's own (FloCMS\Core\Auth):
 *  - every action needs `users.manage` (Admins and Super Admins by default);
 *  - a role can only be given up to your own level (Auth::canAssignRole);
 *  - only users of a lower role can be edited or deleted, unless you hold
 *    `users.manage_any` (Auth::canManageUser);
 *  - nobody can delete themselves, change their own role or suspend themselves.
 *
 * Switch it off with 'users' => false in config/admin.php.
 */
final class UsersResource extends Resource
{
    public const ACTIVE = 1;
    public const PENDING = 2;
    public const SUSPENDED = 3;
    public const MIN_PASSWORD = 8;

    protected static string $table = 'users';
    protected static string $slug = 'accounts';
    protected static ?string $icon = 'users';
    protected static int $navigationSort = 1000;

    /** Accounts and their sessions don't restore cleanly: deleted users are logged, not trashed. */
    protected static bool $trash = false;
    protected static array $permissions = [
        'view' => 'users.manage',
        'create' => 'users.manage',
        'update' => 'users.manage',
        'delete' => 'users.manage',
    ];

    public static function getLabel(): string
    {
        return AdminLang::get('users.label');
    }

    public static function getPluralLabel(): string
    {
        return AdminLang::get('users.plural');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('full_name')->label(AdminLang::get('users.full_name'))->rules('required|max:190'),
            TextInput::make('email')->email()->label(AdminLang::get('users.email'))->rules('required|max:190')->unique(),
            TextInput::make('login')->label(AdminLang::get('users.login'))->rules('max:190')->unique()
                ->help(AdminLang::get('users.login_help')),
            TextInput::make('password')->password()->label(AdminLang::get('users.password'))
                ->rules('min:' . self::MIN_PASSWORD)->requiredOnCreate()
                ->help(AdminLang::get('users.password_help')),
            Select::make('role')->label(AdminLang::get('users.role'))->options(fn (): array => self::assignableRoles())->required(),
            Select::make('status')->label(AdminLang::get('users.status'))->options(fn (): array => self::statuses())->required()
                ->default((string) self::ACTIVE),
            TextInput::make('phone')->tel()->label(AdminLang::get('users.phone'))->rules('max:50'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('full_name')->label(AdminLang::get('users.full_name'))->searchable()->sortable(),
                TextColumn::make('email')->label(AdminLang::get('users.email'))->searchable()->sortable(),
                TextColumn::make('login')->label(AdminLang::get('users.login'))->searchable(),
                BadgeColumn::make('role')->label(AdminLang::get('users.role'))->labels(self::roles())
                    ->colors(array_fill_keys(array_keys(self::roles()), 'blue')),
                BadgeColumn::make('status')->label(AdminLang::get('users.status'))->labels(self::statuses())
                    ->colors([self::ACTIVE => 'green', self::PENDING => 'yellow', self::SUSPENDED => 'red']),
            ])
            ->defaultSort('id', 'desc');
    }

    public function canEdit(object $record): bool
    {
        // Yourself: only with users.manage_any (your own details: the profile page)
        return Auth::canManageUser((int) ($record->role ?? 0));
    }

    public function canDelete(object $record): bool
    {
        return !self::isCurrentUser($record) && Auth::canManageUser((int) ($record->role ?? 0));
    }

    public function validate(array $state, ?object $record): array
    {
        $errors = [];
        $role = (int) ($state['role'] ?? 0);

        if (!Auth::canAssignRole($role)) {
            $errors['role'] = AdminLang::get('users.cannot_assign_role');
        }

        if ($record !== null && self::isCurrentUser($record)) {
            if ($role !== (int) ($record->role ?? 0)) {
                $errors['role'] = AdminLang::get('users.cannot_change_own_role');
            }
            if ((int) ($state['status'] ?? 0) !== self::ACTIVE) {
                $errors['status'] = AdminLang::get('users.cannot_suspend_self');
            }
        }

        return $errors;
    }

    public function normalizeState(array $state, ?object $record): array
    {
        return self::normalizeLogin($state);
    }

    /** @param array<string, string|array<string, string>> $state */
    public static function normalizeLogin(array $state): array
    {
        if (($state['login'] ?? '') === '') {
            $state['login'] = $state['email'] ?? '';
        }
        return $state;
    }

    public function beforeSave(array $data, ?object $record): array
    {
        if ($record === null) {
            // Created by an admin: no email verification (as with user:create)
            $data['is_verified'] = 1;
            $data['verify_type'] = 2;
            $data['token'] = '';
        }

        return $data;
    }

    /**
     * A new password ends the account's other sessions (0.26); the admin who
     * changed their own password here stays signed in.
     */
    public function afterSave(object $record): void
    {
        $id = Session::get('user_id');
        if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id === (int) ($record->id ?? 0) && is_string($record->password ?? null)) {
            \FloCMS\Admin\Auth\PasswordStamp::restamp($record->password);
        }
    }

    /** A deleted user's personal preferences go too. */
    public function afterDelete(object $record): void
    {
        $id = (string) ($record->id ?? '');
        $store = \FloCMS\Admin\Preferences\Preferences::store();
        if ($id !== '' && $store instanceof \FloCMS\Admin\Preferences\DatabasePreferenceStore) {
            $store->forget($id);
        }
    }

    /** @return array<int, string> role number => label: Config 'roles', or the roles of the Roles page */
    public static function roles(): array
    {
        // Through flocms-core, so roles added on the Roles page (0.21) are listed too.
        $roles = method_exists(\FloCMS\Core\Auth::class, 'roles') ? \FloCMS\Core\Auth::roles() : Config::get('roles');
        if (!is_array($roles) || $roles === []) {
            $roles = [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin'];
        }

        $labels = [];
        foreach ($roles as $role => $label) {
            $labels[(int) $role] = AdminLang::label((string) $label);
        }

        return $labels;
    }

    /** @return array<int, string> the roles the current user may give */
    public static function assignableRoles(): array
    {
        return array_filter(self::roles(), static fn (int $role): bool => Auth::canAssignRole($role), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<int, string> */
    public static function statuses(): array
    {
        return [
            self::ACTIVE => AdminLang::get('users.status_active'),
            self::PENDING => AdminLang::get('users.status_pending'),
            self::SUSPENDED => AdminLang::get('users.status_suspended'),
        ];
    }

    public static function isCurrentUser(object $record): bool
    {
        $id = Session::get('user_id');

        return $id !== null && $id !== '' && (string) ($record->id ?? '') === (string) $id;
    }
}
