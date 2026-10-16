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

    /** Optional one-line description under the list page title: plain text or a lang key. */
    protected static ?string $description = null;

    protected static int $navigationSort = 0;

    /**
     * How Create and Edit open from the list: 'page' (a full page with room for
     * a sidebar, the default), 'modal' (a dialog over the list, best for small
     * records such as categories) or 'slide-over' (a panel from the side).
     * A single action can differ: CreateAction::make()->modal().
     */
    protected static string $formMode = 'page';

    public const FORM_MODES = ['page', 'modal', 'slide-over'];

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

    /** @return list<\FloCMS\Admin\Actions\Action> Resource list page-header actions. */
    public function headerActions(): array
    {
        return [\FloCMS\Admin\Actions\CreateAction::make()];
    }

    /** Narrow every query of this resource, e.g. $query->where('site_id', '=', 1). */
    public function query(Database $query): Database
    {
        return $query;
    }

    /**
     * Normalize editable state before skip rules and all validation. No writes or
     * password hashing here; return the complete string/translation-array state.
     *
     * @param array<string, string|array<string, string>> $state
     * @return array<string, string|array<string, string>>
     */
    public function normalizeState(array $state, ?object $record): array
    {
        return $state;
    }

    /**
     * Change dehydrated values inside the save transaction ($record is null on create).
     * Normalize user-editable values in normalizeState() so validation sees them.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function beforeSave(array $data, ?object $record): array
    {
        return $data;
    }

    /** Runs inside the save transaction, before commit. Exceptions roll back database writes. */
    public function afterSave(object $record): void
    {
    }

    /** Runs right after a record was deleted (inside the action's transaction when there is one). */
    public function afterDelete(object $record): void
    {
    }

    /** Return false to refuse creating records (the New button is hidden, the page answers 403). */
    public function canCreate(): bool
    {
        return true;
    }

    /** Return false to refuse editing a record (the Edit button is hidden, the page answers 403). */
    public function canEdit(object $record): bool
    {
        return true;
    }

    /** Return false to refuse deleting a record (the delete button is hidden). */
    public function canDelete(object $record): bool
    {
        return true;
    }

    /**
     * Checks after the field rules passed: return field name => message.
     * $state holds the submitted values (translatable ones as ['en' => ...]);
     * $record is null on create.
     *
     * @param array<string, string|array<string, string>> $state
     * @return array<string, string>
     */
    public function validate(array $state, ?object $record): array
    {
        return [];
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

    public static function getDescription(): ?string
    {
        return static::$description === null || static::$description === '' ? null : AdminLang::label(static::$description);
    }

    /** 'page', 'modal' or 'slide-over'; an unknown value falls back to 'page' (admin:doctor reports it). */
    public static function getFormMode(): string
    {
        return in_array(static::$formMode, self::FORM_MODES, true) ? static::$formMode : 'page';
    }

    /** The configured value as written, for admin:doctor. */
    public static function configuredFormMode(): string
    {
        return static::$formMode;
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
