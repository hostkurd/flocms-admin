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

    public function testGreekSearchIgnoresCase(): void
    {
        $result = $this->repository->paginate($this->table, 'ΒΊΛΑ', null, null, 1, 10, 'en');

        self::assertSame(['sea-view-villa'], array_map(static fn (object $r): string => $r->slug, $result['data']));
    }

    public function testJsonColumnsWork(): void
    {
        // The legacy plain-text row is not valid JSON: start from an empty table
        $this->db->query('DELETE FROM posts');
        $this->db->query('ALTER TABLE posts MODIFY title JSON NULL');
        $id = $this->repository->insert(['title' => '{"en":"Json villa","ar":"فيلا"}', 'slug' => 'json', 'status' => 'draft', 'featured' => 0]);

        $result = $this->repository->paginate($this->table, 'فيلا', 'title', 'asc', 1, 10, 'ar');
        self::assertSame([$id], array_map(static fn (object $r): string => (string) $r->id, $result['data']));
    }
}
