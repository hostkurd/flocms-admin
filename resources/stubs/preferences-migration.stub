<?php
declare(strict_types=1);

use FloCMS\CLI\Database\Migration;
use FloCMS\Core\Database;

/**
 * Personal preferences of admin users (flocms-admin): theme, panel language,
 * table density, visible columns. One row per user and key; the value is JSON.
 */
return new class extends Migration
{
    public function up(Database $db): void
    {
        $db->query('CREATE TABLE IF NOT EXISTS `admin_user_preferences` (
            `user_id` INTEGER NOT NULL,
            `key` VARCHAR(100) NOT NULL,
            `value` TEXT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`user_id`, `key`)
        )' . $this->tableOptions($db));
    }

    public function down(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS `admin_user_preferences`');
    }
};
