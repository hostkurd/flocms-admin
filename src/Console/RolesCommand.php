<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Roles\Role;
use FloCMS\Admin\Roles\Roles;
use FloCMS\CLI\Console\Command;

/**
 * php flo admin:roles — the roles in force: config/config.php with the changes
 * and new roles of the Roles page (0.21).
 */
final class RolesCommand extends Command
{
    protected string $name = 'admin:roles';

    protected string $description = 'List the roles in force, with their level, panel access and permissions';

    protected string $help = 'Shows the roles of config/config.php with the changes and new roles made on the Roles page (/admin/roles). Undo changes with php flo admin:roles-reset.';

    protected function handle(): int
    {
        $project = $this->project();
        $project->boot();
        Admin::setRoot($project->root());
        try { \FloCMS\Core\App::$db = $project->database(); } catch (\Throwable) { /* config roles only */ }

        $status = Roles::status();
        $this->note(match ($status) {
            'ready' => 'Roles: config/config.php with the changes of the Roles page.',
            'missing' => 'The roles table is missing (php flo admin:install, php flo migrate): config/config.php applies.',
            'unsupported' => 'The Roles page needs flocms-core 2.4+ and the permissions and admin_access_roles maps in config/config.php: config/config.php applies.',
            default => 'The Roles page is off: config/config.php applies.',
        });
        $rows = [];
        foreach (Roles::all() as $role) {
            $rows[] = [
                (string) $role->number,
                $role->label(\FloCMS\Admin\Translation\Languages::default()) . (Roles::isLocked($role) ? ' (locked)' : ''),
                (string) $role->level,
                $role->adminAccess ? 'yes' : 'no',
                match ($role->source) { Role::SOURCE_CHANGED => 'config, changed', Role::SOURCE_CUSTOM => 'Roles page', default => 'config' },
                $role->permissions === [] ? '-' : implode(', ', $role->permissions),
            ];
        }
        $this->table(['Role', 'Name', 'Level', 'Panel', 'Source', 'Permissions'], $rows);
        return self::SUCCESS;
    }
}
