<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Core\Database;
use PDO;

final class SqliteRecordOrderTest extends RecordOrderTestCase
{
    protected function connect(): Database
    {
        return new Database(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
    }

    protected function createTable(Database $db): void
    {
        $db->query('CREATE TABLE faqs (id INTEGER PRIMARY KEY AUTOINCREMENT, question TEXT NULL, category VARCHAR(20) NOT NULL DEFAULT "",
            sort INTEGER NULL, archived INTEGER NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL)');
    }
}
