<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Core\Database;
use PDO;

/** Runs when FLO_ADMIN_TEST_DB_NAME is set (CI: MySQL 8 and MariaDB). */
final class MysqlRecordOrderTest extends RecordOrderTestCase
{
    protected function connect(): Database
    {
        $name = getenv('FLO_ADMIN_TEST_DB_NAME');
        if (!$name) {
            self::markTestSkipped('Set FLO_ADMIN_TEST_DB_NAME (and _HOST, _PORT, _USER, _PASS) to run the MySQL tests.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('FLO_ADMIN_TEST_DB_HOST') ?: '127.0.0.1', (int) (getenv('FLO_ADMIN_TEST_DB_PORT') ?: 3306), $name);
        return new Database(new PDO($dsn, getenv('FLO_ADMIN_TEST_DB_USER') ?: 'root', getenv('FLO_ADMIN_TEST_DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
        ]));
    }

    protected function createTable(Database $db): void
    {
        $db->query('CREATE TABLE faqs (id INT AUTO_INCREMENT PRIMARY KEY, question TEXT NULL, category VARCHAR(20) NOT NULL DEFAULT "",
            sort INT NULL, archived INT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL, INDEX (sort))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
