<?php
declare(strict_types=1);

namespace FloCMS\Admin\Roles;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Validation\FormValidator;
use FloCMS\Core\App;
use FloCMS\Core\Auth;

/**
 * Creating, changing, resetting and deleting roles, with the safety rules of the
 * Roles page. Works without HTTP so it can be tested on its own; the server
 * enforces every rule, the page only hides what would be refused.
 *
 * 1. The top role (the highest-level role holding "*") is locked.
 * 2. Only roles below your own level can be changed; your own role never.
 * 3. Nobody gives or takes away a permission they don't hold themselves.
 * 4. A role's level stays below your own.
 * 5. At least one active user keeps panel access and roles.manage.
 *
 * @internal
 */
final class RoleEditor
{
    /** The Name, Level and Panel access fields (the aside of the page). */
    public static function form(): Form
    {
        $own = Roles::ownLevel() ?? 0;
        return (new Form())->schema([
            TextInput::make('name')->label(AdminLang::get('roles.name'))->translatable()->rules('required|max:60'),
            TextInput::make('level')->numeric()->label(AdminLang::get('roles.level'))->rules('required|integer|min:0|max:' . max(0, $own - 1))
                ->help(AdminLang::get('roles.level_help', ['level' => $own])),
            Toggle::make('admin_access')->label(AdminLang::get('roles.access_label'))->help(AdminLang::get('roles.access_help')),
        ]);
    }

    /** @return array<string, string|array<string, string>> the fields' state for $role */
    public static function state(Role $role): array
    {
        return ['name' => $role->names(), 'level' => (string) $role->level, 'admin_access' => $role->adminAccess ? '1' : '0'];
    }

    /** A new, empty role (not saved): the next free number, one level below yours, panel access on. */
    public static function blank(): Role
    {
        $own = Roles::ownLevel() ?? 1;
        return new Role(Roles::nextNumber() ?? 0, [], max(0, min(1, $own - 1)), true, [], Role::SOURCE_CUSTOM);
    }

    /**
     * Validate the submitted page; when valid, store it.
     *
     * @param array<string, mixed> $input
     * @return array{state: array<string, mixed>, permissions: list<string>, errors: array<string, string>, role: ?Role}
     */
    public static function save(?Role $before, array $input): array
    {
        $creating = $before === null;
        $current = $before ?? self::blank();
        $form = self::form();
        $state = [];
        foreach ($form->fields() as $name => $field) { $state[$name] = $field->stateFromInput($input, Languages::all()); }
        $errors = (new FormValidator(Languages::all(), Languages::default(), null, !$creating))->validate($form, $state);

        $permissions = self::permissionsFrom($current, $input);
        $level = (int) (is_string($state['level']) ? $state['level'] : $current->level);
        $access = $state['admin_access'] === '1';
        $names = array_filter(array_map('strval', is_array($state['name']) ? $state['name'] : []), static fn (string $v): bool => trim($v) !== '');

        if ($creating && !Roles::canCreate()) { $errors['_form'] = AdminLang::get('roles.cannot_create'); }
        if (!$creating && !Roles::canEdit($current)) {
            $errors['_form'] = Roles::isLocked($current) ? AdminLang::get('roles.locked_help') : AdminLang::get('roles.cannot_edit');
        }

        $after = $current->with(name: $names !== [] ? $names : null, level: $level, adminAccess: $access, permissions: $permissions,
            source: $creating ? Role::SOURCE_CUSTOM : ($current->fromConfig() ? Role::SOURCE_CHANGED : Role::SOURCE_CUSTOM));

        if ($errors === []) {
            $roles = Roles::all();
            $roles[$after->number] = $after;
            if (Roles::someoneCanManage($roles) === false) { $errors['_form'] = AdminLang::get('roles.lockout'); }
        }

        $result = ['state' => $state, 'permissions' => $permissions, 'errors' => $errors, 'role' => null];
        if ($errors !== []) { return $result; }

        $config = Roles::configRoles()[$after->number] ?? null;
        if (!$creating && $config !== null && self::sameAs($after, $config)) {
            // Saved back to exactly the config values: the config applies again.
            Roles::store()->delete($after->number);
            $after = $config;
        } else {
            Roles::store()->put($after);
        }
        self::log($creating ? 'role_created' : 'role_updated', $before, $after);
        Roles::refresh();
        $result['role'] = $after;
        return $result;
    }

    /**
     * Give a config role its config values again.
     *
     * @return ?string an error, or null when done
     */
    public static function reset(Role $role): ?string
    {
        if (!Roles::canReset($role)) { return AdminLang::get('roles.cannot_edit'); }
        $roles = Roles::all();
        $config = Roles::configRoles()[$role->number] ?? null;
        if ($config === null) { return AdminLang::get('roles.cannot_edit'); }
        $roles[$role->number] = $config;
        if (Roles::someoneCanManage($roles) === false) { return AdminLang::get('roles.lockout'); }
        Roles::store()->delete($role->number);
        self::log('role_reset', $role, $config);
        Roles::refresh();
        return null;
    }

    /**
     * Delete a role added in the panel; its users move to $moveTo first.
     *
     * @return ?string an error, or null when done
     */
    public static function delete(Role $role, ?int $moveTo): ?string
    {
        if (!Roles::canDelete($role)) { return AdminLang::get('roles.cannot_delete'); }
        $count = Roles::userCounts()[$role->number] ?? 0;
        $target = null;
        if ($count > 0) {
            $target = $moveTo !== null ? Roles::find($moveTo) : null;
            if ($target === null || $target->number === $role->number || !Auth::canAssignRole($target->number)) { return AdminLang::get('roles.move_invalid'); }
        }
        $roles = Roles::all();
        unset($roles[$role->number]);
        if (Roles::someoneCanManage($roles, $target !== null ? [$role->number => $target->number] : []) === false) { return AdminLang::get('roles.lockout'); }

        $db = App::db() ?? throw new \RuntimeException('No database connection.');
        $store = Roles::store($db);
        $db->transaction(static function () use ($db, $store, $role, $target): void {
            if ($target !== null) {
                $db->table(Roles::usersTable())->where('role', '=', $role->number)->update(['role' => $target->number]);
            }
            $store->delete($role->number);
        });
        self::log('role_deleted', $role, null, $target !== null ? ['moved' => $count, 'to' => $target->label()] : []);
        Roles::refresh();
        return null;
    }

    /** Whether $role holds exactly the values of the config role $config. */
    private static function sameAs(Role $role, Role $config): bool
    {
        $names = is_array($role->name) ? array_filter($role->name, static fn (string $v): bool => $v !== '') : [Languages::default() => $role->name];
        $sorted = static function (array $patterns): array { sort($patterns); return $patterns; };
        return $role->level === $config->level && $role->adminAccess === $config->adminAccess
            && $sorted($role->permissions) === $sorted($config->permissions)
            && $names === [Languages::default() => $config->label(Languages::default())];
    }

    /**
     * The role's patterns after the page was submitted. Patterns the current user
     * doesn't hold can't be given or taken away: they stay as they were.
     *
     * @param array<string, mixed> $input perm[] (ticked boxes, group switches, Other chips) and perm_add (new names)
     * @return list<string>
     */
    public static function permissionsFrom(Role $before, array $input): array
    {
        $submitted = is_array($input['perm'] ?? null) ? $input['perm'] : [];
        $added = preg_split('/[\s,]+/', is_string($input['perm_add'] ?? null) ? $input['perm_add'] : '') ?: [];
        $wanted = Roles::cleanPatterns(array_merge($submitted, $added));

        $groups = PermissionCatalogue::groups();
        // Ticking Create, Edit or Delete also gives View: the list is how a record is reached.
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $names = array_column($row['cells'], 'name', 'ability');
                $view = $names['view'] ?? null;
                if ($view === null || in_array($view, $wanted, true)) { continue; }
                foreach (['create', 'update', 'delete'] as $ability) {
                    if (isset($names[$ability]) && in_array($names[$ability], $wanted, true) && Roles::holds($view)) { $wanted[] = $view; break; }
                }
            }
        }

        $final = [];
        foreach ($before->permissions as $pattern) {
            if (!Roles::holds($pattern)) { $final[] = $pattern; }
        }
        foreach ($wanted as $pattern) {
            if (Roles::holds($pattern)) { $final[] = $pattern; }
        }
        $final = Roles::cleanPatterns($final);

        // A group switch covers its names: keep the wildcard only.
        $wildcards = array_values(array_filter($final, static fn (string $p): bool => str_contains($p, '*')));
        $final = array_values(array_filter($final, static function (string $p) use ($wildcards): bool {
            foreach ($wildcards as $wildcard) {
                if ($wildcard !== $p && Roles::matches($wildcard, $p)) { return false; }
            }
            return true;
        }));
        return $final;
    }

    /**
     * One activity entry: name, level and panel access before → after, then the
     * permissions given and taken away in words ("Listings → Edit").
     *
     * @param array<string, scalar> $meta
     */
    private static function log(string $action, ?Role $before, ?Role $after, array $meta = []): void
    {
        try {
            if (!Activity::enabled()) { return; }
            $role = $after ?? $before;
            if ($role === null) { return; }
            $groups = PermissionCatalogue::groups();
            $changes = [];
            $add = static function (string $field, string $label, string $old, string $new) use (&$changes): void {
                if ($old !== $new) { $changes[] = ['field' => $field, 'label' => $label, 'lang' => null, 'type' => 'value', 'before' => $old, 'after' => $new]; }
            };
            if ($action !== 'role_deleted') {
                $add('name', AdminLang::get('roles.name'), $before?->label() ?? '', (string) $after?->label());
                $add('level', AdminLang::get('roles.level'), $before !== null ? (string) $before->level : '', (string) $after?->level);
                $yes = AdminLang::get('roles.yes');
                $no = AdminLang::get('roles.no');
                $add('admin_access', AdminLang::get('roles.col.access'), $before !== null ? ($before->adminAccess ? $yes : $no) : '', $after?->adminAccess ? $yes : $no);
                $old = $before?->permissions ?? [];
                $new = $after?->permissions ?? [];
                $granted = array_map(static fn (string $p): string => PermissionCatalogue::describe($p, $groups), array_values(array_diff($new, $old)));
                $revoked = array_map(static fn (string $p): string => PermissionCatalogue::describe($p, $groups), array_values(array_diff($old, $new)));
                $separator = AdminLang::get('form.list_separator');
                $add('granted', AdminLang::get('roles.granted'), '', implode($separator, $granted));
                $add('revoked', AdminLang::get('roles.revoked'), implode($separator, $revoked), '');
            }
            $names = is_array($role->name) ? $role->name : [Languages::default() => $role->label()];
            $title = (string) \FloCMS\Admin\Translation\Translations::store()->encode($names);
            Activity::record($action, 'roles', (string) $role->number, $title, $changes, $meta);
        } catch (\Throwable $error) {
            error_log('flocms-admin: could not log the role change: ' . $error->getMessage());
        }
    }
}
