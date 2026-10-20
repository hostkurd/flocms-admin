<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Console;

use FloCMS\Admin\Console\DoctorCommand;
use FloCMS\Admin\Console\InstallCommand;
use FloCMS\Admin\Console\ListCommand;
use FloCMS\Admin\Console\MakeResourceCommand;
use FloCMS\Admin\Console\MakeWidgetCommand;
use FloCMS\Admin\Console\MakeBlockCommand;
use FloCMS\Admin\Console\BlocksCommand;
use FloCMS\Admin\Console\TrashPurgeCommand;
use FloCMS\Admin\Console\ActivityPurgeCommand;
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
        unset($_ENV['PRIMARY_EMAIL'], $_ENV['APP_URL'], $_ENV['APP_KEY'], $_ENV['MAIL_TRANSPORT'], $_ENV['APP_ENV']);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDoctorChecksThePages(): void
    {
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        mkdir($this->root . '/storage');
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false];");
        self::assertStringContainsString("Off: add 'pages' => true", $this->runCommand(new DoctorCommand())->getDisplay());

        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false, 'pages' => true];");
        self::assertStringContainsString('Table "admin_pages" not found', $this->runCommand(new DoctorCommand())->getDisplay());

        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        $file = $this->root . '/pages-migration.php';
        file_put_contents($file, \FloCMS\Admin\Console\Stub::render('pages-migration', ['table' => 'admin_pages']));
        (require $file)->up(new \FloCMS\Core\Database($pdo));
        $pdo->exec("INSERT INTO admin_pages (title, slug, status, is_home) VALUES ('{\"en\":\"Home\"}', '{\"en\":\"home\"}', 'published', 1), ('{\"en\":\"Login\"}', '{\"en\":\"login\"}', 'published', 0)");
        $pdo->exec("INSERT INTO admin_pages (title, slug, status, parent_id) VALUES ('{\"en\":\"Lost\"}', '{\"en\":\"lost\"}', 'published', 99)");
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Table "admin_pages": 3 pages, with a home page', $display);
        self::assertStringContainsString('Page "Lost" is under a page that was deleted', $display);
        self::assertStringContainsString('has the address /login, which the panel or the framework answers first', $display);
        self::assertStringContainsString('Page templates: default', $display);
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
        self::assertStringContainsString("\$formMode = 'page'", $code);
        self::assertStringContainsString("TextColumn::make('id')->sortable()->toggleable()", $code, 'new resources get a Columns menu');

        $tester = $this->runCommand(new MakeResourceCommand(), ['City', '--form=modal']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString("\$formMode = 'modal'", (string) file_get_contents($this->root . '/admin/Resources/CityResource.php'));

        $tester = $this->runCommand(new MakeResourceCommand(), ['Country', '--form=popup']);
        self::assertNotSame(0, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->root . '/admin/Resources/CountryResource.php');
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
        self::assertStringContainsString('Settings table "settings" not found: run php flo admin:install, then php flo migrate', $display);
        self::assertStringContainsString('Table "admin_user_preferences" not found', $display);
        self::assertStringContainsString('Table "media" not found: run php flo admin:install, then php flo migrate.', $display, 'the media library is on by default');
        self::assertStringContainsString('Table "admin_trash" not found: run php flo admin:install, then php flo migrate.', $display, 'the trash is on by default');
        self::assertStringContainsString('Table "admin_activity" not found', $display, 'the activity log is on by default');
        self::assertStringContainsString('/admin shows the skeleton\'s page', $display, 'skeleton before 1.8: no admin.home');

        $pdo->exec('ALTER TABLE properties ADD COLUMN title TEXT');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, email TEXT, login TEXT, password TEXT,
            role INTEGER, status INTEGER, phone TEXT, is_verified INTEGER, verify_type INTEGER, token TEXT)');
        $pdo->exec('CREATE TABLE settings (`param` VARCHAR(190) NOT NULL, `value` TEXT NULL, `lang` VARCHAR(10) NOT NULL DEFAULT \'\', PRIMARY KEY (`param`, `lang`))');
        $pdo->exec('CREATE TABLE admin_user_preferences (user_id INTEGER, `key` VARCHAR(100), `value` TEXT NULL, updated_at DATETIME NULL, PRIMARY KEY (user_id, `key`))');
        // The media library's migration, as admin:install wrote it
        $migration = glob($this->root . '/database/migrations/*_create_media_table.php');
        self::assertCount(1, $migration);
        (require $migration[0])->up(new \FloCMS\Core\Database($pdo));
        $upgrade = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('The media library needs its 0.18 migration (folders; "media" has no folder_id, hash, focal_x, focal_y)', $upgrade, 'only the 0.17 migration ran');
        // ... and the folders migration (0.18), written to run after it
        $folders = glob($this->root . '/database/migrations/*_create_media_folders_table.php');
        self::assertCount(1, $folders);
        self::assertGreaterThan(basename($migration[0]), basename($folders[0]), 'runs after the media table exists');
        (require $folders[0])->up(new \FloCMS\Core\Database($pdo));
        // The trash and the activity log (0.19): one migration for both tables
        $history = glob($this->root . '/database/migrations/*_create_admin_trash_and_admin_activity_tables.php');
        self::assertCount(1, $history);
        (require $history[0])->up(new \FloCMS\Core\Database($pdo));
        // CSV imports (0.25): one migration for the runs and their rows
        $imports = glob($this->root . '/database/migrations/*_create_admin_imports_and_admin_import_rows_tables.php');
        self::assertCount(1, $imports);
        (require $imports[0])->up(new \FloCMS\Core\Database($pdo));
        // Forgot password (0.26): the resets table, a sender address and the site's full address
        $resets = glob($this->root . '/database/migrations/*_create_admin_password_resets_table.php');
        self::assertCount(1, $resets);
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Table "admin_password_resets" not found', $display);
        self::assertStringContainsString('no sender address: set MAIL_FROM (or PRIMARY_EMAIL)', $display);
        self::assertStringContainsString('APP_URL in .env must be the full address', $display);
        (require $resets[0])->up(new \FloCMS\Core\Database($pdo));
        $_ENV['PRIMARY_EMAIL'] = 'info@example.test';
        $_ENV['APP_URL'] = 'https://example.test';
        $_ENV['APP_KEY'] = str_repeat('k', 40);
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
        self::assertMatchesRegularExpression('~/admin/media\s+.*media\s+.*content\.media\.view~', $list->getDisplay());
        self::assertStringContainsString("    'content.media.view',\n    'content.media.create',\n    'content.media.update',\n    'content.media.delete',", $permissions);

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
        $tester = $this->runCommand(new InstallCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $settings = glob($this->root . '/database/migrations/*_create_settings_table.php');
        $preferences = glob($this->root . '/database/migrations/*_create_admin_user_preferences_table.php');
        self::assertCount(1, $settings);
        self::assertCount(1, $preferences);
        $code = (string) file_get_contents($settings[0]);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `settings`', $code);
        self::assertStringContainsString('PRIMARY KEY (`param`, `lang`)', $code);
        self::assertStringNotContainsString('DROP TABLE', $code, 'the table is FloCMS\'s: rolling back never drops it');
        $this->lint($settings[0]);
        $this->lint($preferences[0]);

        $again = $this->runCommand(new InstallCommand());
        self::assertStringContainsString('A migration for "settings" already exists', $again->getDisplay());
        self::assertCount(1, glob($this->root . '/database/migrations/*_create_settings_table.php'));
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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDoctorChecksRelationManagers(): void
    {
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        mkdir($this->root . '/storage');
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false, 'resources' => [\\FloCMS\\Admin\\Tests\\Fixtures\\Managers\\EstateResource::class, \\FloCMS\\Admin\\Tests\\Fixtures\\Managers\\UnitResource::class]];");
        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        $pdo->exec('CREATE TABLE estates (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec('CREATE TABLE units (id INTEGER PRIMARY KEY, name TEXT, bedrooms TEXT, archived INTEGER)');
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Relation manager "units" (units by estate_id): table "units" has no column "estate_id"', $display);
        self::assertStringContainsString('Relation manager "enquiries" (enquiries by estate_id): FloCMS\Admin\Tests\Fixtures\Managers\EnquiryResource is not a registered resource', $display);
        self::assertStringContainsString('Not in the sidebar ($navigation = false)', $display);
        $pdo->exec('ALTER TABLE units ADD COLUMN estate_id INTEGER');
        self::assertStringContainsString('✓ Relation manager "units" (units by estate_id)', $this->runCommand(new DoctorCommand())->getDisplay());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDoctorFlagsAForeignSettingsTableAndUndeclaredKeys(): void
    {
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        mkdir($this->root . '/storage');
        mkdir($this->root . '/admin', 0777, true);
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false];");
        file_put_contents($this->root . '/admin/settings.php', "<?php return [\\FloCMS\\Admin\\Settings\\SettingsPage::make('site')->schema([\\FloCMS\\Admin\\Form\\Fields\\TextInput::make('title')])];");
        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, name TEXT, val TEXT)');
        $tester = $this->runCommand(new DoctorCommand());
        $display = $tester->getDisplay();
        self::assertStringContainsString("does not have the columns param, value and lang", $display, $tester->getErrorOutput());
        self::assertStringContainsString('Settings pages: site (settings.manage)', $display);

        $pdo->exec('DROP TABLE settings');
        $pdo->exec('CREATE TABLE settings (`param` VARCHAR(190) NOT NULL, `value` TEXT NULL, `lang` VARCHAR(10) NOT NULL DEFAULT \'\', PRIMARY KEY (`param`, `lang`))');
        $pdo->exec("INSERT INTO settings (`param`, `value`, `lang`) VALUES ('site.title', '\"A\"', ''), ('old.thing', '\"B\"', ''), ('offline_mode', '0', 'en')");
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('1 stored setting(s) no longer declared: old.thing', $display);

        $list = $this->runCommand(new ListCommand())->getDisplay();
        self::assertMatchesRegularExpression('~/admin/settings/edit/site[\s|]+site\.\*[\s|]+1[\s|]+settings\.manage~', $list);
        self::assertStringContainsString('settings.panel', $list);
        self::assertStringContainsString("'settings.manage',", $this->runCommand(new ListCommand(), ['--permissions'])->getDisplay());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDoctorChecksRelationsAndSuggestsForeignKeys(): void
    {
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        mkdir($this->root . '/storage');
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false, 'panel_settings' => false, 'resources' => [\\FloCMS\\Admin\\Tests\\Fixtures\\Relations\\HomeResource::class]];");
        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        $pdo->exec('CREATE TABLE homes (id INTEGER PRIMARY KEY, title TEXT, country_id INTEGER, city_id INTEGER, agent_id INTEGER)');
        $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT, country_id INTEGER, archived INTEGER)');
        $pdo->exec('CREATE TABLE features (id INTEGER PRIMARY KEY, name TEXT)');
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Relation field "agent_id": table "agents" not found', $display);
        self::assertStringContainsString('Relation field "features": pivot table "home_feature" not found', $display);
        self::assertStringContainsString('no foreign key on homes.city_id', $display);
        self::assertStringContainsString('REFERENCES `cities` (`id`) ON DELETE RESTRICT', $display);
        self::assertStringNotContainsString('Column "features"', $display, 'a pivot field is not a column of homes');
    }

    public function testMakeResourceReorderableWritesTheColumnAndTheCall(): void
    {
        $tester = $this->runCommand(new MakeResourceCommand(), ['Faq', '--migration', '--reorderable']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $file = $this->root . '/admin/Resources/FaqResource.php';
        $code = (string) file_get_contents($file);
        self::assertStringContainsString("->reorderable('sort');", $code);
        self::assertStringNotContainsString('defaultSort', $code, 'the list follows the editors\' order');
        $this->lint($file);

        $migrations = glob($this->root . '/database/migrations/*_create_faqs_table.php');
        self::assertCount(1, $migrations);
        $this->lint($migrations[0]);
        $db = new \FloCMS\Core\Database(new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        (require $migrations[0])->up($db);
        $columns = array_column($db->query('PRAGMA table_info(`faqs`)')->fetchAll(\PDO::FETCH_ASSOC), 'type', 'name');
        self::assertSame('INT', $columns['sort']);
        self::assertSame(['faqs_sort_index'], array_column($db->query("PRAGMA index_list(`faqs`)")->fetchAll(\PDO::FETCH_ASSOC), 'name'));

        $tester = $this->runCommand(new MakeResourceCommand(), ['Slide', '--reorderable']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('ALTER TABLE `slides` ADD `sort` INT NOT NULL DEFAULT 0; CREATE INDEX `slides_sort_index` ON `slides` (`sort`);', $tester->getDisplay());
        self::assertStringContainsString("->reorderable('sort');", (string) file_get_contents($this->root . '/admin/Resources/SlideResource.php'));

        $plain = (string) file_get_contents($this->root . '/admin/Resources/FaqResource.php');
        $this->runCommand(new MakeResourceCommand(), ['Page', '--migration']);
        $migration = (string) file_get_contents(glob($this->root . '/database/migrations/*_create_pages_table.php')[0]);
        self::assertStringNotContainsString('sort', $migration, 'only with --reorderable');
        self::assertStringNotContainsString('{{', $migration);
        self::assertNotSame('', $plain);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDoctorChecksTheReorderColumn(): void
    {
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        mkdir($this->root . '/storage');
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false, 'panel_settings' => false, 'resources' => [\\FloCMS\\Admin\\Tests\\Fixtures\\Reorder\\FaqResource::class]];");
        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        $pdo->exec('CREATE TABLE faqs (id INTEGER PRIMARY KEY, question TEXT, category TEXT, archived INTEGER, created_at TEXT, updated_at TEXT)');
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Column "sort" (reorderable) is missing: ALTER TABLE `faqs` ADD `sort` INT NOT NULL DEFAULT 0; CREATE INDEX `faqs_sort_index` ON `faqs` (`sort`);', $display);

        $pdo->exec('ALTER TABLE faqs ADD sort POINT');
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Column "sort" (reorderable) is POINT: use an integer type (INT)', $display, '"point" contains "int" but is not an integer');
        $pdo->exec('DROP TABLE faqs');
        $pdo->exec('CREATE TABLE faqs (id INTEGER PRIMARY KEY, question TEXT, category TEXT, archived INTEGER, created_at TEXT, updated_at TEXT, sort TEXT)');
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Column "sort" (reorderable) is TEXT: use an integer type (INT)', $display);

        $pdo->exec('DROP TABLE faqs');
        $pdo->exec('CREATE TABLE faqs (id INTEGER PRIMARY KEY, question TEXT, category TEXT, sort INTEGER NOT NULL DEFAULT 0, archived INTEGER, created_at TEXT, updated_at TEXT)');
        $display = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Reorderable by "sort" (up to 500 rows at a time)', $display);
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

    public function testMakeBlockWritesTheClassAndTemplateAndRegistersIt(): void
    {
        $this->runCommand(new InstallCommand());
        $tester = $this->runCommand(new MakeBlockCommand(), ['LatestProjects']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $code = (string) file_get_contents($this->root . '/admin/Blocks/LatestProjects.php');
        self::assertStringContainsString('namespace App\Admin\Blocks;', $code);
        self::assertStringContainsString("protected static string \$type = 'latest_projects';", $code);
        $this->lint($this->root . '/admin/Blocks/LatestProjects.php');
        $template = (string) file_get_contents($this->root . '/views/blocks/latest_projects.html');
        self::assertStringContainsString('{{ $heading }}', $template);
        self::assertStringContainsString('class="flo-latest-projects"', $template);
        $this->lint($this->root . '/config/admin.php');
        self::assertSame(['App\Admin\Blocks\LatestProjects'], (require $this->root . '/config/admin.php')['blocks']);
        // Never twice; an existing template is kept unless --force
        self::assertSame(1, $this->runCommand(new MakeBlockCommand(), ['LatestProjects'])->getStatusCode());
        self::assertStringContainsString('already registered', $this->runCommand(new MakeBlockCommand(), ['LatestProjects', '--force'])->getDisplay());
        self::assertNotSame(0, $this->runCommand(new MakeBlockCommand(), ['latest-projects'])->getStatusCode());
    }

    public function testAdminBlocksCopiesTheDefaultsAndNeverOverwrites(): void
    {
        $tester = $this->runCommand(new BlocksCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($this->root . '/views/blocks/slider.html');
        self::assertFileExists($this->root . '/public/assets/flo-blocks/slider.js');
        self::assertFileExists($this->root . '/public/assets/flo-blocks/blocks.css');
        file_put_contents($this->root . '/views/blocks/slider.html', 'mine');
        file_put_contents($this->root . '/views/blocks/hero.html', 'mine too');
        $again = $this->runCommand(new BlocksCommand());
        self::assertStringContainsString('already there and kept', $again->getDisplay());
        self::assertSame('mine', file_get_contents($this->root . '/views/blocks/slider.html'));
        $this->runCommand(new BlocksCommand(), ['--force=slider.html']);
        self::assertStringContainsString('data-flo-slider', (string) file_get_contents($this->root . '/views/blocks/slider.html'));
        self::assertSame('mine too', file_get_contents($this->root . '/views/blocks/hero.html'), 'only the file named');
        self::assertNotSame(0, $this->runCommand(new BlocksCommand(), ['--force=../config/admin.php'])->getStatusCode());

        // With pages (0.30), the default page template too
        self::assertFileDoesNotExist($this->root . '/views/page-templates/default.html');
        file_put_contents($this->root . '/config/admin.php', "<?php return ['pages' => true];");
        $this->runCommand(new BlocksCommand());
        self::assertStringContainsString('{!! $content !!}', (string) file_get_contents($this->root . '/views/page-templates/default.html'));
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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testPurgeCommandsRemoveWhatHasExpired(): void
    {
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        mkdir($this->root . '/storage');
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false, 'media_library' => false, 'trash' => ['days' => 7], 'activity' => ['days' => 30]];");
        $missing = $this->runCommand(new TrashPurgeCommand());
        self::assertSame(1, $missing->getStatusCode());
        self::assertStringContainsString('The trash table is missing', $missing->getDisplay() . $missing->getErrorOutput());

        $this->runCommand(new InstallCommand());
        $migration = glob($this->root . '/database/migrations/*_create_admin_trash_and_admin_activity_tables.php');
        self::assertCount(1, $migration);
        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        (require $migration[0])->up(new \FloCMS\Core\Database($pdo));
        $insert = $pdo->prepare('INSERT INTO admin_trash (resource, record_id, title, data, batch, deleted_at) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute(['posts', '1', 'Old', '{"id":1}', str_repeat('a', 32), date('Y-m-d H:i:s', time() - 8 * 86400)]);
        $insert->execute(['posts', '2', 'New', '{"id":2}', str_repeat('b', 32), date('Y-m-d H:i:s', time() - 86400)]);
        $log = $pdo->prepare('INSERT INTO admin_activity (action, created_at) VALUES (?, ?)');
        $log->execute(['created', date('Y-m-d H:i:s', time() - 40 * 86400)]);
        $log->execute(['created', date('Y-m-d H:i:s')]);

        $tester = $this->runCommand(new TrashPurgeCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 record(s) older than 7 days deleted for good.', $tester->getDisplay());
        self::assertSame(['2'], $pdo->query('SELECT record_id FROM admin_trash')->fetchAll(\PDO::FETCH_COLUMN));

        $tester = $this->runCommand(new ActivityPurgeCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('older than 30 days removed', $tester->getDisplay());
        // The trash purge wrote a "purged" entry; the old "created" one is gone
        self::assertSame(['created', 'purged'], $pdo->query('SELECT action FROM admin_activity ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));

        $doctor = $this->runCommand(new DoctorCommand())->getDisplay();
        self::assertStringContainsString('Table "admin_trash" (trash)', $doctor);
        self::assertStringContainsString('Table "admin_activity" (activity log)', $doctor);
        self::assertStringContainsString('records are kept 7 days', $doctor);
    }

    /**
     * The command boots the project, once per process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testMailTestSendsWithTheMailSettings(): void
    {
        $this->runCommand(new InstallCommand());
        $_ENV['MAIL_TRANSPORT'] = 'log';
        $_ENV['PRIMARY_EMAIL'] = 'info@example.test';
        $tester = $this->runCommand(new \FloCMS\Admin\Console\MailTestCommand(), ['address' => 'me@example.test']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('Sending with the log', $tester->getDisplay());
        self::assertStringContainsString('To: me@example.test', (string) file_get_contents($this->root . '/storage/logs/admin-mail.log'));

        $_ENV['APP_ENV'] = 'production';
        self::assertStringContainsString('MAIL_TRANSPORT=log on a production site', $this->runCommand(new DoctorCommand())->getDisplay());

        $bad = $this->runCommand(new \FloCMS\Admin\Console\MailTestCommand(), ['address' => 'not-an-address']);
        self::assertSame(1, $bad->getStatusCode());
    }
}
