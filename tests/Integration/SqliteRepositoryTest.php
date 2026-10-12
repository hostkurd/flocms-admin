<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Core\Database;
use PDO;

final class SqliteRepositoryTest extends RepositoryTestCase
{
    protected function connect(): Database
    {
        return new Database(new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]));
    }

    protected function createTable(Database $db): void
    {
        $db->query('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NULL, slug VARCHAR(190) NOT NULL DEFAULT "", body TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "draft", views INTEGER NULL, featured INTEGER NOT NULL DEFAULT 0,
            published_on DATE NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
    }
}
