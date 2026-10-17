<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Core\Database;
use PDO;

/** Runs when FLO_ADMIN_TEST_DB_NAME is set (CI: MySQL 8 and MariaDB). */
final class MysqlTrashTest extends TrashTestCase
{
    protected function connect(): Database
    {
        $name = getenv('FLO_ADMIN_TEST_DB_NAME');
        if (!$name) {
            self::markTestSkipped('Set FLO_ADMIN_TEST_DB_NAME (and _HOST, _PORT, _USER, _PASS) to run the MySQL tests.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('FLO_ADMIN_TEST_DB_HOST') ?: '127.0.0.1', (int) (getenv('FLO_ADMIN_TEST_DB_PORT') ?: 3306), $name);
        return new Database(new PDO($dsn, getenv('FLO_ADMIN_TEST_DB_USER') ?: 'root', getenv('FLO_ADMIN_TEST_DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        ]));
    }

    protected function createTables(Database $db): void
    {
        $options = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->query('CREATE TABLE cities (id INT PRIMARY KEY, name VARCHAR(100))' . $options);
        $db->query('CREATE TABLE tags (id INT PRIMARY KEY, name VARCHAR(100))' . $options);
        $db->query('CREATE TABLE villas (id INT AUTO_INCREMENT PRIMARY KEY, title TEXT NULL, slug VARCHAR(190) NOT NULL UNIQUE, city_id INT NULL,
            status VARCHAR(20) NULL, featured TINYINT NOT NULL DEFAULT 0, body MEDIUMTEXT NULL, owner_phone VARCHAR(40) NULL, notes TEXT NULL, secret VARCHAR(255) NULL, price DECIMAL(12,2) NULL)' . $options);
        $db->query('CREATE TABLE villa_tag (villa_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY (villa_id, tag_id))' . $options);
        $db->query('CREATE TABLE notes (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255))' . $options);
    }
}
