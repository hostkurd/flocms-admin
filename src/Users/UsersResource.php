<?php
declare(strict_types=1);

namespace FloCMS\Admin\Users;

use FloCMS\Admin\Form\Fields\ImageUpload;
use FloCMS\Admin\Form\Fields\Placeholder;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Table\Columns\BadgeColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Filters\SelectFilter;
use FloCMS\Admin\Table\Filters\TabsFilter;
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
        // 0.28: the person's details in the main column; photo, access and quick actions in the sidebar.
        return $form->schema([
            Section::make('profile')->label(AdminLang::get('users.section_profile'))->schema([
                TextInput::make('full_name')->label(AdminLang::get('users.full_name'))->rules('required|max:190'),
                TextInput::make('email')->email()->label(AdminLang::get('users.email'))->rules('required|max:190')->unique(),
                TextInput::make('login')->label(AdminLang::get('users.login'))->rules('max:190')->unique()
                    ->help(AdminLang::get('users.login_help')),
                TextInput::make('phone')->tel()->label(AdminLang::get('users.phone'))->rules('max:50'),
                ...(self::hasColumn('bio') ? [Textarea::make('bio')->label(AdminLang::get('users.bio'))->rows(4)->rules('max:2000')->nullable()->columnSpan('full')->help(AdminLang::get('users.bio_help'))] : []),
            ]),
            Section::make('security')->label(AdminLang::get('users.section_password'))->schema([
                TextInput::make('password')->password()->label(AdminLang::get('users.password'))
                    ->rules('min:' . self::MIN_PASSWORD)->requiredOnCreate()->generate()
                    ->help(AdminLang::get('users.password_help'))->columnSpan('full'),
            ]),
        ])->aside([
            // The photo (0.28): public, a 256 px square; without one the list shows the first letter.
            ...(self::hasImageColumn() ? [Section::make('photo')->label(AdminLang::get('users.photo'))->columns(1)->schema([
                ImageUpload::make('image')->label(AdminLang::get('users.photo'))->avatar()->nullable(false)->help(AdminLang::get('users.photo_help')),
            ])] : []),
            Section::make('access')->label(AdminLang::get('users.section_access'))->columns(1)->schema([
                Select::make('role')->label(AdminLang::get('users.role'))->options(fn (): array => self::assignableRoles())->required(),
                Select::make('status')->label(AdminLang::get('users.status'))->options(fn (): array => self::statuses())->required()
                    ->default((string) self::ACTIVE),
            ]),
            // On an existing user: Send reset link, Send email, Activate, Suspend (greyed out with the reason when they don't apply).
            Section::make('quick')->label(AdminLang::get('actions.quick'))->columns(1)->schema([
                Placeholder::make('quick_actions')->html(fn (?object $record): string => $record === null || !isset($record->id) ? ''
                    : (new \FloCMS\Admin\Actions\ActionRenderer($this, new \FloCMS\Admin\Actions\ActionRegistry($this)))->links($record, Admin::url(static::getSlug() . '/edit/' . $record->id))),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        // 0.28: one User column (photo or letter, name, email), status tabs with counts,
        // a role filter, and Suspend / Activate / Reset link / Send email per row and in bulk.
        return $table
            ->columns([
                TextColumn::make('full_name')->label(AdminLang::get('users.user'))->avatar('image')
                    ->description('email')->descriptionMark(static fn (object $user): bool => (int) ($user->is_verified ?? 0) === 1, AdminLang::get('users.email_verified'))
                    ->searchable()->searchAlso(['email', 'login', 'phone'])->sortable(),
                BadgeColumn::make('role')->label(AdminLang::get('users.role'))->labels(self::roles())
                    ->colors(array_map(static fn (int $role): string => $role >= 2 ? 'blue' : ($role === 1 ? 'purple' : 'gray'), array_combine(array_keys(self::roles()), array_keys(self::roles())))),
                BadgeColumn::make('status')->label(AdminLang::get('users.status'))->labels(self::statuses())->dot()
                    ->colors([self::ACTIVE => 'green', self::PENDING => 'yellow', self::SUSPENDED => 'red']),
                TextColumn::make('phone')->label(AdminLang::get('users.phone'))->toggleable(hiddenByDefault: true),
                TextColumn::make('login')->label(AdminLang::get('users.login'))->toggleable(hiddenByDefault: true),
            ])
            ->tabs(TabsFilter::make('status')->label(AdminLang::get('users.status'))->options(array_map('strval', self::statuses())))
            ->filters([SelectFilter::make('role')->label(AdminLang::get('users.role'))->options(array_map('strval', self::roles()))])
            ->actions(UserActions::row())
            ->bulkActions(UserActions::bulk())
            ->defaultSort('id', 'desc');
    }

    /** "Email users" beside New user (0.28), for people who may send emails. */
    public function headerActions(): array
    {
        return [
            \FloCMS\Admin\Actions\Action::make('email_users')->label(AdminLang::get('users.email_users'))->icon('mail')->permission('users.mailing')
                ->url(static fn (?object $record): string => Admin::url('mailings/compose')),
            \FloCMS\Admin\Actions\CreateAction::make(),
        ];
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

    /** @var array<string, bool> */
    private static array $columns = [];

    /** Whether the users table has the skeleton's `image` column (for the photo, 0.28). */
    public static function hasImageColumn(): bool { return self::hasColumn('image'); }

    /** Whether the site's users table has this column ('image', 'bio' since admin:install 0.28). */
    public static function hasColumn(string $column): bool
    {
        if (!in_array($column, ['image', 'bio'], true)) { return false; }
        if (isset(self::$columns[$column])) { return self::$columns[$column]; }
        try { \FloCMS\Core\App::db()?->query('SELECT `' . $column . '` FROM `users` WHERE 1 = 0'); return self::$columns[$column] = \FloCMS\Core\App::db() !== null; }
        catch (\Throwable) { return self::$columns[$column] = false; }
    }

    public static function isCurrentUser(object $record): bool
    {
        $id = Session::get('user_id');

        return $id !== null && $id !== '' && (string) ($record->id ?? '') === (string) $id;
    }
}
