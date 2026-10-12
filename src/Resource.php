<?php
declare(strict_types=1);

namespace FloCMS\Admin;

use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Database;

/**
 * One admin resource = one database table, with its form and its list.
 *
 *     final class PropertyResource extends Resource
 *     {
 *         protected static string $table = 'properties';
 *         protected static string $slug = 'properties';
 *
 *         public function form(Form $form): Form { ... }
 *         public function table(Table $table): Table { ... }
 *     }
 */
abstract class Resource
{
    /** Database table. */
    protected static string $table = '';

    /** URL segment: /admin/<slug>. Lower case letters, digits and single hyphens. */
    protected static string $slug = '';

    protected static string $primaryKey = 'id';

    /** Fill created_at on insert and updated_at on every save. */
    protected static bool $timestamps = false;

    /** Labels: a key of the site's lang files ("admin.property") or plain text. */
    protected static string $label = '';
    protected static string $pluralLabel = '';

    /** Navigation icon name (see the README for the list). */
    protected static ?string $icon = null;

    protected static int $navigationSort = 0;

    /** Prefix of the permission names; default "<config permission_prefix>.<slug>". */
    protected static ?string $permissionPrefix = null;

    /**
     * Permission per ability, overriding the generated name:
     * ['delete' => 'settings.delete']. Abilities: view, create, update, delete.
     *
     * @var array<string, string>
     */
    protected static array $permissions = [];

    abstract public function form(Form $form): Form;

    abstract public function table(Table $table): Table;

    /** Narrow every query of this resource, e.g. $query->where('site_id', '=', 1). */
    public function query(Database $query): Database
    {
        return $query;
    }

    /**
     * Change the values before they are written ($record is null on create).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function beforeSave(array $data, ?object $record): array
    {
        return $data;
    }

    /** Runs after a successful insert or update, with the saved record. */
    public function afterSave(object $record): void
    {
    }

    /** Return false to refuse deleting a record (the delete button is hidden). */
    public function canDelete(object $record): bool
    {
        return true;
    }

    /* ---------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------- */

    public static function getTable(): string
    {
        return static::$table;
    }

    public static function getSlug(): string
    {
        return static::$slug;
    }

    public static function getPrimaryKey(): string
    {
        return static::$primaryKey;
    }

    public static function usesTimestamps(): bool
    {
        return static::$timestamps;
    }

    public static function getLabel(): string
    {
        return AdminLang::label(static::$label !== '' ? static::$label : Str::headline(static::$table !== '' ? static::$table : static::$slug));
    }

    public static function getPluralLabel(): string
    {
        return AdminLang::label(static::$pluralLabel !== '' ? static::$pluralLabel : Str::headline(static::$slug));
    }

    public static function getIcon(): ?string
    {
        return static::$icon;
    }

    public static function getNavigationSort(): int
    {
        return static::$navigationSort;
    }

    /** The permission an ability needs: view, create, update or delete. */
    public static function permission(string $ability): string
    {
        if (isset(static::$permissions[$ability])) {
            return static::$permissions[$ability];
        }

        $prefix = static::$permissionPrefix ?? (Admin::config()->permissionPrefix() . '.' . static::$slug);

        return trim($prefix, '.') . '.' . $ability;
    }

    /**
     * Problems in the settings, or [] when the resource is usable.
     *
     * @return list<string>
     */
    public static function configurationErrors(): array
    {
        $errors = [];

        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', static::$slug) !== 1) {
            $errors[] = 'slug "' . static::$slug . '" is not valid: use lower case letters, digits and single hyphens';
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', static::$table) !== 1) {
            $errors[] = 'table "' . static::$table . '" is not a valid table name';
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', static::$primaryKey) !== 1) {
            $errors[] = 'primaryKey "' . static::$primaryKey . '" is not a valid column name';
        }

        return $errors;
    }
}
