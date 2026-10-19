<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Console;

use FloCMS\Admin\Console\{ExportCommand, ImportCommand, ImportPurgeCommand, ImportsCommand, ImportUndoCommand, InstallCommand};
use FloCMS\Admin\Tests\Fixtures\ImportExport\ProductResource;
use FloCMS\CLI\Console\Testing\CommandTester;
use FloCMS\CLI\Project;
use PHPUnit\Framework\TestCase;

/** admin:export, admin:import (with --dry-run), admin:imports, admin:import-undo and admin:import-purge (0.25). */
final class ImportExportCommandsTest extends TestCase
{
    private string $root;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/flo-admin-csv-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/storage');
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        file_put_contents($this->root . '/config/config.php', "<?php\n\\FloCMS\\Core\\Config::set('languages', ['en', 'ar']);\n");
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false, 'media_library' => false, 'trash' => false, 'activity' => false, 'resources' => [" . var_export(ProductResource::class, true) . "]];");
        $this->pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, country_id INTEGER, archived INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE features (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, title TEXT, summary TEXT, category TEXT, price DECIMAL(12,2), in_stock INTEGER NOT NULL DEFAULT 0, city_id INTEGER NULL, released_on DATE NULL)');
        $this->pdo->exec('CREATE TABLE product_feature (product_id INTEGER NOT NULL, feature_id INTEGER NOT NULL, PRIMARY KEY (product_id, feature_id))');
        $this->pdo->exec("INSERT INTO cities (name, country_id) VALUES ('{\"en\":\"Erbil\",\"ar\":\"أربيل\"}', 1), ('{\"en\":\"Athens\"}', 2)");
        $this->pdo->exec("INSERT INTO features (name) VALUES ('{\"en\":\"Pool\"}'), ('{\"en\":\"Garden\"}')");
        $this->pdo->exec("INSERT INTO products (code, title, category, price, in_stock, city_id) VALUES ('P-1', '{\"en\":\"Hammer\",\"ar\":\"مطرقة\"}', 'tools', 12, 1, 1)");
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

    private function installImportTables(): void
    {
        $this->runCommand(new InstallCommand());
        $migration = glob($this->root . '/database/migrations/*_create_admin_imports_and_admin_import_rows_tables.php');
        self::assertCount(1, $migration, 'admin:install writes the import migration');
        (require $migration[0])->up(new \FloCMS\Core\Database($this->pdo));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testExportWritesTheListAsCsv(): void
    {
        $file = $this->root . '/out.csv';
        $tester = $this->runCommand(new ExportCommand(), ['products', '--output=' . $file]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 row written to', $tester->getDisplay());
        $csv = (string) file_get_contents($file);
        self::assertStringStartsWith("\u{FEFF}\"id\",\"code\",\"title.en\",\"title.ar\"", $csv);
        self::assertStringContainsString("\"P-1\",\"Hammer\",\"مطرقة\"", $csv);
        self::assertStringContainsString("\"1\",\"Erbil\"", $csv, 'the relation id and its name');
        self::assertStringNotContainsString('released_on', $csv, 'Export::except()');
        self::assertStringContainsString('"label"', $csv);
        self::assertStringContainsString('"Code P-1"', $csv, 'Export::column()');

        $readable = $this->runCommand(new ExportCommand(), ['products', '--output=' . $file, '--readable', '--filter=category:garden']);
        self::assertSame(0, $readable->getStatusCode(), $readable->getDisplay());
        self::assertStringContainsString('0 rows written', $readable->getDisplay());
        self::assertStringContainsString('"Title (English)"', (string) file_get_contents($file));

        $wrong = $this->runCommand(new ExportCommand(), ['nothing']);
        self::assertSame(1, $wrong->getStatusCode());
        self::assertStringContainsString('No resource "nothing". Registered: products', $wrong->getErrorOutput());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testImportDryRunImportListUndoAndPurge(): void
    {
        $file = $this->root . '/in.csv';
        file_put_contents($file, "code;title.en;category;Preis;in_stock;city_id;features\r\nP-1;Hammer XL;tools;14,5;1;Erbil;Pool | Garden\r\np-2 ;Saw;Tools;9;no;Athens;\r\nP-3;;toys;;;;\r\n");

        $missing = $this->runCommand(new ImportCommand(), ['products', $file]);
        self::assertSame(1, $missing->getStatusCode());
        self::assertStringContainsString('php flo admin:install, then php flo migrate', $missing->getErrorOutput());

        $this->installImportTables();
        $dry = $this->runCommand(new ImportCommand(), ['products', $file, '--update', '--dry-run']);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString('2 ready (1 new, 1 to update), 1 with errors', $dry->getDisplay());
        self::assertStringContainsString('Row 4 · Title: Title is required.', $dry->getErrorOutput());
        self::assertStringContainsString('Dry run: nothing was saved.', $dry->getDisplay());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());

        $create = $this->runCommand(new ImportCommand(), ['products', $file]);
        self::assertSame(0, $create->getStatusCode(), $create->getDisplay());
        self::assertStringContainsString('1 created, 0 updated, 2 skipped', $create->getDisplay(), 'P-1 exists: without --update it is skipped');

        $saw = $this->pdo->query("SELECT code, summary FROM products WHERE code = 'P-2'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['code' => 'P-2', 'summary' => 'Imported'], $saw, 'beforeRow() made the code upper case; defaults() filled the summary');
        self::assertSame([2], ProductResource::$finished, 'afterImport() ran once, for the import that saved');
        $update = $this->runCommand(new ImportCommand(), ['products', $file, '--update-only']);
        self::assertSame(0, $update->getStatusCode(), $update->getDisplay());
        self::assertStringContainsString('0 created, 2 updated', $update->getDisplay());
        $row = $this->pdo->query("SELECT * FROM products WHERE code = 'P-1'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['en' => 'Hammer XL', 'ar' => 'مطرقة'], json_decode($row['title'], true), 'the Arabic title has no column and is kept');
        self::assertEquals(14.5, $row['price'], 'a decimal comma in a ; file');
        self::assertSame(['1', '2'], array_map('strval', $this->pdo->query('SELECT feature_id FROM product_feature ORDER BY feature_id')->fetchAll(\PDO::FETCH_COLUMN)));

        $list = $this->runCommand(new ImportsCommand());
        self::assertSame(0, $list->getStatusCode(), $list->getDisplay());
        self::assertMatchesRegularExpression('/3\s.*products.*in\.csv.*System.*finished \(can undo\)/', $list->getDisplay());
        self::assertMatchesRegularExpression('/1\s.*products.*in\.csv.*cancelled/', $list->getDisplay());

        $undo = $this->runCommand(new ImportUndoCommand(), ['3'], ['yes']);
        self::assertSame(0, $undo->getStatusCode(), $undo->getDisplay());
        self::assertStringContainsString('0 records moved to the trash, 2 records restored.', $undo->getDisplay());
        $row = $this->pdo->query("SELECT * FROM products WHERE code = 'P-1'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('Hammer', json_decode($row['title'], true)['en']);
        self::assertSame([], $this->pdo->query('SELECT feature_id FROM product_feature')->fetchAll(\PDO::FETCH_COLUMN));

        $again = $this->runCommand(new ImportUndoCommand(), ['3'], ['yes']);
        self::assertSame(1, $again->getStatusCode());

        $purge = $this->runCommand(new ImportPurgeCommand());
        self::assertSame(0, $purge->getStatusCode(), $purge->getDisplay());
        self::assertStringContainsString('removed.', $purge->getDisplay());
    }
}
