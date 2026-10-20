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

    /**
     * How translatable fields are edited (0.23): 'page' (an "Editing: language"
     * switch shows the whole form in one language, best for content such as
     * posts and properties) or 'field' (a language badge on each field, best
     * for small records with one translatable name). '' follows the panel's
     * 'translation_mode' in config/admin.php.
     */
    protected static string $translationMode = '';

    /** Prefix of the permission names; default "<config permission_prefix>.<slug>". */
    protected static ?string $permissionPrefix = null;

    /**
     * Permission per ability, overriding the generated name:
     * ['delete' => 'settings.delete']. Abilities: view, create, update, delete.
     *
     * @var array<string, string>
     */
    protected static array $permissions = [];

    /**
     * Deleting a record puts it in the trash (0.19), where it can be restored for
     * the configured number of days. false deletes this resource's records at once.
     */
    protected static bool $trash = true;

    /**
     * Fields whose values stay out of the activity log (0.19): a change shows as
     * "Changed. The value is not recorded." Password fields are never logged.
     * The same as ->hiddenFromActivity() on the field.
     *
     * @var list<string>
     */
    protected static array $activityExcept = [];

    /**
     * Ctrl+K search (0.20) finds this resource's records by its table's searchable
     * columns; false leaves it out (its list search still works).
     */
    protected static bool $globallySearchable = true;

    /**
     * false keeps the resource out of the sidebar (0.22), e.g. records managed
     * only inside their parent's page. Its pages, permissions, trash and
     * Ctrl+K search still work.
     */
    protected static bool $navigation = true;

    /**
     * The view page (0.27): /admin/<slug>/view/<id>, read-only, for anyone with
     * the view permission. false: no view page for this resource.
     */
    protected static bool $viewPage = true;

    /**
     * What a click on a list row's title opens for someone who may edit the
     * record: 'edit' (the default) or 'view' (records mostly read, such as
     * enquiries). Without the right to edit, it always opens the view page.
     */
    protected static string $rowOpens = 'edit';

    /**
     * CSV export and import (0.25): the list's Export menu and Import button.
     * Each also needs its permission (content.<slug>.export / .import) and the
     * panel's 'export' / 'import' setting; false turns one off for this resource.
     */
    protected static bool $exportable = true;
    protected static bool $importable = true;

    /** Set when the resource is seen from inside one parent record (a relation manager). */
    private ?\FloCMS\Admin\Relations\ParentScope $parentScope = null;

    /** The list's Edit row action, looked up once (false: there is none). */
    private \FloCMS\Admin\Actions\Action|false|null $editAction = null;

    /**
     * Shape the CSV export (0.25): fields left out, extra columns, a row limit.
     *
     *     return $export->except(['internal_notes'])->column('agent', fn (object $row) => …);
     */
    public function export(\FloCMS\Admin\ImportExport\Export $export): \FloCMS\Admin\ImportExport\Export
    {
        return $export;
    }

    /**
     * Shape the CSV import (0.25): the unique column that finds existing records,
     * values for fields the file does not carry, a hook that cleans each row.
     *
     *     return $import->key('reference')->defaults(['status' => 'draft']);
     */
    public function import(\FloCMS\Admin\ImportExport\Import $import): \FloCMS\Admin\ImportExport\Import
    {
        return $import;
    }

    public static function isExportable(): bool { return static::$exportable && Admin::config()->export()['enabled']; }
    public static function isImportable(): bool { return static::$importable && Admin::config()->import()['enabled']; }

    /** Whether the current user may download this resource's records as CSV. */
    public static function canExport(): bool
    {
        return static::isExportable() && \FloCMS\Core\Auth::can(static::permission('view')) && \FloCMS\Core\Auth::can(static::permission('export'));
    }

    /** Whether an import may also update existing records (the update permission). */
    public static function canImportUpdates(): bool
    {
        return \FloCMS\Core\Auth::can(static::permission('update'));
    }

    /** Whether the current user may import into this resource (creating records). */
    /** Undo an import that created records: they are deleted (to the trash), so it needs delete too. */
    public static function canUndoImport(int $created, int $updated): bool
    {
        return ($created === 0 || \FloCMS\Core\Auth::can(static::permission('delete'))) && ($updated === 0 || static::canImportUpdates());
    }

    public static function canImport(): bool
    {
        return static::isImportable() && \FloCMS\Core\Auth::can(static::permission('view')) && \FloCMS\Core\Auth::can(static::permission('import'))
            && \FloCMS\Core\Auth::can(static::permission('create'));
    }

    abstract public function form(Form $form): Form;

    abstract public function table(Table $table): Table;

    /** @return list<\FloCMS\Admin\Actions\Action> Resource list page-header actions. */
    public function headerActions(): array
    {
        return [\FloCMS\Admin\Actions\CreateAction::make()];
    }

    /**
     * Related records shown on this resource's edit page (0.22), below the form:
     *
     *     return [RelationManager::make(UnitResource::class, 'property_id')];
     *
     * @return list<\FloCMS\Admin\Relations\RelationManager>
     */
    public function relations(): array
    {
        return [];
    }

    /** This resource as seen from inside one parent record. @internal */
    final public function withinParent(\FloCMS\Admin\Relations\ParentScope $scope): static
    {
        $copy = clone $this;
        $copy->parentScope = $scope;
        $copy->editAction = null;
        return $copy;
    }

    /** The parent record this resource is limited to, or null (its own pages). */
    final public function parentScope(): ?\FloCMS\Admin\Relations\ParentScope
    {
        return $this->parentScope;
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

    /**
     * Runs inside the restore transaction before the row goes back into its table
     * ($record holds the row as it was deleted). Throw a
     * \FloCMS\Core\ValidationException(['_form' => '…']) to refuse with a reason.
     */
    public function beforeRestore(object $record): void
    {
    }

    /** Runs inside the restore transaction after the record, its links and its media usage are back. */
    public function afterRestore(object $record): void
    {
    }

    /**
     * Whether the current user may see this deleted record in the trash (and restore
     * it or delete it for good). $record holds the row as it was deleted.
     *
     * A resource that narrows query() (records of one site, one owner) shows only the
     * records the current user deleted, unless it overrides this with its own rule:
     *
     *     public function canSeeInTrash(object $record, ?int $deletedBy): bool
     *     {
     *         return (int) $record->site_id === current_site_id();
     *     }
     */
    public function canSeeInTrash(object $record, ?int $deletedBy): bool
    {
        if (!self::narrowsQuery(static::class)) { return true; }
        return $deletedBy !== null && $deletedBy === \FloCMS\Admin\Support\Actor::id();
    }

    /** Whether a resource narrows query() or has its own trash rule (the trash then checks every entry). */
    public static function hasTrashRule(string $class): bool
    {
        return self::narrowsQuery($class) || (new \ReflectionMethod($class, 'canSeeInTrash'))->getDeclaringClass()->getName() !== self::class;
    }

    private static function narrowsQuery(string $class): bool
    {
        return (new \ReflectionMethod($class, 'query'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * The line under a record's title in Ctrl+K search (0.20). Null: the next
     * searchable column of the table.
     */
    public function searchDetails(object $record): ?string
    {
        return null;
    }

    /**
     * Where a record found by Ctrl+K search opens (0.20). Null: its edit page when
     * the person may edit it, else the list searched for its title.
     */
    public function searchUrl(object $record): ?string
    {
        return null;
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

    /** Return false to hide a record's view page (the page answers 403). The view permission is always needed as well. */
    public function canView(object $record): bool
    {
        return true;
    }

    /**
     * The fields of the view page (0.27). By default the form's own fields,
     * sections, tabs and aside, shown as values; return another form to show
     * a different set.
     */
    public function view(\FloCMS\Admin\Form\Form $form): \FloCMS\Admin\Form\Form
    {
        return $this->form($form);
    }

    public static function hasViewPage(): bool
    {
        return static::$viewPage;
    }

    /** 'edit' or 'view' (any other value reads as 'edit'). */
    public static function rowOpens(): string
    {
        return static::$rowOpens === 'view' ? 'view' : 'edit';
    }

    /**
     * Where a link to this record goes for the signed-in person (0.27): the
     * edit page when they may edit it (or the view page first with $rowOpens
     * 'view'), else the view page when they may view it, else null.
     *
     * @param array<string, string|null> $query extra query values (parent, back)
     */
    public function recordUrl(object $record, array $query = []): ?string
    {
        $id = (string) ($record->{static::getPrimaryKey()} ?? '');
        if ($id === '') { return null; }
        $canView = static::$viewPage && \FloCMS\Core\Auth::can(static::permission('view')) && $this->canView($record);
        $canEdit = $this->mayEdit($record);
        if ($canEdit && !($canView && static::rowOpens() === 'view')) {
            return \FloCMS\Admin\Admin::url(static::getSlug() . '/edit/' . rawurlencode($id), $query);
        }
        return $canView ? \FloCMS\Admin\Admin::url(static::getSlug() . '/view/' . rawurlencode($id), $query) : null;
    }

    /**
     * Whether the signed-in person may open this record's edit page: the same
     * check as the page itself (the list's Edit action with its permission,
     * canEdit(), visible() and authorize(); none inside a read-only manager).
     */
    public function mayEdit(object $record): bool
    {
        $action = $this->editAction();
        return $action !== null && $action->allowed($this, $record);
    }

    /** Whether the signed-in person may edit records of this resource at all (no record at hand). */
    public function mayEditSome(): bool
    {
        $action = $this->editAction();
        return $action !== null && \FloCMS\Core\Auth::can($action->permissionName($this));
    }

    private function editAction(): ?\FloCMS\Admin\Actions\Action
    {
        if ($this->editAction === null) {
            try {
                $action = (new \FloCMS\Admin\Actions\ActionRegistry($this))->find('row', 'edit');
                $this->editAction = $action->getKind() === 'edit' ? $action : false;
            } catch (\FloCMS\Core\HttpException) { $this->editAction = false; }
        }
        return $this->editAction === false ? null : $this->editAction;
    }

    /** The view page of a record, when the signed-in person may open it. */
    public function viewUrl(object $record, array $query = []): ?string
    {
        $id = (string) ($record->{static::getPrimaryKey()} ?? '');
        if ($id === '' || !static::$viewPage || !\FloCMS\Core\Auth::can(static::permission('view')) || !$this->canView($record)) { return null; }
        return \FloCMS\Admin\Admin::url(static::getSlug() . '/view/' . rawurlencode($id), $query);
    }

    /** Return false to refuse deleting a record (the delete button is hidden). */
    public function canDelete(object $record): bool
    {
        return true;
    }

    /**
     * Return false to hide the Reorder button of a reorderable list (the page
     * answers 403). The update permission is always needed as well.
     */
    public function canReorder(): bool
    {
        return true;
    }

    /**
     * Runs inside the transaction after Reorder changed positions, with the keys
     * in their new order (e.g. to clear a cached menu). Exceptions roll back.
     *
     * @param list<string> $ids
     */
    public function afterReorder(array $ids): void
    {
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

    /** Whether deleting puts records in the trash (when the trash is set up). */
    public static function usesTrash(): bool
    {
        return static::$trash;
    }

    /** @return list<string> */
    public static function activityExcept(): array
    {
        return array_values(array_filter(static::$activityExcept, 'is_string'));
    }

    public static function showsInNavigation(): bool
    {
        return static::$navigation;
    }

    public static function isGloballySearchable(): bool
    {
        return static::$globallySearchable;
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

    /** @var array<class-string, bool> */
    private static array $builders = [];

    /** Whether the form has a Builder field (0.29): its editor needs a page, never a dialog. */
    public function hasBuilder(): bool
    {
        if (!isset(self::$builders[static::class])) {
            $has = false;
            try {
                foreach ($this->form(new \FloCMS\Admin\Form\Form())->fields() as $field) { if ($field instanceof \FloCMS\Admin\Form\Fields\Builder) { $has = true; break; } }
            } catch (\Throwable) { $has = false; }
            self::$builders[static::class] = $has;
        }
        return self::$builders[static::class];
    }

    /** The configured value as written, for admin:doctor. */
    public static function configuredFormMode(): string
    {
        return static::$formMode;
    }

    /** 'page' or 'field': this resource's own choice, else the panel's. An unknown value falls back to the panel's (admin:doctor reports it). */
    public static function getTranslationMode(): string
    {
        return in_array(static::$translationMode, AdminConfig::TRANSLATION_MODES, true) ? static::$translationMode : Admin::config()->translationMode();
    }

    /** The value as written ('' follows the panel), for admin:doctor. */
    public static function configuredTranslationMode(): string
    {
        return static::$translationMode;
    }

    /** Whether forms of this resource show the "Editing: language" switch: page mode on a site with more than one language. */
    public static function usesLanguageSwitch(): bool
    {
        return static::getTranslationMode() === 'page' && count(\FloCMS\Admin\Translation\Languages::all()) > 1;
    }

    /** Whether this form shows the switch: page mode, several languages and at least one translatable field. */
    public static function usesLanguageSwitchFor(Form $form): bool
    {
        return static::usesLanguageSwitch() && $form->translatableNames() !== [];
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
