<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Core\Database;
use PDO;

final class SqliteMediaLibraryTest extends MediaLibraryTestCase
{
    protected function connect(): Database
    {
        return new Database(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
    }
}
