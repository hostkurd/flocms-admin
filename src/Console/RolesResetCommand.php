<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Roles\Roles;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;

/**
 * php flo admin:roles-reset [role] — undo the Roles page: one role, or all of
 * them. Config roles get their config/config.php values back; roles added on
 * the page are removed when no user holds them.
 */
final class RolesResetCommand extends Command
{
    protected string $name = 'admin:roles-reset';

    protected string $description = 'Give config roles their config values back (one role, or all)';

    protected string $help = 'Without a role number, every change made on the Roles page is undone (asks first). Roles added on the page are kept while users hold them: move those users first. Without shell access, \'roles\' => false in config/admin.php has the same effect until switched on again.';

    protected function configure(Definition $definition): void
    {
        $definition->argument('role', Argument::OPTIONAL, 'The role number (default: all roles)');
    }

    protected function handle(): int
    {
        $project = $this->project();
        $project->boot();
        Admin::setRoot($project->root());
        try {
            \FloCMS\Core\App::$db = $project->database();
        } catch (\Throwable $error) {
            $this->fail('No database: ' . $error->getMessage());
        }
        if (!Roles::enabled()) {
            $this->note('The Roles page is not in use (off, unsupported or no table): config/config.php already applies.');
            return self::SUCCESS;
        }
        $store = Roles::store();
        $rows = $store->rows();
        $argument = $this->argument('role');
        if ($argument !== null && $argument !== '') {
            if (!is_numeric($argument) || !isset($rows[(int) $argument])) {
                $this->fail('Role ' . $argument . ' has no changes on the Roles page.');
            }
            $rows = [(int) $argument => $rows[(int) $argument]];
        } elseif ($rows === []) {
            $this->note('No changes on the Roles page: config/config.php applies.');
            return self::SUCCESS;
        } elseif (!$this->confirm('Undo every change of the Roles page (' . count($rows) . ' role(s))?')) {
            $this->note('Nothing was changed.');
            return self::SUCCESS;
        }

        $config = Roles::configRoles();
        $counts = Roles::userCounts();
        foreach (array_keys($rows) as $number) {
            if (!isset($config[$number]) && ($counts === null || ($counts[$number] ?? 0) > 0)) {
                $this->warn($counts === null
                    ? 'Role ' . $number . ' was added on the Roles page and the users table could not be read: kept.'
                    : 'Role ' . $number . ' was added on the Roles page and ' . $counts[$number] . ' user(s) hold it: kept. Move them to another role first.');
                continue;
            }
            $store->delete($number);
            \FloCMS\Admin\Activity\Activity::record('role_reset', 'roles', (string) $number, isset($config[$number]) ? $config[$number]->label() : '#' . $number, meta: ['command' => 'admin:roles-reset']);
            $this->success(isset($config[$number]) ? 'Role ' . $number . ' has its config values again.' : 'Role ' . $number . ' removed.');
        }
        Roles::refresh();
        return self::SUCCESS;
    }
}
