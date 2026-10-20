<?php
declare(strict_types=1);

use FloCMS\CLI\Database\Migration;
use FloCMS\Core\Database;

/**
 * A bio for users (flocms-admin 0.28): an optional text column on the site's
 * own `users` table, shown on Users and the profile page. Nothing happens when
 * the column is already there.
 */
return new class extends Migration
{
    public bool $transactional = false;

    public function up(Database $db): void
    {
        try { $db->query('SELECT `bio` FROM `users` WHERE 1 = 0'); return; } catch (\PDOException) { /* not there yet */ }
        $db->query('ALTER TABLE `users` ADD COLUMN `bio` TEXT NULL');
    }

    public function down(Database $db): void
    {
        try { $db->query('ALTER TABLE `users` DROP COLUMN `bio`'); } catch (\PDOException) { /* already gone, or SQLite before 3.35 */ }
    }
};
