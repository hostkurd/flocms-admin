<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Widgets\WidgetRegistry;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo admin:list [--permissions] — the resources and widgets the panel loads.
 */
final class ListCommand extends Command
{
    private const ABILITIES = ['view', 'create', 'update', 'delete'];

    protected string $name = 'admin:list';

    protected string $description = 'List the admin resources, settings pages and dashboard widgets with their URLs, tables and permissions';

    protected array $examples = [
        'php flo admin:list',
        'php flo admin:list --permissions',
    ];

    protected function configure(Definition $definition): void
    {
        $definition->option('permissions', 'p', Option::VALUE_NONE, 'Print only the permission names, ready to paste into a role');
    }

    protected function handle(): int
    {
        $project = $this->project();
        $project->boot();
        Admin::setRoot($project->root());
        Languages::reset();
        $config = Admin::config();
        if (!$config->isInstalled()) {
            $this->fail('config/admin.php not found: run php flo admin:install');
        }

        $registry = Admin::registry();
        $resources = $registry->all();
        if ($config->usersEnabled() && ($users = $registry->find('accounts')) !== null) {
            $resources['accounts'] = $users;
        }
        $widgets = WidgetRegistry::fromConfig($config);

        if ($this->option('permissions')) {
            return $this->permissions($resources, $widgets);
        }

        $this->title('Admin resources');
        if ($resources === []) {
            $this->note('No resources yet: php flo make:admin-resource Post --migration');
        } else {
            $rows = [];
            foreach ($resources as $slug => $class) {
                $rows[] = [
                    $class::getPluralLabel(),
                    '/admin/' . $slug,
                    $class::getTable(),
                    $class::permission('view') . ' (+create, update, delete)',
                    $registry->isBuiltIn($slug) ? 'built in' : $this->short($class),
                ];
            }
            $this->table(['Resource', 'URL', 'Table', 'Permissions', 'Class'], $rows);
        }

        $settings = Admin::settings();
        $this->section('Settings pages');
        if ($settings->all() === []) {
            $this->note('None: php flo make:admin-settings');
        } else {
            $rows = [];
            foreach ($settings->all() as $name => $page) {
                $rows[] = [$page->getLabel(), '/admin/settings/edit/' . $name, $name . '.*', (string) count($page->storedFields()), $page->getPermission()];
            }
            $this->table(['Page', 'URL', 'Keys', 'Fields', 'Permission'], $rows);
        }

        $this->section('Dashboard widgets');
        if (!$widgets->configured()) {
            $this->note("None: the dashboard shows resource counts. Create one with php flo make:admin-widget Overview");
        } else {
            $rows = [];
            foreach ($widgets->all() as $widget) {
                $type = match (true) {
                    $widget instanceof \FloCMS\Admin\Widgets\StatsWidget => 'stats',
                    $widget instanceof \FloCMS\Admin\Widgets\RecordsWidget => 'records',
                    default => 'chart',
                };
                $reads = array_map(fn (string $class): string => $this->short($class), $widget->resources());
                $rows[] = [$widget->title(), $type, $reads === [] ? '-' : implode(', ', $reads), (string) $widget->sort(), $this->short($widget::class)];
            }
            if ($rows !== []) {
                $this->table(['Widget', 'Type', 'Reads', 'Sort', 'Class'], $rows);
            }
        }

        $problems = array_merge($registry->problems(), array_map(static fn (string $p): string => 'Widget: ' . $p, $widgets->problems()), array_map(static fn (string $p): string => 'Settings: ' . $p, $settings->problems()));
        foreach ($problems as $problem) {
            $this->warn($problem);
        }
        if ($problems !== []) {
            $this->note('Run php flo admin:doctor for details.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, class-string> $resources */
    private function permissions(array $resources, WidgetRegistry $widgets): int
    {
        $names = [];
        foreach ($resources as $class) {
            foreach (self::ABILITIES as $ability) {
                $names[] = $class::permission($ability);
            }
        }
        foreach ($widgets->all() as $widget) {
            $permission = (fn (): ?string => $this->permission)->call($widget);
            if (is_string($permission) && $permission !== '') {
                $names[] = $permission;
            }
        }
        foreach (Admin::settings()->all() as $page) {
            $names[] = $page->getPermission();
        }
        $names = array_values(array_unique($names));
        $this->line("// Custom actions add <prefix>.<action> names, e.g. content.posts.publish.");
        $this->line('[');
        foreach ($names as $name) {
            $this->line("    '" . $name . "',");
        }
        $this->line(']');

        return self::SUCCESS;
    }

    private function short(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
