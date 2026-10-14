<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Core\Database;
use PDO;

/**
 * Runs when FLO_ADMIN_TEST_DB_NAME is set (CI: MySQL 8 and MariaDB):
 * FLO_ADMIN_TEST_DB_HOST, _PORT, _NAME, _USER, _PASS.
 */
final class MysqlRepositoryTest extends RepositoryTestCase
{
    protected function connect(): Database
    {
        $name = getenv('FLO_ADMIN_TEST_DB_NAME');
        if (!$name) {
            self::markTestSkipped('Set FLO_ADMIN_TEST_DB_NAME (and _HOST, _PORT, _USER, _PASS) to run the MySQL tests.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('FLO_ADMIN_TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('FLO_ADMIN_TEST_DB_PORT') ?: 3306),
            $name
        );

        return new Database(new PDO($dsn, getenv('FLO_ADMIN_TEST_DB_USER') ?: 'root', getenv('FLO_ADMIN_TEST_DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]));
    }

    protected function createTable(Database $db): void
    {
        // TEXT (not JSON) for title, so a legacy plain-text row can exist, as on a
        // site that switches an existing column to translatable
        $db->query('CREATE TABLE posts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title TEXT NULL, slug VARCHAR(190) NOT NULL DEFAULT \'\', body JSON NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'draft\', views INT NULL, featured TINYINT NOT NULL DEFAULT 0,
            published_on DATE NULL, created_at DATETIME NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function testNoOpCheckUsesCurrentReadAfterConcurrentCommit(): void
    {
        $other = $this->connect();
        $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->repository->transaction(function () use ($other): void {
            $original = $this->repository->find('1'); // establishes an older snapshot
            $other->table('posts')->where('id', '=', 1)->update(['slug' => 'concurrent']);
            try {
                $this->repository->updateUnchanged('1', ['slug' => $original->slug], $original);
                self::fail('A snapshot read would incorrectly report a successful no-op.');
            } catch (\FloCMS\Core\ValidationException $e) {
                self::assertArrayHasKey('_form', $e->getErrors());
            }
        });
        self::assertSame('concurrent', $this->repository->find('1')->slug);
    }

    public function testActionLockUsesCurrentReadRatherThanOlderRepeatableReadSnapshot(): void
    {
        $other = $this->connect();
        $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->repository->transaction(function () use ($other): void {
            $original = $this->repository->find('1');
            $other->table('posts')->where('id', '=', 1)->update(['slug' => 'concurrent-lock']);
            try {
                $this->repository->lockUnchanged('1', $original);
                self::fail('The action lock must reject the old snapshot before invoking a callback.');
            } catch (\FloCMS\Core\ValidationException $error) { self::assertArrayHasKey('_form', $error->getErrors()); }
        });
        self::assertSame('concurrent-lock', $this->repository->find('1')->slug);
    }

    public function testAccentedSearchIgnoresCase(): void
    {
        $this->repository->insert(['title' => '{"en":"Café du port"}', 'slug' => 'cafe', 'status' => 'draft', 'featured' => 0]);
        $result = $this->repository->paginate($this->table, 'CAFÉ', null, null, 1, 10, 'en');

        self::assertSame(['cafe'], array_map(static fn (object $r): string => $r->slug, $result['data']));
    }

    public function testJsonColumnsWork(): void
    {
        // The legacy plain-text row is not valid JSON: start from an empty table
        $this->db->query('DELETE FROM posts');
        $this->db->query('ALTER TABLE posts MODIFY title JSON NULL');
        $id = $this->repository->insert(['title' => '{"en":"Json villa","ar":"فيلا"}', 'slug' => 'json', 'status' => 'draft', 'featured' => 0]);

        self::assertTrue($this->repository->exists('title', 'Json villa', null, 'en'));
        self::assertTrue($this->repository->exists('title', 'فيلا', null, 'ar'));
        self::assertFalse($this->repository->exists('title', 'فيلا', $id, 'ar'));

        $result = $this->repository->paginate($this->table, 'فيلا', 'title', 'asc', 1, 10, 'ar');
        self::assertSame([$id], array_map(static fn (object $r): string => (string) $r->id, $result['data']));
    }
}
