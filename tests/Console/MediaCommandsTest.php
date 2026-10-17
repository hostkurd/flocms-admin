<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Console;

use FloCMS\Admin\Console\InstallCommand;
use FloCMS\Admin\Console\MediaImportCommand;
use FloCMS\Admin\Console\MediaUsageCommand;
use FloCMS\Admin\Tests\Fixtures\Media\MediaTables;
use FloCMS\CLI\Console\Testing\CommandTester;
use FloCMS\CLI\Project;
use PHPUnit\Framework\TestCase;

final class MediaCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/flo-admin-media-cli-' . bin2hex(random_bytes(6));
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

    public function testInstallWritesTheMediaMigrationWithTheConfiguredNames(): void
    {
        file_put_contents($this->root . '/config/admin.php', "<?php return ['media_library' => ['table' => 'site_media', 'usage_table' => 'site_media_usage']];");
        $tester = $this->runCommand(new InstallCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $migration = glob($this->root . '/database/migrations/*_create_site_media_table.php');
        self::assertCount(1, $migration);
        $code = (string) file_get_contents($migration[0]);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `site_media`', $code);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `site_media_usage`', $code);
        self::assertStringNotContainsString('{{', $code);
        self::assertStringContainsString('already exists', $this->runCommand(new InstallCommand())->getDisplay(), 'written once');

        file_put_contents($this->root . '/config/admin.php', "<?php return ['media_library' => false];");
        exec('rm -rf ' . escapeshellarg($this->root . '/database'));
        $this->runCommand(new InstallCommand());
        self::assertSame([], glob($this->root . '/database/migrations/*media*') ?: [], 'switched off: no migration');
    }

    public function testTheMigrationRefusesAForeignMediaTableAndCreatesBothTables(): void
    {
        $db = new \FloCMS\Core\Database(new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
        $db->query('CREATE TABLE media (id INTEGER PRIMARY KEY, file TEXT)');
        try {
            MediaTables::migration()->up($db);
            self::fail('A foreign media table was taken over.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString("Rename the library's tables in config/admin.php", $error->getMessage());
        }
        self::assertSame(['id', 'file'], array_column($db->query('PRAGMA table_info(media)')->fetchAll(\PDO::FETCH_ASSOC), 'name'), 'left alone');

        $db->query('DROP TABLE media');
        MediaTables::migration()->up($db);
        MediaTables::migration()->up($db);
        self::assertContains('alt', array_column($db->query('PRAGMA table_info(media)')->fetchAll(\PDO::FETCH_ASSOC), 'name'));
        self::assertContains('media_usage_record_index', array_column($db->query('PRAGMA index_list(media_usage)')->fetchAll(\PDO::FETCH_ASSOC), 'name'));
        MediaTables::migration()->down($db);
        self::assertSame([], $db->query("SELECT name FROM sqlite_master WHERE name IN ('media', 'media_usage')")->fetchAll());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testImportAddsExistingFilesOnceAndRebuildsUsage(): void
    {
        file_put_contents($this->root . '/.env', "DB_TYPE=sqlite\nDB_NAME=storage/db.sqlite\n");
        mkdir($this->root . '/storage');
        $media = $this->root . '/public/uploads/editor';
        file_put_contents($this->root . '/config/admin.php', "<?php return ['users' => false, 'panel_settings' => false, 'media' => ['root' => 'public/uploads/editor', 'url' => '/uploads/editor'], 'resources' => [\\FloCMS\\Admin\\Tests\\Fixtures\\Media\\ListingResource::class]];");
        mkdir($media . '/articles/body/2026/09', 0777, true);
        mkdir($media . '/library/2026/10/thumb', 0777, true);
        copy(dirname(__DIR__) . '/Browser/fixtures/photo.png', $media . '/articles/body/2026/09/photo.png');
        copy(dirname(__DIR__) . '/Browser/fixtures/clip.mp4', $media . '/articles/body/2026/09/clip.mp4');
        copy(dirname(__DIR__) . '/Browser/fixtures/photo.png', $media . '/library/2026/10/thumb/t.png');
        file_put_contents($media . '/articles/body/2026/09/notes.txt', 'not media');
        $pdo = new \PDO('sqlite:' . $this->root . '/storage/db.sqlite');
        $pdo->exec('CREATE TABLE listings (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, cover_id INTEGER NULL, gallery TEXT NULL, video_id INTEGER NULL, body TEXT NULL)');
        $pdo->exec("INSERT INTO listings (title, body) VALUES ('Old', '{\"en\":\"<p><img src=\\\"/uploads/editor/articles/body/2026/09/photo.png\\\"></p>\"}')");
        MediaTables::migration()->up(new \FloCMS\Core\Database($pdo));

        $dry = $this->runCommand(new MediaImportCommand(), ['--dry-run']);
        self::assertStringContainsString('2 file(s) would be added', $dry->getDisplay());
        self::assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM media')->fetchColumn());

        $tester = $this->runCommand(new MediaImportCommand());
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('2 file(s) added; 0 already in the library.', $tester->getDisplay());
        $rows = $pdo->query('SELECT kind, path, thumb_path, width FROM media ORDER BY path')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['kind' => 'video', 'path' => 'articles/body/2026/09/clip.mp4', 'thumb_path' => null, 'width' => null], ['kind' => 'image', 'path' => 'articles/body/2026/09/photo.png', 'thumb_path' => null]], array_map(static fn (array $r): array => array_filter($r, static fn ($v, $k): bool => $k !== 'width' || $r['kind'] === 'video', ARRAY_FILTER_USE_BOTH), $rows));
        self::assertGreaterThan(0, (int) $rows[1]['width']);
        self::assertSame([['listings', '1', 'body']], $pdo->query('SELECT resource, record_id, field FROM media_usage')->fetchAll(\PDO::FETCH_NUM), 'usage of the old text found');

        self::assertStringContainsString('0 file(s) added; 2 already in the library.', $this->runCommand(new MediaImportCommand())->getDisplay());

        $pdo->exec('DELETE FROM media_usage');
        $usage = $this->runCommand(new MediaUsageCommand());
        self::assertStringContainsString('listings: 1 record(s)', $usage->getDisplay());
        self::assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM media_usage')->fetchColumn());
    }
}
