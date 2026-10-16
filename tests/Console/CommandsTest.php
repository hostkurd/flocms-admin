<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Console;

use FloCMS\Admin\Console\DoctorCommand;
use FloCMS\Admin\Console\InstallCommand;
use FloCMS\Admin\Console\ListCommand;
use FloCMS\Admin\Console\MakeResourceCommand;
use FloCMS\Admin\Console\MakeWidgetCommand;
use FloCMS\CLI\Console\Testing\CommandTester;
use FloCMS\CLI\Project;
use PHPUnit\Framework\TestCase;

final class CommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/flo-admin-cli-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function runCommand(object $command, array $arguments = []): CommandTester
    {
        $tester = new CommandTester($command, new Project($this->root));
        $tester->execute($arguments, [], ['interactive' => false, 'decorated' => false]);

        return $tester;
    }

    public function testInstallCreatesConfigAndFolderOnce(): void
    {
        $tester = $this->runCommand(new InstallCommand());

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->root . '/config/admin.php');
        self::assertFileExists($this->root . '/admin/Resources/.gitkeep');
        $config = require $this->root . '/config/admin.php';
        self::assertSame('admin/Resources', $config['resources_path']);
        self::assertSame('content', $config['permission_prefix']);

        file_put_contents($this->root . '/config/admin.php', '<?php return ["brand" => "Mine"];');
        $tester = $this->runCommand(new InstallCommand());
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertSame(['brand' => 'Mine'], require $this->root . '/config/admin.php', 'never overwritten');
    }

    public function testMakeResourceWritesAClassAndOptionallyAMigration(): void
    {
        $tester = $this->runCommand(new MakeResourceCommand(), ['Property', '--migration']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $file = $this->root . '/admin/Resources/PropertyResource.php';
        self::assertFileExists($file);
        $code = (string) file_get_contents($file);
        self::assertStringContainsString('namespace App\\Admin\\Resources;', $code);
        self::assertStringContainsString("protected static string \$table = 'properties';", $code);
        self::assertStringContainsString("protected static string \$slug = 'properties';", $code);
        self::assertStringContainsString('protected static bool $timestamps = true;', $code);
        self::assertStringContainsString("DateColumn::make('updated_at')", $code);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $out, $lint);
        self::assertSame(0, $lint, implode("\n", $out));

        $migrations = glob($this->root . '/database/migrations/*_create_properties_table.php');
        self::assertCount(1, $migrations);
        self::assertStringContainsString('`title` \' . $json . \' NULL', (string) file_get_contents($migrations[0]));

        $again = $this->runCommand(new MakeResourceCommand(), ['Property']);
        self::assertSame(1, $again->getStatusCode(), 'existing file is not overwritten');
    }

    public function testMakeResourceOptions(): void
    {
        $tester = $this->runCommand(new MakeResourceCommand(), ['BlogPost', '--table=posts', '--slug=blog']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $code = (string) file_get_contents($this->root . '/admin/Resources/BlogPostResource.php');
        self::assertStringContainsString("\$table = 'posts'", $code);
        self::assertStringContainsString("\$slug = 'blog'", $code);
        self::assertStringContainsString('protected static bool $timestamps = false;', $code);
        self::assertStringNotContainsString('DateColumn', $code);
        self::assertSame([], glob($this->root . '/database/migrations/*') ?: []);
    }

    public function testMakeResourceRefusesBadNames(): void
    {
        self::assertNotSame(0, $this->runCommand(new MakeResourceCommand(), ['property'])->getStatusCode());
        self::assertNotSame(0, $this->runCommand(new MakeResourceCommand(), ['Property', '--slug=Bad Slug'])->getStatusCode());
    }

    /**
     * Project::boot() defines ROOT, so the doctor runs in its own process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDoctorReportsMissingTablesAndColumns(): void
    {
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\nLANGUAGES=en,ar\nDEFAULT_LANG=en\n");
        file_put_contents($this->root . '/config/config.php', "<?php\n\\FloCMS\\Core\\Config::set('languages', ['en', 'ar']);\n\\FloCMS\\Core\\Config::set('default_language', 'en');\n");
        mkdir($this->root . '/storage');
        mkdir($this->root . '/templates/default/layouts', 0777, true);
        file_put_contents($this->root . '/templates/default/layouts/admin.html', '<meta name="viewport" content="width=device-width"><?= $data[\'content\'] ?>');
        $this->runCommand(new InstallCommand());
        $this->runCommand(new MakeResourceCommand(), ['Property']);

        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        $pdo->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, created_at TEXT)');

        $tester = $this->runCommand(new DoctorCommand());
        $display = $tester->getDisplay();

        self::assertSame(1, $tester->getStatusCode(), $display);
        self::assertStringContainsString('Languages: en, ar (default en)', $display);
        self::assertStringContainsString('Admin layout: templates/default/layouts/admin.html', $display);
        self::assertStringContainsString('content.properties.view', $display);
        self::assertStringContainsString('Column "title" (form field) is missing', $display);
        self::assertStringContainsString('auth.login_view presentation hook', $display);

        self::assertStringContainsString('Table "users" not found', $display, 'users management is on by default');
        self::assertStringContainsString('Table "settings" not found: run php flo admin:install, then php flo migrate', $display);
        self::assertStringContainsString('Table "admin_user_preferences" not found', $display);
        self::assertStringContainsString('/admin shows the skeleton\'s page', $display, 'skeleton before 1.8: no admin.home');

        $pdo->exec('ALTER TABLE properties ADD COLUMN title TEXT');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, email TEXT, login TEXT, password TEXT,
            role INTEGER, status INTEGER, phone TEXT, is_verified INTEGER, verify_type INTEGER, token TEXT)');
        $pdo->exec('CREATE TABLE settings (`key` VARCHAR(190) PRIMARY KEY, `value` TEXT NULL, `updated_at` DATETIME NULL)');
        $pdo->exec('CREATE TABLE admin_user_preferences (user_id INTEGER, `key` VARCHAR(100), `value` TEXT NULL, updated_at DATETIME NULL, PRIMARY KEY (user_id, `key`))');
        // Skeleton 1.8+: the /admin page follows the admin.home setting
        mkdir($this->root . '/controllers');
        file_put_contents($this->root . '/controllers/PagesController.php', "<?php // reads Config::get('admin.home')\n");
        mkdir($this->root . '/views/users', 0777, true);
        file_put_contents($this->root . '/views/users/admin_login.html', "<?php // optional Config::get('auth.login_view')\n");
        $tester = $this->runCommand(new DoctorCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Everything looks good.', $tester->getDisplay());

        // admin:list shows the resource, the built-in accounts and the permission names.
        $list = $this->runCommand(new ListCommand());
        self::assertSame(0, $list->getStatusCode(), $list->getDisplay() . $list->getErrorOutput());
        self::assertMatchesRegularExpression('~/admin/properties\s+.*properties\s+.*content\.properties\.view~', $list->getDisplay());
        self::assertStringContainsString('built in', $list->getDisplay());
        self::assertStringContainsString('make:admin-widget', $list->getDisplay());
        $permissions = $this->runCommand(new ListCommand(), ['--permissions'])->getDisplay();
        self::assertStringContainsString("    'content.properties.view',\n    'content.properties.create',", $permissions);

        // Configuration diagnostics must not invoke a widget's provider.
        file_put_contents($this->root . '/config/admin.php', '<?php return ["widgets" => [\\FloCMS\\Admin\\Tests\\Fixtures\\Widgets\\BrokenStats::class, "MissingWidget"]];');
        $tester = $this->runCommand(new DoctorCommand());
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Widget: MissingWidget: class not found: it is outside widgets_namespace', $tester->getDisplay());

        // A file with the wrong name (ListingStats.php.txt) is named in the message.
        mkdir($this->root . '/admin/Widgets', 0777, true);
        file_put_contents($this->root . '/admin/Widgets/ListingStats.php.txt', '<?php');
        file_put_contents($this->root . '/config/admin.php', '<?php return ["widgets" => [App\\Admin\\Widgets\\ListingStats::class, App\\Admin\\Widgets\\Gone::class]];');
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('ListingStats: class not found: expected the file admin/Widgets/ListingStats.php (found ListingStats.php.txt: the file must be named ListingStats.php)', $display);
        self::assertStringContainsString('Gone: class not found: expected the file admin/Widgets/Gone.php (create it with: php flo make:admin-widget Gone)', $display);
        self::assertStringNotContainsString('private-dsn-sensitive', $tester->getDisplay());
    }

    public function testInstallWritesTheSettingsAndPreferencesMigrationsOnce(): void
    {
        file_put_contents($this->root . '/config/admin.php', "<?php return ['settings_table' => 'site_settings'];");
        $tester = $this->runCommand(new InstallCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $settings = glob($this->root . '/database/migrations/*_create_site_settings_table.php');
        $preferences = glob($this->root . '/database/migrations/*_create_admin_user_preferences_table.php');
        self::assertCount(1, $settings);
        self::assertCount(1, $preferences);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `site_settings`', (string) file_get_contents($settings[0]));
        $this->lint($settings[0]);
        $this->lint($preferences[0]);

        $again = $this->runCommand(new InstallCommand());
        self::assertStringContainsString('A migration for "site_settings" already exists', $again->getDisplay());
        self::assertCount(1, glob($this->root . '/database/migrations/*_create_site_settings_table.php'));
    }

    public function testMakeSettingsWritesTheFileOnce(): void
    {
        file_put_contents($this->root . '/config/admin.php', '<?php return [];');
        $tester = $this->runCommand(new \FloCMS\Admin\Console\MakeSettingsCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $file = $this->root . '/admin/settings.php';
        self::assertFileExists($file);
        $this->lint($file);
        $pages = require $file;
        self::assertSame(['site', 'contact'], array_map(static fn ($page): string => $page->getName(), $pages));

        file_put_contents($file, '<?php return [];');
        $again = $this->runCommand(new \FloCMS\Admin\Console\MakeSettingsCommand());
        self::assertStringContainsString('already exists', $again->getDisplay());
        self::assertSame('<?php return [];', file_get_contents($file));
    }

    public function testDoctorFlagsAForeignSettingsTableAndUndeclaredKeys(): void
    {
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        mkdir($this->root . '/storage');
        mkdir($this->root . '/admin', 0777, true);
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false];");
        file_put_contents($this->root . '/admin/settings.php', "<?php return [\\FloCMS\\Admin\\Settings\\SettingsPage::make('site')->schema([\\FloCMS\\Admin\\Form\\Fields\\TextInput::make('title')])];");
        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, name TEXT, val TEXT)');
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString("is not a flocms-admin settings table", $display);
        self::assertStringContainsString("'settings_table' => 'site_settings'", $display);
        self::assertStringContainsString('Settings pages: site (settings.manage)', $display);

        $pdo->exec('DROP TABLE settings');
        $pdo->exec('CREATE TABLE settings (`key` VARCHAR(190) PRIMARY KEY, `value` TEXT NULL, `updated_at` DATETIME NULL)');
        $pdo->exec("INSERT INTO settings (`key`, `value`) VALUES ('site.title', '\"A\"'), ('old.thing', '\"B\"')");
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('1 stored setting(s) no longer declared: old.thing', $display);

        $list = $this->runCommand(new ListCommand())->getDisplay();
        self::assertMatchesRegularExpression('~/admin/settings/edit/site[\s|]+site\.\*[\s|]+1[\s|]+settings\.manage~', $list);
        self::assertStringContainsString('settings.panel', $list);
        self::assertStringContainsString("'settings.manage',", $this->runCommand(new ListCommand(), ['--permissions'])->getDisplay());
    }

    private function lint(string $file): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $out, $lint);
        self::assertSame(0, $lint, implode("\n", $out));
    }

    public function testMakeWidgetWritesEachTypeAndRegistersIt(): void
    {
        $this->runCommand(new InstallCommand());
        $tester = $this->runCommand(new MakeWidgetCommand(), ['PostStats', '--resource=Post']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $code = (string) file_get_contents($this->root . '/admin/Widgets/PostStats.php');
        self::assertStringContainsString('namespace App\Admin\Widgets;', $code);
        self::assertStringContainsString('use App\Admin\Resources\PostResource;', $code);
        self::assertStringContainsString('final class PostStats extends StatsWidget', $code);
        self::assertStringContainsString('protected array $resources = [PostResource::class];', $code);
        self::assertStringContainsString("Stat::make('Total', \$records->count())->resource(PostResource::class)", $code);
        $this->lint($this->root . '/admin/Widgets/PostStats.php');
        self::assertStringContainsString('admin/Resources/PostResource.php was not found', $tester->getErrorOutput());
        self::assertStringContainsString('Registered PostStats in config/admin.php', $tester->getDisplay());

        $this->runCommand(new MakeWidgetCommand(), ['RecentPosts', '--type=records', '--resource=PostResource']);
        $this->runCommand(new MakeWidgetCommand(), ['Visits', '--type=chart']);
        $records = (string) file_get_contents($this->root . '/admin/Widgets/RecentPosts.php');
        self::assertStringContainsString('protected string $resource = PostResource::class;', $records);
        $chart = (string) file_get_contents($this->root . '/admin/Widgets/Visits.php');
        self::assertStringContainsString('protected array $resources = [];', $chart);
        self::assertStringNotContainsString('use App\Admin\Resources', $chart);
        $this->lint($this->root . '/admin/Widgets/RecentPosts.php');
        $this->lint($this->root . '/admin/Widgets/Visits.php');

        $this->lint($this->root . '/config/admin.php');
        $config = require $this->root . '/config/admin.php';
        self::assertSame(['App\Admin\Widgets\PostStats', 'App\Admin\Widgets\RecentPosts', 'App\Admin\Widgets\Visits'], $config['widgets']);

        // Once only, and an existing file is kept.
        $again = $this->runCommand(new MakeWidgetCommand(), ['PostStats', '--force']);
        self::assertStringContainsString('already registered', $again->getDisplay());
        self::assertSame(3, count((require $this->root . '/config/admin.php')['widgets']));
        self::assertSame(1, $this->runCommand(new MakeWidgetCommand(), ['Visits'])->getStatusCode());
    }

    public function testMakeWidgetRegistersInListsWrittenByHand(): void
    {
        foreach (["[]", "[\n        \\App\\Admin\\Widgets\\One::class\n    ]"] as $list) {
            file_put_contents($this->root . '/config/admin.php', "<?php\nreturn [\n    'widgets' => " . $list . ",\n    'widgets_path' => 'admin/Widgets',\n];\n");
            $tester = $this->runCommand(new MakeWidgetCommand(), ['Two', '--force']);
            self::assertStringContainsString('Registered Two', $tester->getDisplay(), $list);
            $this->lint($this->root . '/config/admin.php');
            self::assertContains('App\Admin\Widgets\Two', (require $this->root . '/config/admin.php')['widgets']);
            self::assertSame('admin/Widgets', (require $this->root . '/config/admin.php')['widgets_path']);
        }
        // A list it cannot read: it says what to add instead of guessing.
        file_put_contents($this->root . '/config/admin.php', "<?php\nreturn ['widgets' => array_merge([], [])];\n");
        $tester = $this->runCommand(new MakeWidgetCommand(), ['Three']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("Add it to config/admin.php", $tester->getDisplay());
    }

    public function testMakeWidgetRefusesBadInput(): void
    {
        self::assertNotSame(0, $this->runCommand(new MakeWidgetCommand(), ['post-stats'])->getStatusCode());
        self::assertNotSame(0, $this->runCommand(new MakeWidgetCommand(), ['Stats', '--type=pie'])->getStatusCode());
        $records = $this->runCommand(new MakeWidgetCommand(), ['Recent', '--type=records']);
        self::assertNotSame(0, $records->getStatusCode());
        self::assertStringContainsString('add --resource=Post', $records->getErrorOutput());
        self::assertNotSame(0, $this->runCommand(new MakeWidgetCommand(), ['Stats', '--resource=../x'])->getStatusCode());
        self::assertDirectoryDoesNotExist($this->root . '/admin/Widgets');
    }
}
