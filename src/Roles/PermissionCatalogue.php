<?php
declare(strict_types=1);

namespace FloCMS\Admin\Roles;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;

/**
 * Every permission the panel knows, grouped for the Roles page's grid: one row
 * per resource (View / Create / Edit / Delete), the media library, each settings
 * page, users, the panel's own pages, actions and widgets with their own
 * permission, and the site's names from config/admin.php ('roles' => ['permissions']).
 *
 * Built from the registered resources and settings pages, so a new resource
 * shows up without any code.
 *
 * @internal
 */
final class PermissionCatalogue
{
    public const ABILITIES = ['view', 'create', 'update', 'delete'];
    /** A resource's row: the four abilities, then CSV export and import (0.25). */
    public const CONTENT_ABILITIES = ['view', 'create', 'update', 'delete', 'export', 'import'];

    /**
     * @return list<array{key: string, title: string, help: string, wildcard: ?string, wildcardLabel: string, wildcardHelp: string, columns: list<string>,
     *     rows: list<array{label: string, sub: string, cells: list<array{name: string, ability: string, label: string}>}>,
     *     items: list<array{name: string, label: string, sub: string}>}>
     */
    public static function groups(): array
    {
        $groups = [];
        $prefix = Admin::config()->permissionPrefix();

        // Content: one row per resource
        $rows = [];
        foreach (Admin::registry()->all() as $class) {
            /** @var class-string<Resource> $class */
            $rows[] = [
                'label' => $class::getPluralLabel(),
                'sub' => self::common(array_map(static fn (string $ability): string => $class::permission($ability), self::CONTENT_ABILITIES)),
                'cells' => array_map(static fn (string $ability): array => [
                    'name' => $class::permission($ability), 'ability' => $ability,
                    'label' => AdminLang::get('roles.ability.' . $ability),
                ], self::CONTENT_ABILITIES),
            ];
        }
        if ($rows !== []) {
            $groups[] = self::group('content', AdminLang::get('roles.group.content'), AdminLang::get('roles.group.content_help'),
                $prefix . '.*', AdminLang::get('roles.all_content'), AdminLang::get('roles.all_content_help'),
                self::abilityLabels(), $rows, []);
        }

        // Media library
        if (Admin::config()->mediaLibrary()['enabled']) {
            $groups[] = self::group('media', AdminLang::get('media.title'), AdminLang::get('roles.group.media_help'), null, '', '',
                [AdminLang::get('roles.ability.view'), AdminLang::get('roles.ability.upload'), AdminLang::get('roles.ability.update'), AdminLang::get('roles.ability.delete')],
                [[
                    'label' => AdminLang::get('roles.media_library'),
                    'sub' => $prefix . '.media.*',
                    'cells' => array_map(static fn (string $ability): array => [
                        'name' => \FloCMS\Admin\Media\MediaLibrary::permission($ability), 'ability' => $ability,
                        'label' => AdminLang::get($ability === 'create' ? 'roles.ability.upload' : 'roles.ability.' . $ability),
                    ], self::ABILITIES),
                ]], []);
        }

        // Settings pages (pages sharing a permission share a box)
        $items = [];
        foreach (Admin::settings()->all() as $page) {
            $name = $page->getPermission();
            if (isset($items[$name])) {
                $items[$name]['label'] .= AdminLang::get('form.list_separator') . strip_tags($page->getLabel());
                continue;
            }
            $items[$name] = ['name' => $name, 'label' => strip_tags($page->getLabel()), 'sub' => (string) ($page->getDescription() ?? '')];
        }
        if ($items !== []) {
            $groups[] = self::group('settings', AdminLang::get('settings.title'), AdminLang::get('roles.group.settings_help'), 'settings.*',
                AdminLang::get('roles.all_settings'), AdminLang::get('roles.all_settings_help'), [], [], array_values($items));
        }

        // Users
        if (Admin::config()->usersEnabled()) {
            $groups[] = self::group('users', AdminLang::get('nav.users'), AdminLang::get('roles.group.users_help'), null, '', '', [], [], [
                ['name' => 'users.manage', 'label' => AdminLang::get('roles.perm.users_manage'), 'sub' => AdminLang::get('roles.perm.users_manage_help')],
                ['name' => 'users.assign_role', 'label' => AdminLang::get('roles.perm.users_assign_role'), 'sub' => AdminLang::get('roles.perm.users_assign_role_help')],
                ['name' => 'users.manage_any', 'label' => AdminLang::get('roles.perm.users_manage_any'), 'sub' => AdminLang::get('roles.perm.users_manage_any_help')],
            ]);
        }

        // The panel's own pages
        $system = [];
        if (Admin::config()->activity()['enabled']) {
            $system[] = ['name' => \FloCMS\Admin\Activity\Activity::PERMISSION, 'label' => AdminLang::get('roles.perm.activity_view'), 'sub' => AdminLang::get('roles.perm.activity_view_help')];
        }
        $system[] = ['name' => Roles::PERMISSION, 'label' => AdminLang::get('roles.perm.roles_manage'), 'sub' => AdminLang::get('roles.perm.roles_manage_help')];
        $groups[] = self::group('system', AdminLang::get('roles.group.system'), AdminLang::get('roles.group.system_help'), null, '', '', [], [], $system);

        // Actions and widgets with their own permission
        $known = self::names($groups);
        $own = [];
        foreach (self::actionPermissions() + self::widgetPermissions() as $name => $item) {
            if (!isset($known[$name])) { $own[$name] = $item; }
        }
        if ($own !== []) {
            $groups[] = self::group('actions', AdminLang::get('roles.group.actions'), AdminLang::get('roles.group.actions_help'), null, '', '', [], [], array_values($own));
        }

        // The site's own names (config/admin.php)
        $known = self::names($groups);
        $site = [];
        foreach (Admin::config()->roles()['permissions'] as $name => $label) {
            if (!isset($known[$name])) { $site[] = ['name' => $name, 'label' => AdminLang::label($label), 'sub' => '']; }
        }
        if ($site !== []) {
            $groups[] = self::group('site', AdminLang::get('nav.site'), AdminLang::get('roles.group.site_help'), null, '', '', [], [], $site);
        }

        return $groups;
    }

    /**
     * Every name the grid shows, as name => true.
     *
     * @param list<array<string, mixed>>|null $groups
     * @return array<string, true>
     */
    public static function names(?array $groups = null): array
    {
        $names = [];
        foreach ($groups ?? self::groups() as $group) {
            foreach ($group['rows'] as $row) { foreach ($row['cells'] as $cell) { $names[$cell['name']] = true; } }
            foreach ($group['items'] as $item) { $names[$item['name']] = true; }
        }
        return $names;
    }

    /**
     * The group switches ("All content"): wildcard => true.
     *
     * @param list<array<string, mixed>>|null $groups
     * @return array<string, true>
     */
    public static function wildcards(?array $groups = null): array
    {
        $wildcards = [];
        foreach ($groups ?? self::groups() as $group) {
            if ($group['wildcard'] !== null) { $wildcards[$group['wildcard']] = true; }
        }
        return $wildcards;
    }

    /** A readable name for a permission or pattern ("Listings → Edit"), for the activity log. */
    public static function describe(string $name, ?array $groups = null): string
    {
        if ($name === '*') { return AdminLang::get('roles.everything'); }
        foreach ($groups ?? self::groups() as $group) {
            if ($group['wildcard'] === $name) { return $group['wildcardLabel']; }
            foreach ($group['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if ($cell['name'] === $name) { return $row['label'] . ' → ' . $cell['label']; }
                }
            }
            foreach ($group['items'] as $item) {
                if ($item['name'] === $name) { return $group['title'] . ' → ' . $item['label']; }
            }
        }
        return $name;
    }

    /** @return list<string> */
    private static function abilityLabels(): array
    {
        return array_map(static fn (string $ability): string => AdminLang::get('roles.ability.' . $ability), self::CONTENT_ABILITIES);
    }

    /** "content.posts.*" when the names share a prefix, else ''. */
    private static function common(array $names): string
    {
        $prefixes = array_unique(array_map(static fn (string $name): string => (string) preg_replace('/\.[^.]+$/', '', $name), $names));
        return count($prefixes) === 1 && reset($prefixes) !== '' ? reset($prefixes) . '.*' : '';
    }

    private static function group(string $key, string $title, string $help, ?string $wildcard, string $wildcardLabel, string $wildcardHelp, array $columns, array $rows, array $items): array
    {
        return compact('key', 'title', 'help', 'wildcard', 'wildcardLabel', 'wildcardHelp', 'columns', 'rows', 'items');
    }

    /** @return array<string, array{name: string, label: string, sub: string}> */
    private static function actionPermissions(): array
    {
        $found = [];
        foreach (Admin::registry()->all() as $class) {
            /** @var class-string<Resource> $class */
            try {
                $resource = new $class();
                $table = $resource->table(new \FloCMS\Admin\Table\Table());
                $actions = array_merge($table->getActions(), $table->getHeaderActions(), $table->getBulkActions(), $resource->headerActions());
            } catch (\Throwable) {
                continue;
            }
            foreach ($actions as $action) {
                if (!$action instanceof \FloCMS\Admin\Actions\Action) { continue; }
                $name = $action->getPermission();
                if ($name === null || !Roles::validPattern($name) || isset($found[$name])) { continue; }
                $found[$name] = ['name' => $name, 'label' => strip_tags($action->getLabel($resource)), 'sub' => AdminLang::get('roles.action_on', ['resource' => $class::getPluralLabel()])];
            }
        }
        return $found;
    }

    /** @return array<string, array{name: string, label: string, sub: string}> */
    private static function widgetPermissions(): array
    {
        $found = [];
        try {
            $widgets = \FloCMS\Admin\Widgets\WidgetRegistry::fromConfig(Admin::config())->all();
        } catch (\Throwable) {
            return [];
        }
        foreach ($widgets as $widget) {
            if (is_string($widget) && is_subclass_of($widget, \FloCMS\Admin\Widgets\Widget::class)) {
                try { $widget = new $widget(); } catch (\Throwable) { continue; }
            }
            if (!$widget instanceof \FloCMS\Admin\Widgets\Widget) { continue; }
            $name = $widget->permission();
            if ($name === null || !Roles::validPattern($name) || isset($found[$name])) { continue; }
            $found[$name] = ['name' => $name, 'label' => strip_tags($widget->title()), 'sub' => AdminLang::get('roles.widget')];
        }
        return $found;
    }
}
