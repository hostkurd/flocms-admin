<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Core\Database;
use PDO;

final class SqliteChoiceFiltersTest extends ChoiceFiltersTestCase
{
    protected function connect(): Database
    {
        return new Database(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
    }

    protected function createTable(Database $db): void
    {
        $db->query('CREATE TABLE homes (id INTEGER PRIMARY KEY, features TEXT NULL, keywords TEXT NULL, opens_at TIME NULL)');
    }
}
