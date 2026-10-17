<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Core\Database;
use PDO;

final class SqliteTrashTest extends TrashTestCase
{
    protected function connect(): Database
    {
        return new Database(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    }

    protected function createTables(Database $db): void
    {
        $db->query('CREATE TABLE cities (id INTEGER PRIMARY KEY, name TEXT)');
        $db->query('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)');
        $db->query('CREATE TABLE villas (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NULL, slug VARCHAR(190) NOT NULL UNIQUE, city_id INTEGER NULL,
            status VARCHAR(20) NULL, featured INTEGER NOT NULL DEFAULT 0, body TEXT NULL, owner_phone TEXT NULL, notes TEXT NULL, secret TEXT NULL, price REAL NULL)');
        $db->query('CREATE TABLE villa_tag (villa_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (villa_id, tag_id))');
        $db->query('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
    }
}
