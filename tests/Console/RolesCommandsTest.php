<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Console;

use FloCMS\Admin\Console\DoctorCommand;
use FloCMS\Admin\Console\InstallCommand;
use FloCMS\Admin\Console\RolesCommand;
use FloCMS\Admin\Console\RolesResetCommand;
use FloCMS\CLI\Console\Testing\CommandTester;
use FloCMS\CLI\Project;
use PHPUnit\Framework\TestCase;

/**
 * admin:install writes the roles migration; admin:roles lists the roles in force;
 * admin:roles-reset undoes the Roles page; admin:doctor reports the table (0.21).
 */
final class RolesCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/flo-admin-roles-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/storage');
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        file_put_contents($this->root . '/config/config.php', "<?php\n"
            . "\\FloCMS\\Core\\Config::set('roles', [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin']);\n"
            . "\\FloCMS\\Core\\Config::set('permissions', [0 => [], 1 => ['content.*'], 2 => ['content.*', 'users.manage'], 3 => ['*']]);\n"
            . "\\FloCMS\\Core\\Config::set('admin_access_roles', ['1', '2', '3']);\n");
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false, 'media_library' => false, 'trash' => false, 'activity' => false];");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function runCommand(object $command, array $arguments = [], array $inputs = []): CommandTester
    {
        $tester = new CommandTester($command, new Project($this->root));
        $tester->execute($arguments, $inputs, ['interactive' => $inputs !== [], 'decorated' => false]);
        return $tester;
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testInstallListResetAndDoctor(): void
    {
        $before = $this->runCommand(new RolesCommand());
        self::assertSame(0, $before->getStatusCode(), $before->getDisplay());
        self::assertStringContainsString('The roles table is missing', $before->getDisplay());
        self::assertStringContainsString('Super Admin (locked)', $before->getDisplay());

        $this->runCommand(new InstallCommand());
        $migration = glob($this->root . '/database/migrations/*_create_admin_roles_table.php');
        self::assertCount(1, $migration);
        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        (require $migration[0])->up(new \FloCMS\Core\Database($pdo));
        $pdo->exec("INSERT INTO admin_roles (role, name, level, admin_access, permissions, created_at, updated_at) VALUES
            (1, '{\"en\":\"Writer\"}', 1, 1, '[\"content.posts.view\"]', '2026-10-18', '2026-10-18'),
            (4, '{\"en\":\"Author\"}', 1, 1, '[\"content.articles.*\"]', '2026-10-18', '2026-10-18')");

        $list = $this->runCommand(new RolesCommand())->getDisplay();
        self::assertStringContainsString('config/config.php with the changes of the Roles page', $list);
        self::assertMatchesRegularExpression('/Writer.*config, changed.*content\.posts\.view/', $list);
        self::assertMatchesRegularExpression('/Author.*Roles page.*content\.articles\.\*/', $list);

        $doctor = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Table "admin_roles": 1 config role(s) changed, 1 added in the panel', $doctor);

        $one = $this->runCommand(new RolesResetCommand(), ['1']);
        self::assertSame(0, $one->getStatusCode(), $one->getDisplay());
        self::assertStringContainsString('Role 1 has its config values again.', $one->getDisplay());
        self::assertSame(['4'], array_map('strval', $pdo->query('SELECT role FROM admin_roles')->fetchAll(\PDO::FETCH_COLUMN)));

        self::assertNotSame(0, $this->runCommand(new RolesResetCommand(), ['2'])->getStatusCode(), 'role 2 has no changes');

        $all = $this->runCommand(new RolesResetCommand(), [], ['yes']);
        self::assertStringContainsString('Role 4 removed.', $all->getDisplay());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM admin_roles')->fetchColumn());
    }
}
