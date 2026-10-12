<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Console;

use FloCMS\Admin\Console\DoctorCommand;
use FloCMS\Admin\Console\InstallCommand;
use FloCMS\Admin\Console\MakeResourceCommand;
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

        $pdo->exec('ALTER TABLE properties ADD COLUMN title TEXT');
        $tester = $this->runCommand(new DoctorCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Everything looks good.', $tester->getDisplay());
    }
}
