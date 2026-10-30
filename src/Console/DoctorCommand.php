<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Languages;
use FloCMS\CLI\Console\Command;
use FloCMS\Core\Database;
use Throwable;

/**
 * php flo admin:doctor — checks the admin setup and every resource.
 */
final class DoctorCommand extends Command
{
    protected string $name = 'admin:doctor';

    protected string $description = 'Check the flocms-admin setup: config, resources, slugs, tables and the admin layout';

    private int $errors = 0;
    private int $warnings = 0;

    protected function handle(): int
    {
        $project = $this->project();
        $project->boot();
        Admin::setRoot($project->root());
        Languages::reset();

        $this->title('flocms-admin ' . Admin::VERSION);

        $config = Admin::config();
        if (!$config->isInstalled()) {
            $this->fail('config/admin.php not found: run php flo admin:install');
        }
        $this->ok('config/admin.php found');

        $languages = Languages::all();
        $this->ok('Languages: ' . implode(', ', $languages) . ' (default ' . Languages::default() . ')');

        $theme = $config->all()['theme'] ?? 'light';
        if (!is_string($theme) || !in_array($theme, \FloCMS\Admin\AdminConfig::THEMES, true)) {
            $this->caution("theme must be 'light', 'dark' or 'auto'; using 'light'.");
        }

        $values = $config->all();
        if (array_key_exists('translation_mode', $values) && !in_array($values['translation_mode'], \FloCMS\Admin\AdminConfig::TRANSLATION_MODES, true)) {
            $this->caution("translation_mode must be 'page' or 'field'; using 'field'.");
        }
        if (array_key_exists('primary_color', $values) && $values['primary_color'] !== null) {
            $color = $config->primaryColor();
            if ($color === null) {
                $this->caution("primary_color must be a hex colour such as '#0b6e91'; using the default.");
            } elseif (($ratio = \FloCMS\Admin\Support\Theme::contrastWithWhite($color)) < 4.5) {
                $this->caution(sprintf('primary_color %s gives white button text a contrast of %.1f:1; choose a darker colour (at least 4.5:1).', $color, $ratio));
            } else {
                $this->ok(sprintf('Brand colour %s (white text contrast %.1f:1)', $color, \FloCMS\Admin\Support\Theme::contrastWithWhite($color)));
            }
        }
        if (array_key_exists('dialog_min_height', $values) && $values['dialog_min_height'] !== null && $config->dialogMinHeight() === null) {
            $this->caution("dialog_min_height must be pixels (240) or a CSS length such as '20rem'; dialogs fit their content.");
        }
        if (array_key_exists('logo', $values) && $values['logo'] !== null && $config->logo() === null) {
            $this->caution('logo must be an absolute URL or a path starting with /; showing the brand letter instead.');
        }
        if (array_key_exists('sidebar', $values) && !in_array($values['sidebar'], \FloCMS\Admin\AdminConfig::SIDEBARS, true)) {
            $this->caution("sidebar must be 'light' or 'dark'; using 'light'.");
        }
        if (array_key_exists('content_width', $values) && !in_array($values['content_width'], \FloCMS\Admin\AdminConfig::WIDTHS, true)) {
            $this->caution("content_width must be 'full' or 'boxed'; using 'full'.");
        }

        $this->checkMedia($config);
        $this->checkBlocks();
        $this->checkBlockLooks();
        $this->checkLayout();
        if ($config->loginThemeEnabled()) {
            $login = $project->path('views/users/admin_login.html');
            if (!is_file($login) || !str_contains((string) file_get_contents($login), 'auth.login_view')) { $this->caution('Login theme enabled: add the auth.login_view presentation hook from skeleton 1.8.3 to views/users/admin_login.html (see UPGRADING.md).'); }
        }
        $this->checkPackagePages($config->usersEnabled());

        $registry = Admin::registry();
        foreach ($registry->problems() as $problem) {
            $this->bad($problem);
        }

        $resources = $registry->all();
        if ($resources === [] && $registry->problems() === []) {
            $this->caution('No resources yet: php flo make:admin-resource Post --migration');
        }

        $db = null;
        if ($resources !== []) {
            try {
                $db = $project->database();
                $db->query('SELECT 1');
                $this->ok('Database connection works');
            } catch (Throwable $e) {
                $this->bad('Database: ' . $e->getMessage());
            }
        }

        if ($config->usersEnabled() && $db === null) {
            try {
                $db = $project->database();
                $db->query('SELECT 1');
            } catch (Throwable $e) {
                $this->bad('Database: ' . $e->getMessage());
                $db = null;
            }
        }

        foreach ($resources as $slug => $class) {
            $this->checkResource($slug, $class, $db);
        }

        if ($config->usersEnabled() && ($users = $registry->find('accounts')) !== null) {
            $this->checkResource('accounts', $users, $db, ['is_verified', 'verify_type', 'token']);
        }

        $this->checkSettings($config, $db);
        $this->checkLibrary($config, $db, $project);
        $this->checkHistory($config, $db, $project, $resources);
        $this->checkRoles($config, $db, $project);
        $this->checkImports($config, $db, $project);
        $this->checkPasswordReset($config, $db, $project);
        $this->checkLoginSecurity($config, $db, $project);
        $this->checkPages($config, $db, $project);
        $this->checkPanelPages($config);

        try {
            $widgets = \FloCMS\Admin\Widgets\WidgetRegistry::fromConfig($config);
            foreach ($widgets->problems() as $problem) { $this->bad('Widget: ' . $problem); }
            $config->widgetColumns();
            if ($widgets->configured()) { $this->ok('Configured dashboard widgets: ' . count($widgets->all()) . ' (data providers not executed)'); }
        } catch (Throwable $error) { $this->bad('Widgets: ' . $error->getMessage()); }

        $this->newLine();
        if ($this->errors > 0) {
            $this->error($this->errors . ' problem(s), ' . $this->warnings . ' warning(s).');
            return self::FAILURE;
        }

        $this->success($this->warnings > 0 ? 'Usable, with ' . $this->warnings . ' warning(s).' : 'Everything looks good.');

        return self::SUCCESS;
    }

    /**
     * @param class-string<Resource> $class
     * @param list<string> $extraColumns columns written besides the form fields
     */
    private function checkResource(string $slug, string $class, ?Database $db, array $extraColumns = []): void
    {
        $this->section($class . '  (/admin/' . $slug . ')');
        if (!in_array($class::configuredFormMode(), Resource::FORM_MODES, true)) {
            $this->caution('formMode "' . $class::configuredFormMode() . "\" is not valid: use 'page', 'modal' or 'slide-over'; using 'page'.");
        }

        try {
            $resource = new $class();
            $form = $resource->form(new Form());
            $table = $resource->table(new Table());
        } catch (Throwable $e) {
            $this->bad('form() or table() fails: ' . $e->getMessage());
            return;
        }

        $this->ok('Permissions: ' . $class::permission('view') . ', ' . $class::permission('create') . ', '
            . $class::permission('update') . ', ' . $class::permission('delete'));
        if (!$class::showsInNavigation()) { $this->line('  Not in the sidebar ($navigation = false): its pages and search still work.'); }
        // CSV export and import (0.25)
        $csv = [];
        if ($class::isExportable()) { $csv[] = 'export (' . $class::permission('export') . ')'; }
        if ($class::isImportable()) { $csv[] = 'import (' . $class::permission('import') . ' + create)'; }
        $this->line('  CSV: ' . ($csv === [] ? 'off' : implode(', ', $csv)));
        if ($class::configuredTranslationMode() !== '' && !in_array($class::configuredTranslationMode(), \FloCMS\Admin\AdminConfig::TRANSLATION_MODES, true)) {
            $this->caution('translationMode "' . $class::configuredTranslationMode() . "\" is not valid: use 'page' or 'field'; using the panel's ('" . Admin::config()->translationMode() . "').");
        }
        if ($form->translatableNames() !== [] && count(Languages::all()) > 1) {
            $this->line('  Translations: ' . ($class::getTranslationMode() === 'page' ? 'one language at a time (page mode)' : 'a badge per field (field mode)')
                . ($class::configuredTranslationMode() === '' ? ', the panel default' : ''));
        }
        $this->checkManagers($class, $resource, $db);

        if ($db === null) {
            return;
        }

        $columns = $this->columns($db, $class::getTable());
        if ($columns === null) {
            $this->bad('Table "' . $class::getTable() . '" not found (run php flo migrate?)');
            return;
        }
        $this->ok('Table "' . $class::getTable() . '" (' . count($columns) . ' columns)');
        $this->checkCharset($db, $class::getTable());

        $needed = [$class::getPrimaryKey() => 'primary key'];
        foreach ($form->writableFields() as $name => $field) {
            if ($field->storesInColumn()) { $needed[$name] = 'form field'; }
        }
        foreach ($table->getColumns() as $name => $column) {
            if ($column->isDatabaseColumn()) { $needed[$name] ??= 'list column'; }
        }
        $table->prepare($form);
        foreach ($table->getFilters() as $filter) {
            if ($filter instanceof \FloCMS\Admin\Table\Filters\MissingTranslationFilter) { foreach ($filter->getFields() as $name) { $needed[$name] ??= 'translations filter'; } continue; }
            if (!($filter instanceof \FloCMS\Admin\Table\Filters\RelationFilter && $filter->getPivot() !== null)) { $needed[$filter->getColumn()] ??= 'filter column'; }
        }
        foreach ($extraColumns as $name) {
            $needed[$name] = 'set on create';
        }
        if ($class::usesTimestamps()) {
            $needed['created_at'] = 'timestamps';
            $needed['updated_at'] = 'timestamps';
        }

        foreach ($needed as $name => $why) {
            if (!array_key_exists(strtolower($name), $columns)) {
                $this->bad('Column "' . $name . '" (' . $why . ') is missing');
            }
        }

        // The import's key() column (0.25) finds existing records: it must exist and be unique.
        try { $importKey = $resource->import(new \FloCMS\Admin\ImportExport\Import())->getKey(); } catch (Throwable) { $importKey = null; }
        if ($importKey !== null && $class::isImportable()) {
            if (!array_key_exists(strtolower($importKey), $columns)) {
                $this->bad('Column "' . $importKey . '" (import key) is missing');
            } else {
                try {
                    $duplicate = $db->query('SELECT `' . $importKey . '` FROM `' . $class::getTable() . '` WHERE `' . $importKey . '` IS NOT NULL AND `' . $importKey . "` <> '' GROUP BY `" . $importKey . '` HAVING COUNT(*) > 1 LIMIT 1')->fetchColumn();
                    if ($duplicate !== false) {
                        $this->bad('Column "' . $importKey . '" (import key) is not unique: "' . $duplicate . '" is in more than one row, so an import can\'t tell which to update');
                    } else {
                        $this->ok('Import key "' . $importKey . '" is unique');
                    }
                } catch (Throwable) { /* not checked */ }
            }
        }

        if (($order = $table->getReorderColumn()) !== null) {
            $type = $columns[strtolower($order)] ?? null;
            if ($type === null) {
                $this->bad('Column "' . $order . '" (reorderable) is missing: ALTER TABLE `' . $class::getTable() . '` ADD `' . $order . '` INT NOT NULL DEFAULT 0; CREATE INDEX `' . $class::getTable() . '_' . $order . '_index` ON `' . $class::getTable() . '` (`' . $order . '`);');
            } elseif (preg_match('/^(tiny|small|medium|big)?int(eger)?\b/i', trim($type)) !== 1) {
                $this->bad('Column "' . $order . '" (reorderable) is ' . $type . ': use an integer type (INT)');
            } else {
                $this->ok('Reorderable by "' . $order . '" (up to ' . $table->getReorderLimit() . ' rows at a time)');
            }
        }

        $this->checkRelations($class, $form, $table, $db);

        foreach ($form->translatableNames() as $name) {
            $type = $columns[strtolower($name)] ?? null;
            if ($type !== null && preg_match('/json|text|longtext|mediumtext|clob/i', $type) !== 1) {
                $this->caution('Column "' . $name . '" is translatable but its type is ' . $type . ': use JSON (or a TEXT type)');
            }
        }
        // Repeater and key-value lists (0.24): one JSON list per column.
        foreach ($form->fields() as $name => $field) {
            if (!$field instanceof \FloCMS\Admin\Form\Fields\Repeater) { continue; }
            $type = $columns[strtolower($name)] ?? null;
            if ($type !== null && preg_match('/json|text|longtext|mediumtext|clob/i', $type) !== 1) {
                $this->caution('Column "' . $name . '" holds a list of items but its type is ' . $type . ': use JSON (or a TEXT type)');
            } elseif ($type !== null && preg_match('/^(tiny)?text$/i', trim($type)) === 1 && str_starts_with(strtolower(trim($type)), 'tiny')) {
                $this->caution('Column "' . $name . '" is TINYTEXT: too small for a list, use TEXT or JSON');
            }
        }
        // Blocks (0.29): one JSON document per column, often large.
        foreach ($form->fields() as $name => $field) {
            if (!$field instanceof \FloCMS\Admin\Form\Fields\Builder) { continue; }
            $vars = (int) ini_get('max_input_vars');
            // 0.32: the editor sends "$name" as one value, so max_input_vars only limits editors without JavaScript.
            if ($vars > 0 && $vars < 3000) {
                $this->ok('"' . $name . '" is saved as one value; max_input_vars (' . $vars . ') only limits pages edited without JavaScript (5000 is enough for them)');
            }
            $type = strtolower(trim((string) ($columns[strtolower($name)] ?? '')));
            if ($type !== '' && preg_match('/json|text|clob/', $type) !== 1) {
                $this->caution('Column "' . $name . '" holds blocks but its type is ' . $type . ': use JSON or LONGTEXT');
            } elseif ($type === 'tinytext' || $type === 'text') {
                $this->caution('Column "' . $name . '" holds blocks and is ' . strtoupper($type) . ' (up to 64 kB on MySQL): use MEDIUMTEXT, LONGTEXT or JSON');
            }
        }
    }

    /**
     * Relation managers (0.22): the child is registered, its table has the key
     * column and the parent has the column it points to.
     *
     * @param class-string<Resource> $class
     */
    private function checkManagers(string $class, Resource $resource, ?Database $db): void
    {
        try { $managers = \FloCMS\Admin\Relations\RelationManager::of($resource); }
        catch (Throwable $e) { $this->bad('relations() fails: ' . $e->getMessage()); return; }
        foreach ($managers as $manager) {
            $what = 'Relation manager "' . $manager->getName() . '" (' . $manager->getResource()::getSlug() . ' by ' . $manager->getForeignKey() . ')';
            $problems = $manager->problems($class, $db);
            if ($problems === []) { $this->ok($what); continue; }
            foreach ($problems as $problem) { $this->bad($what . ': ' . $problem); }
        }
    }

    /**
     * Relation fields, columns and filters: the related table and its key and
     * title columns, the pivot table and its key columns; a tip when a
     * key column has no foreign key (so the database protects related records).
     *
     * @param class-string<Resource> $class
     */
    private function checkRelations(string $class, Form $form, Table $table, Database $db): void
    {
        $items = [];
        foreach ($form->fields() as $field) {
            if ($field instanceof \FloCMS\Admin\Form\Fields\BelongsTo || $field instanceof \FloCMS\Admin\Form\Fields\BelongsToMany) { $items['field "' . $field->getName() . '"'] = $field; }
        }
        foreach ($table->getColumns() as $column) {
            if ($column instanceof \FloCMS\Admin\Table\Columns\RelationColumn) { $items['column "' . $column->getName() . '"'] = $column; }
        }
        foreach ($table->getFilters() as $filter) {
            if ($filter instanceof \FloCMS\Admin\Table\Filters\RelationFilter) { $items['filter "' . $filter->getName() . '"'] = $filter; }
        }
        foreach ($items as $what => $item) {
            try {
                $source = $item->relation();
            } catch (Throwable $e) {
                $this->bad('Relation ' . $what . ': ' . $e->getMessage());
                continue;
            }
            $related = $this->columns($db, $source->table());
            if ($related === null) {
                $this->bad('Relation ' . $what . ': table "' . $source->table() . '" not found');
                continue;
            }
            $this->checkCharset($db, $source->table());
            foreach ([$source->key(), $source->titleColumn()] as $column) {
                if (!array_key_exists(strtolower($column), $related)) { $this->bad('Relation ' . $what . ': column "' . $column . '" missing in "' . $source->table() . '"'); }
            }
            $pivot = method_exists($item, 'getPivot') ? $item->getPivot() : null;
            if (($item instanceof \FloCMS\Admin\Form\Fields\BelongsToMany || $item instanceof \FloCMS\Admin\Table\Columns\RelationColumn && $pivot !== null || $item instanceof \FloCMS\Admin\Table\Filters\RelationFilter && $pivot !== null)) {
                if ($pivot === null) { $this->bad('Relation ' . $what . ': call ->pivot(\'table\', \'owner_key\', \'related_key\')'); continue; }
                $pivotColumns = $this->columns($db, $pivot->table);
                if ($pivotColumns === null) { $this->bad('Relation ' . $what . ': pivot table "' . $pivot->table . '" not found'); continue; }
                foreach (array_filter([$pivot->ownerKey, $pivot->relatedKey, $pivot->orderColumn]) as $column) {
                    if (!array_key_exists(strtolower($column), $pivotColumns)) { $this->bad('Relation ' . $what . ': column "' . $column . '" missing in pivot table "' . $pivot->table . '"'); }
                }
                if ($pivot->parentKey !== $class::getPrimaryKey()) { $this->caution('Relation ' . $what . ': pivot parent key "' . $pivot->parentKey . '" is not the primary key "' . $class::getPrimaryKey() . '"; saving uses the primary key'); }
                continue;
            }
            if ($item instanceof \FloCMS\Admin\Form\Fields\BelongsTo && !$this->hasForeignKey($db, $class::getTable(), $item->getName())) {
                $this->line('  <fg=cyan>i</> Relation ' . $what . ': no foreign key on ' . $class::getTable() . '.' . $item->getName() . '. Adding one keeps related records from being deleted while in use: '
                    . 'ALTER TABLE `' . $class::getTable() . '` ADD FOREIGN KEY (`' . $item->getName() . '`) REFERENCES `' . $source->table() . '` (`' . $source->key() . '`) ON DELETE RESTRICT;');
            }
        }
    }

    /** @var array<string, true> tables whose character set was checked */
    private array $charsetChecked = [];

    /** A MySQL/MariaDB table whose text columns cannot hold every language (latin1 saves Arabic as "?"). */
    private function checkCharset(Database $db, string $table): void
    {
        if (isset($this->charsetChecked[strtolower($table)])) { return; }
        $this->charsetChecked[strtolower($table)] = true;
        $narrow = self::narrowTextColumns($db, $table);
        if ($narrow !== []) {
            $this->bad('Table "' . $table . '" stores text as ' . implode(', ', array_unique(array_values($narrow))) . ' (' . implode(', ', array_keys($narrow)) . '): Arabic, Kurdish and other non-Latin text is saved as "?". '
                . 'Fix: ALTER TABLE `' . $table . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; (text already saved as "?" must be typed again)');
        }
    }

    /**
     * Text columns whose character set is not UTF-8 (MySQL/MariaDB; SQLite is always UTF-8).
     *
     * @return array<string, string> column => character set
     * @internal
     */
    public static function narrowTextColumns(Database $db, string $table): array
    {
        if ($db->driver() === 'sqlite') { return []; }
        try {
            $rows = $db->query("SELECT COLUMN_NAME, CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME NOT IN ('utf8mb4', 'utf8mb3', 'utf8') ORDER BY ORDINAL_POSITION", [$table])->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        $narrow = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $narrow[(string) $row['column_name']] = (string) $row['character_set_name'];
        }
        return $narrow;
    }

    /** Whether $table.$column has a foreign key (MySQL/MariaDB and SQLite). */
    private function hasForeignKey(Database $db, string $table, string $column): bool
    {
        try {
            if ($db->driver() === 'sqlite') {
                foreach ($db->query('PRAGMA foreign_key_list(`' . $table . '`)')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if (strcasecmp((string) $row['from'], $column) === 0) { return true; }
                }
                return false;
            }
            $statement = $db->query('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$table, $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return true; // unknown: no tip
        }
    }

    /** @return array<string, string>|null lower-case column name => type, null when the table does not exist */
    private function columns(Database $db, string $table): ?array
    {
        try {
            if ($db->driver() === 'sqlite') {
                $rows = $db->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(\PDO::FETCH_ASSOC);
                $columns = [];
                foreach ($rows as $row) {
                    $columns[strtolower((string) $row['name'])] = (string) $row['type'];
                }
                return $columns === [] ? null : $columns;
            }

            $rows = $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC);
            $columns = [];
            foreach ($rows as $row) {
                $columns[strtolower((string) $row['Field'])] = (string) $row['Type'];
            }

            return $columns;
        } catch (Throwable) {
            return null;
        }
    }

    /** The package's own pages, and whether /admin can open the dashboard. */
    private function checkPackagePages(bool $usersEnabled): void
    {
        $pages = ['dashboard' => 'Dashboard', 'settings' => 'Settings'];
        if ($usersEnabled) {
            $pages += ['accounts' => 'Users', 'profile' => 'Profile'];
        }
        foreach ($pages as $slug => $name) {
            $controller = \FloCMS\Admin\Support\Str::appControllerClass($slug);
            if (class_exists($controller)) {
                $this->bad($name . ' page (/admin/' . $slug . ') is taken by the application controller ' . $controller);
            }
        }
        $this->ok('Panel pages: /admin/' . implode(', /admin/', array_keys($pages)));

        $pages = (string) @file_get_contents($this->project()->path('controllers/PagesController.php'));
        if (!str_contains($pages, 'admin.home')) {
            $this->caution('/admin shows the skeleton\'s page (skeleton 1.8+ opens the dashboard): link /admin/dashboard from views/pages/admin_index.html');
        }
    }

    /** The site's own panel pages (0.36): their classes, slugs, views, forms and the home page. */
    private function checkPanelPages(\FloCMS\Admin\AdminConfig $config): void
    {
        $registry = Admin::panelPages();
        $home = $config->home();
        if (($config->all()['admin_pages'] ?? []) === [] && $registry->problems() === [] && $home === null) { return; }
        $this->section('Panel pages  (admin_pages)');
        foreach ($registry->problems() as $problem) { $this->bad($problem); }
        foreach ($registry->all() as $slug => $class) {
            $problems = [];
            try {
                $page = new $class();
                new \FloCMS\Admin\PanelPages\PageFilters($page, []);
                if ($page->hasForm()) { new \FloCMS\Admin\PanelPages\PageForm($page); }
                elseif ($page->form(new \FloCMS\Admin\Form\Form())->fields() !== [] && !method_exists($page, 'submit')) { $problems[] = 'form() has fields but the page has no submit(array $data) method, so the form is not shown'; }
                $view = $page->view();
                if ($view !== null && $view !== '' && \FloCMS\Admin\Http\CustomPageController::viewFile($view) === null) { $problems[] = 'view "' . $view . '" not found: expected views/' . $view . '.html'; }
                foreach ($page->widgets() as $widget) {
                    if (!is_string($widget) || !is_subclass_of($widget, \FloCMS\Admin\Widgets\Widget::class)) { $problems[] = 'widgets() lists ' . (is_string($widget) ? $widget : get_debug_type($widget)) . ', which is not a widget class'; continue; }
                    foreach ((new $widget())->configurationErrors() as $error) { $problems[] = 'widget ' . $widget . ': ' . $error; }
                }
                foreach ($page->actions() as $action) {
                    if (!$action instanceof \FloCMS\Admin\Actions\Action || $action->getKind() !== 'custom') { $problems[] = 'actions() must return Action::make(...) objects'; break; }
                    $action->getPageForm();
                }
                $table = $page->table(new \FloCMS\Admin\Table\Table());
                if ($table->getQuery() !== null && $table->getColumns() === []) { $problems[] = 'table() sets a query but no columns, so no table is shown'; }
                if ($table->getQuery() !== null && $table->getDefaultSort() === null) { $this->caution($class . ': table() has no defaultSort(): rows may change places between pages; sort by a unique column such as id'); }
            } catch (Throwable $error) {
                $problems[] = $error->getMessage();
            }
            foreach ($problems as $problem) { $this->bad($class . ': ' . $problem); }
            if ($problems === []) {
                $this->ok($class::getLabel() . '  /admin/' . $slug . '  (' . $class::permission() . ($class::getGroup() !== null ? ', group ' . $class::getGroup() : '') . ')');
            }
        }
        if ($registry->all() !== []) {
            $this->line('  Roles open a page with its permission (Roles page › Panel pages; Super Admin has them through *).');
        }
        if ($home !== null) {
            $found = $registry->findClass($home);
            $found !== null ? $this->ok('Home page: /admin/' . $found::getSlug() . ' (people who may not open it see the dashboard)')
                : $this->bad("'home' => " . $home . ' is not one of admin_pages: /admin opens the dashboard.');
        }
    }

    /** Settings pages, their table and files, and the personal preferences table. */
    private function checkSettings(\FloCMS\Admin\AdminConfig $config, ?Database $db): void
    {
        $this->section('Settings and preferences');
        $settings = Admin::settings();
        foreach ($settings->problems() as $problem) {
            $this->bad('Settings: ' . $problem);
        }
        $pages = $settings->all();
        $site = array_diff_key($pages, ['panel' => true]);
        if ($site === [] && $settings->problems() === []) {
            $this->ok('No website settings pages (optional: php flo make:admin-settings)');
        } elseif ($site !== []) {
            $this->ok('Settings pages: ' . implode(', ', array_map(static fn ($page): string => $page->getName() . ' (' . $page->getPermission() . ')', $site)));
        }
        if (isset($pages['panel'])) {
            $this->ok('Admin panel settings page (settings.panel)');
        }

        $uploads = false;
        foreach ($settings->fields() as [, $field]) {
            if ($field instanceof \FloCMS\Admin\Form\Fields\FileUpload) { $uploads = true; break; }
        }
        if ($uploads) {
            $folder = $config->settingsUploads()['root'];
            $parent = $folder;
            while (!is_dir($parent) && dirname($parent) !== $parent) { $parent = dirname($parent); }
            if (!is_writable($parent)) {
                $this->bad('Settings uploads folder ' . $folder . ' is not writable: logos and other files cannot be saved.');
            } else {
                $this->ok('Settings uploads: ' . $folder . ' (served at ' . $config->settingsUploads()['url'] . ')');
            }
        }

        $cache = $config->root() . '/storage/cache';
        $parent = $cache;
        while (!is_dir($parent) && dirname($parent) !== $parent) { $parent = dirname($parent); }
        if (!is_writable($parent)) {
            $this->caution('storage/cache is not writable: setting() works but reads the database on every request.');
        }

        if ($db === null && $pages !== []) {
            try {
                $db = $this->project()->database();
                $db->query('SELECT 1');
            } catch (Throwable $e) {
                $this->bad('Database: ' . $e->getMessage());
                return;
            }
        }
        if ($db === null) {
            return;
        }

        $table = \FloCMS\Admin\Settings\SettingsStore::TABLE;
        $columns = $this->columns($db, $table);
        if ($columns === null) {
            $this->bad('Settings table "' . $table . '" not found: run php flo admin:install, then php flo migrate');
        } elseif (array_diff(['param', 'value', 'lang'], array_keys($columns)) !== []) {
            $this->bad('Table "' . $table . '" does not have the columns param, value and lang of FloCMS\'s settings table');
        } else {
            $this->ok('Table "' . $table . '"');
            try {
                $stored = array_keys((new \FloCMS\Admin\Settings\SettingsStore($db))->all());
                $unknown = array_values(array_diff($stored, array_keys($settings->fields())));
                if ($unknown !== []) {
                    $this->caution(count($unknown) . ' stored setting(s) no longer declared: ' . implode(', ', array_slice($unknown, 0, 10)) . (count($unknown) > 10 ? ', …' : ''));
                }
                $color = (new \FloCMS\Admin\Settings\SettingsStore($db))->all()['panel.primary_color'] ?? null;
                if (is_string($color) && preg_match('/^#[0-9a-f]{6}$/D', $color) === 1 && \FloCMS\Admin\Support\Theme::contrastWithWhite($color) < 4.5) {
                    $this->caution(sprintf('Saved brand colour %s gives white button text a contrast of %.1f:1; choose a darker colour in Settings → Admin panel.', $color, \FloCMS\Admin\Support\Theme::contrastWithWhite($color)));
                }
            } catch (Throwable $e) {
                $this->bad('Table "' . $table . '": ' . $e->getMessage());
            }
        }

        $columns = $this->columns($db, 'admin_user_preferences');
        if ($columns === null) {
            $this->caution('Table "admin_user_preferences" not found: personal preferences last only until logout (run php flo admin:install, then php flo migrate)');
        } elseif (array_diff(['user_id', 'key', 'value'], array_keys($columns)) !== []) {
            $this->bad('Table "admin_user_preferences" does not have the columns user_id, key, value');
        } else {
            $this->ok('Table "admin_user_preferences"');
        }
    }

    private function checkLayout(): void
    {
        $project = $this->project();
        $template = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $project->env('APP_TEMPLATE', 'default')) ?: 'default';

        foreach ([$template, 'default'] as $name) {
            $file = $project->path('templates/' . $name . '/layouts/admin.html');
            if (!is_file($file)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            if (!str_contains($content, "\$data['content']") && !str_contains($content, '$content')) {
                $this->bad('templates/' . $name . '/layouts/admin.html does not print $data[\'content\']: the panel would not show');
            } else {
                $this->ok('Admin layout: templates/' . $name . '/layouts/admin.html');
            }
            if (!str_contains($content, 'viewport')) {
                $this->caution('The admin layout has no <meta name="viewport">: the panel will not fit phones');
            }
            return;
        }

        $this->bad('No layouts/admin.html in templates/' . $template . '/ or templates/default/');
    }

    /** Rich text uploads go to a public folder: it must be writable and reachable by the web server. */
    /** The site's own blocks (config 'blocks', 0.29): classes that extend Block, with a valid, unique type. */
    private function checkBlocks(): void
    {
        $value = \FloCMS\Admin\Admin::config()->configuredBlocks();
        if (!is_array($value) || $value === []) { return; }
        $types = [];
        foreach ($value as $class) {
            if (!is_string($class) || !class_exists($class)) { $this->bad('Block ' . (is_string($class) ? $class : gettype($class)) . ' in admin.blocks does not exist'); continue; }
            if (!is_a($class, \FloCMS\Admin\Blocks\Block::class, true)) { $this->bad('Block ' . $class . ' must extend ' . \FloCMS\Admin\Blocks\Block::class); continue; }
            try { $type = $class::type(); } catch (\Throwable $e) { $this->bad('Block ' . $class . ': ' . $e->getMessage()); continue; }
            if (isset($types[$type])) { $this->caution('Blocks ' . $types[$type] . ' and ' . $class . ' have the same type "' . $type . '": the last one is used'); }
            $types[$type] = $class;
            try { \FloCMS\Admin\Form\Fields\Builder::make('doctor')->blockForm(new $class()); }
            catch (\Throwable $e) { $this->bad('Block ' . $class . ': ' . $e->getMessage()); continue; }
        }
        $this->ok(count($types) . ' block' . (count($types) === 1 ? '' : 's') . ' of the site\'s own');
    }

    /**
     * The blocks' styles and colours (0.33): entries that can't be used, and a copied
     * blocks.css (php flo admin:blocks) without the rules 0.33 added.
     */
    private function checkBlockLooks(): void
    {
        foreach ([...\FloCMS\Admin\Blocks\BlockStyles::problems(), ...\FloCMS\Admin\Blocks\BlockColours::problems()] as $problem) { $this->caution($problem); }
        $copied = Admin::root() . '/public/assets/flo-blocks/blocks.css';
        if (is_file($copied) && !str_contains((string) file_get_contents($copied), 'flo-space-above-xlarge')) {
            $this->caution('public/assets/flo-blocks/blocks.css is from before 0.33: Extra large and phone spacing and the background colours have no styles. Add the new rules from vendor/hostkurd/flocms-admin/resources/site/blocks.css (or php flo admin:blocks --force blocks.css)');
        }
    }

    private function checkMedia(\FloCMS\Admin\AdminConfig $config): void
    {
        $uses = false;
        foreach (Admin::registry()->all() as $class) {
            try { $form = (new $class())->form(new \FloCMS\Admin\Form\Form()); } catch (\Throwable) { continue; }
            foreach ($form->fields() as $field) {
                if ($field instanceof \FloCMS\Admin\Form\Fields\RichEditor && ($field->allowsImages() || $field->allowsVideos())) { $uses = true; break 2; }
            }
        }
        if (!$uses) { return; }
        $media = $config->media();
        $folder = $media['root'];
        $parent = $folder;
        while (!is_dir($parent) && dirname($parent) !== $parent) { $parent = dirname($parent); }
        if (!is_writable($parent)) {
            $this->bad('Rich text media folder ' . $folder . ' is not writable: editors cannot upload images or videos.');
        } elseif (!str_contains(str_replace('\\', '/', $folder), '/public/')) {
            $this->caution('Rich text media folder ' . $folder . ' is outside public/: make sure the web server serves it at ' . $media['url'] . '.');
        } else {
            $this->ok('Rich text media: ' . $folder . ' (served at ' . $media['url'] . ')');
        }
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $this->bad('Rich text images need the GD or Imagick PHP extension to make their web size.');
        }
    }

    /** The media library (0.17): its two tables, its folder and image processing. */
    private function checkLibrary(\FloCMS\Admin\AdminConfig $config, ?Database $db, \FloCMS\CLI\Project $project): void
    {
        $library = $config->mediaLibrary();
        if (!$library['enabled']) { return; }
        $this->section('Media library  (/admin/media)');
        if ($db === null) {
            try { $db = $project->database(); $db->query('SELECT 1'); } catch (Throwable) { $this->caution('No database connection: the media library tables were not checked.'); return; }
        }
        $needed = [$library['table'] => ['id', 'kind', 'path', 'thumb_path', 'mime', 'bytes', 'width', 'height', 'file_name', 'title', 'alt', 'uploaded_by', 'created_at', 'updated_at'],
            $library['usage_table'] => ['media_id', 'resource', 'record_id', 'field']];
        $ready = true;
        foreach ($needed as $table => $columns) {
            $found = $this->columns($db, $table);
            if ($found === null) {
                $this->bad('Table "' . $table . '" not found: run php flo admin:install, then php flo migrate.');
                $ready = false;
                continue;
            }
            $missing = array_values(array_diff($columns, array_keys($found)));
            if ($missing !== []) {
                $this->bad('Table "' . $table . '" exists but is not the media library\'s (missing ' . implode(', ', $missing) . '). Rename the library\'s tables in config/admin.php: \'media_library\' => [\'table\' => \'site_media\', \'usage_table\' => \'site_media_usage\'], then run php flo admin:install and php flo migrate.');
                $ready = false;
                continue;
            }
            $this->ok('Table "' . $table . '"');
            $this->checkCharset($db, $table);
        }
        // 0.18: folders, and the folder, fingerprint and focal point of each item.
        if ($ready) {
            $mediaColumns = $this->columns($db, $library['table']) ?? [];
            $missing = array_values(array_diff(['folder_id', 'hash', 'focal_x', 'focal_y'], array_keys($mediaColumns)));
            $folders = $this->columns($db, $library['folders_table']);
            if ($missing !== [] || $folders === null) {
                $this->bad('The media library needs its 0.18 migration (folders' . ($missing !== [] ? '; "' . $library['table'] . '" has no ' . implode(', ', $missing) : '') . '): run php flo admin:install, then php flo migrate. Until then the library is off and media fields keep their values.');
                $ready = false;
            } elseif (array_diff(['id', 'parent_id', 'name'], array_keys($folders)) !== []) {
                $this->bad('Table "' . $library['folders_table'] . '" exists but is not the media library\'s folders table. Rename it in config/admin.php: \'media_library\' => [\'folders_table\' => \'site_media_folders\'], then run php flo admin:install and php flo migrate.');
                $ready = false;
            } else {
                $this->ok('Table "' . $library['folders_table'] . '" (folders)');
                $this->checkCharset($db, $library['folders_table']);
            }
        }
        $media = $config->media();
        $parent = $media['root'];
        while (!is_dir($parent) && dirname($parent) !== $parent) { $parent = dirname($parent); }
        if (!is_writable($parent)) {
            $this->bad('Media folder ' . $media['root'] . ' is not writable: nobody can upload to the library.');
        } elseif ($ready) {
            $this->ok('Files in ' . $media['root'] . '/' . \FloCMS\Admin\Media\MediaFiles::DIRECTORY . ' (served at ' . $media['url'] . '/' . \FloCMS\Admin\Media\MediaFiles::DIRECTORY . ')');
        }
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $this->bad('Library images need the GD or Imagick PHP extension to make their web and thumb versions.');
        }
        $this->line('  Permissions: ' . \FloCMS\Admin\Media\MediaLibrary::permission('view') . ', …create, …update, …delete');
    }

    /** CSV imports (0.25): their tables, the upload folder and PHP's upload limits. */
    private function checkImports(\FloCMS\Admin\AdminConfig $config, ?Database $db, \FloCMS\CLI\Project $project): void
    {
        $import = $config->import();
        $export = $config->export();
        $this->section('CSV import and export  (/admin/<slug>/import, /admin/imports)');
        $this->line('  Export: ' . ($export['enabled'] ? 'on, up to ' . number_format($export['limit']) . ' rows a file' : 'off (\'export\' => false)'));
        if (!$import['enabled']) { $this->line('  Import: off (\'import\' => false)'); return; }
        if ($db === null) {
            try { $db = $project->database(); $db->query('SELECT 1'); } catch (Throwable) { $this->caution('No database connection: the import tables were not checked.'); return; }
        }
        $tables = [
            $import['table'] => ['id', 'resource', 'user_id', 'file_name', 'file_path', 'encoding', 'csv_separator', 'mapping', 'mode', 'status', 'position', 'next_byte', 'counts', 'locked_until', 'created_at', 'updated_at'],
            $import['rows_table'] => ['id', 'import_id', 'line', 'status', 'record_id', 'key_value', 'errors', 'before_values', 'after_hash'],
        ];
        foreach ($tables as $table => $columns) {
            $found = $this->columns($db, $table);
            if ($found === null) {
                $this->bad('Table "' . $table . '" not found: run php flo admin:install, then php flo migrate. Until then the Import button is not shown.');
                return;
            }
            $missing = array_values(array_diff($columns, array_keys($found)));
            if ($missing !== []) {
                $this->bad('Table "' . $table . '" exists but is not the import table (missing ' . implode(', ', $missing) . '). Name them in config/admin.php: \'import\' => [\'table\' => …, \'rows_table\' => …], then run php flo admin:install and php flo migrate.');
                return;
            }
            $this->ok('Table "' . $table . '"');
        }
        $folder = $config->root() . '/storage/admin-imports';
        if (is_dir($folder) && !is_writable($folder)) {
            $this->bad('storage/admin-imports/ is not writable: uploaded files can\'t be kept.');
        } elseif (!is_dir($folder) && !is_writable($config->root() . '/storage') && is_dir($config->root() . '/storage')) {
            $this->bad('storage/ is not writable: storage/admin-imports/ can\'t be created.');
        }
        $this->line('  Import: up to ' . number_format($import['max_rows']) . ' rows and ' . round($import['max_bytes'] / 1048576, 1) . ' MB a file, ' . $import['batch_size'] . ' rows a request, Undo for ' . $import['undo_days'] . ' days.');
        $this->line('  The browser sends files in parts of 2 MB, so PHP\'s upload_max_filesize (' . (ini_get('upload_max_filesize') ?: '?') . ') only limits pages without JavaScript.');
        $this->line('  php flo admin:import-purge removes old upload files (panel pages do it hourly).');
        if (Admin::registry()->find('imports') !== null || class_exists(\FloCMS\Admin\Support\Str::appControllerClass('imports'))) {
            $this->caution('The URL /admin/imports belongs to your site, so the panel\'s Imports page is not shown. Rename that resource or controller to use it.');
        }
    }

    /** The Roles page (0.21): flocms-core 2.4, the config maps it builds on, its table and the stored roles. */
    /** Two-factor sign-in and email verification (0.35). */
    /**
     * The login security switches whose value comes from the environment
     * (Env::get(), getenv(), $_ENV, $_SERVER) in config/admin.php (0.39.4).
     *
     * @return list<string>
     */
    public static function switchesFromEnv(string $file): array
    {
        $code = is_file($file) ? (string) file_get_contents($file) : '';
        $found = [];
        foreach (['two_factor', 'email_verification'] as $key) {
            $pattern = '/[\'"]' . $key . '[\'"]\s*=>\s*(?:\[[^\]]*?[\'"]enabled[\'"]\s*=>\s*)?(?:\(\w+\)\s*)?\\\\?(?:FloCMS\\\\Core\\\\)?(?:Env::get|getenv|\$_ENV|\$_SERVER|env\s*\()/';
            if (preg_match($pattern, $code) === 1) { $found[] = $key; }
        }
        return $found;
    }

    private function checkLoginSecurity(\FloCMS\Admin\AdminConfig $config, ?Database $db, \FloCMS\CLI\Project $project): void
    {
        $twoFactor = $config->twoFactor();
        $verification = $config->emailVerification();
        $this->section('Login security  (/admin/profile/security)');
        foreach (self::switchesFromEnv($config->root() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'admin.php') as $key) {
            $this->bad("'" . $key . "' in config/admin.php takes its value from the environment. The panel decides whether to ask the sign-in step when it starts, before the site reads .env, so a value in .env is not seen in time: write true or false in config/admin.php, or set a real server environment variable.");
        }
        if (!$twoFactor['enabled'] && !$verification['enabled']) {
            $this->line("  Off: add 'two_factor' => true and/or 'email_verification' => true to config/admin.php.");
            return;
        }
        if ($twoFactor['enabled'] && !method_exists(\FloCMS\Core\Auth::class, 'holdSessionsUsing')) {
            $this->bad('Two-factor sign-in needs flocms-core 2.7 or later: composer require hostkurd/flocms-core:^2.7');
        }
        if ($verification['enabled'] && $verification['required'] && !method_exists(\FloCMS\Core\Auth::class, 'holdSessionsUsing')) {
            $this->bad("'email_verification' => ['required' => true] needs flocms-core 2.7 or later.");
        }
        if (!is_callable(\FloCMS\Core\Config::get('auth.user_loader'))) {
            $this->bad("Config 'auth.user_loader' is not set (the skeleton sets it in config/config.php): signing in with a sign-in step would end the session.");
        }
        if ($twoFactor['enabled'] && !\FloCMS\Admin\Auth\SecretBox::ready()) {
            $this->bad('Two-factor sign-in needs APP_KEY of at least ' . \FloCMS\Admin\Auth\SecretBox::MIN_KEY_LENGTH . ' characters in .env (it encrypts the authenticator secrets). Changing APP_KEY later turns everyone\'s two-factor sign-in off: they set it up again.');
        }
        foreach ([\FloCMS\Admin\Auth\TwoFactor::PATH => 'TwoFactor', \FloCMS\Admin\Auth\EmailVerification::PATH => 'Verify'] as $slug => $class) {
            if (class_exists(\FloCMS\Admin\Support\Str::appControllerClass($slug))) {
                $this->bad('Your site has a ' . $class . 'Controller, which always wins: the panel\'s /admin/' . $slug . ' pages are not shown.');
            }
        }
        if ($db === null) {
            try { $db = $project->database(); $db->query('SELECT 1'); } catch (Throwable) { $this->caution('No database connection: the login security tables were not checked.'); $db = null; }
        }
        if ($db !== null) {
            $tables = [];
            if ($twoFactor['enabled']) {
                $tables[$twoFactor['table']] = ['user_id', 'secret', 'last_step', 'backup_codes', 'failures', 'failures_since', 'locked_until', 'confirmed_at', 'updated_at'];
                $tables[$twoFactor['roles_table']] = ['role', 'required', 'updated_at'];
                $tables[$twoFactor['devices_table']] = ['id', 'user_id', 'token_hash', 'password_stamp', 'name', 'created_at', 'last_used_at', 'expires_at'];
            }
            if ($verification['enabled']) {
                $tables[$verification['table']] = ['id', 'user_id', 'kind', 'email', 'token_hash', 'created_at', 'expires_at', 'used_at'];
            }
            $ready = true;
            foreach ($tables as $table => $columns) {
                $found = $this->columns($db, $table);
                if ($found === null) {
                    $this->bad('Table "' . $table . '" not found: run php flo admin:install, then php flo migrate.');
                    $ready = false;
                } elseif (($missing = array_values(array_diff($columns, array_keys($found)))) !== []) {
                    $this->bad('Table "' . $table . '" is missing ' . implode(', ', $missing) . '.');
                    $ready = false;
                } else {
                    $this->ok('Table "' . $table . '"');
                }
            }
            if ($verification['enabled'] && ($this->columns($db, 'users')['is_verified'] ?? null) === null) {
                $this->bad('The users table has no is_verified column (the skeleton\'s users table has it).');
            }
            if ($twoFactor['enabled'] && $ready && method_exists(\FloCMS\Core\Auth::class, 'holdSessionsUsing')) {
                try {
                    $previous = \FloCMS\Core\App::$db;
                    \FloCMS\Core\App::$db = $db;
                    \FloCMS\Admin\Auth\TwoFactor::reset();
                    $roles = \FloCMS\Admin\Users\UsersResource::roles();
                    foreach (\FloCMS\Admin\Auth\TwoFactor::requiredRoles($db) as $role) {
                        $coverage = \FloCMS\Admin\Auth\TwoFactor::roleCoverage($role, $db);
                        $missing = $coverage['total'] - $coverage['on'];
                        $label = $roles[$role] ?? ('#' . $role);
                        if ($missing > 0) {
                            $this->caution($label . ' requires two-factor sign-in: ' . $missing . ' of ' . $coverage['total'] . ' people haven\'t set it up yet (they will at their next sign-in).');
                        } else {
                            $this->ok($label . ' requires two-factor sign-in; everyone has it on.');
                        }
                    }
                    \FloCMS\Core\App::$db = $previous;
                } catch (Throwable $error) {
                    $this->caution('Could not read who uses two-factor sign-in: ' . $error->getMessage());
                }
            }
        }
        if (\FloCMS\Admin\Mail\Mailer::problem() !== null) {
            $this->bad('Email: ' . \FloCMS\Admin\Mail\Mailer::problem() . '. Verification links, invitations and two-factor notices are sent by email.');
        }
        if ($twoFactor['enabled']) {
            $this->line('  Two-factor sign-in: ' . ($twoFactor['remember_days'] > 0 ? 'devices can be remembered for ' . $twoFactor['remember_days'] . ' days' : 'no remembered devices') . '; app name "' . $twoFactor['issuer'] . '".');
        }
        if ($verification['enabled']) {
            $this->line('  Email links work once, for ' . $verification['expires'] . ' minutes' . ($verification['required'] ? '; unverified people wait at "Check your email" after signing in.' : '.'));
        }
    }

    private function checkPasswordReset(\FloCMS\Admin\AdminConfig $config, ?Database $db, \FloCMS\CLI\Project $project): void
    {
        $reset = $config->passwordReset();
        $this->section('Forgot password  (/admin/password/forgot)');
        if (!$reset['enabled']) { $this->line('  Off: add \'password_reset\' => true to config/admin.php to show "Forgot password?" on the login page.'); return; }
        if (!method_exists(\FloCMS\Core\App::class, 'allowGuestAdminActions') || !method_exists(\FloCMS\Core\Auth::class, 'checkSessionsUsing')) {
            $this->bad('Needs flocms-core 2.5 or later: composer require hostkurd/flocms-core:^2.5');
        }
        if (!$config->loginThemeEnabled()) { $this->caution('login_theme is off, so the login page has no "Forgot password?" link (the pages still work).'); }
        if (class_exists(\FloCMS\Admin\Support\Str::appControllerClass('password'))) {
            $this->caution('Your site has a PasswordController, which always wins: the panel\'s /admin/password pages are not shown.');
        }
        if ($db === null) {
            try { $db = $project->database(); $db->query('SELECT 1'); } catch (Throwable) { $this->caution('No database connection: the password resets table was not checked.'); $db = null; }
        }
        if ($db !== null) {
            $found = $this->columns($db, $reset['table']);
            if ($found === null) {
                $this->bad('Table "' . $reset['table'] . '" not found: run php flo admin:install, then php flo migrate. Until then the login page has no "Forgot password?" link.');
            } elseif (($missing = array_values(array_diff(['id', 'kind', 'user_id', 'email_hash', 'ip_hash', 'token_hash', 'created_at', 'expires_at', 'used_at'], array_keys($found)))) !== []) {
                $this->bad('Table "' . $reset['table'] . '" exists but is not the password resets table (missing ' . implode(', ', $missing) . '). Name another in config/admin.php: \'password_reset\' => [\'table\' => …].');
            } else {
                $this->ok('Table "' . $reset['table'] . '"');
            }
        }
        $problem = \FloCMS\Admin\Mail\Mailer::problem();
        $mail = \FloCMS\Admin\Mail\Mailer::settings();
        if ($problem !== null) {
            $this->bad('Email: ' . $problem . '.');
        } else {
            $this->ok('Email: ' . match ($mail['transport']) {
                'smtp' => 'SMTP ' . $mail['host'] . ':' . $mail['port'] . ' (' . $mail['encryption'] . ')',
                'log' => 'written to storage/logs/admin-mail.log (MAIL_TRANSPORT=log)',
                default => 'PHP mail()',
            } . ', from ' . ($mail['from'] !== '' ? $mail['from'] : '?') . '. Check it with: php flo admin:mail-test you@example.com');
        }
        $env = strtolower((string) \FloCMS\Core\Env::get('APP_ENV', ''));
        if ($mail['transport'] === 'log' && $env === 'production') {
            $this->bad('MAIL_TRANSPORT=log on a production site: reset links end up in a log file instead of an inbox.');
        }
        $url = (string) \FloCMS\Core\Env::get('APP_URL', '');
        if (preg_match('#^https?://[^/]+#i', $url) !== 1) {
            $this->bad('APP_URL in .env must be the full address of the site (https://…): it starts the link in the email.');
        } elseif ($env === 'production' && stripos($url, 'https://') !== 0) {
            $this->caution('APP_URL is not https: reset links would travel unencrypted.');
        }
        $key = \FloCMS\Core\Env::get('APP_KEY');
        if (!is_string($key) || strlen($key) < 32) {
            $this->caution('APP_KEY is empty or short: the stored hashes of addresses and IP addresses use a fixed key. Set a long random APP_KEY in .env.');
        }
        $this->line('  Links work once, for ' . $reset['expires'] . ' minutes; ' . $reset['per_email'] . ' requests an hour per address, ' . $reset['per_ip'] . ' per IP address.');
    }

    /** Website pages (config 'pages', 0.30): core 2.6, the table, addresses that something else answers, templates, the home page line and page_meta(). */
    private function checkPages(\FloCMS\Admin\AdminConfig $config, ?Database $db, \FloCMS\CLI\Project $project): void
    {
        $pages = $config->pages();
        $this->section('Pages  (/admin/site-pages)');
        // The website link (0.39.3): checked here, next to the website's pages.
        try { $link = $config->siteLink('/'); }
        catch (\InvalidArgumentException $error) { $this->bad($error->getMessage()); $link = '/'; }
        if (!$pages['enabled']) { $this->line('  Off: add \'pages\' => true to config/admin.php to manage the website\'s pages in the panel.'); return; }
        if ($link === null) { $this->caution('Pages are on, but \'site_link\' => false hides View site: Pages are website pages. Remove one of the two from config/admin.php.'); }
        if (!method_exists(\FloCMS\Core\App::class, 'fallbackUsing')) {
            $this->bad('Needs flocms-core 2.6 or later, so pages answer their addresses: composer require hostkurd/flocms-core:^2.6');
        }
        if ($db === null) {
            try { $db = $project->database(); $db->query('SELECT 1'); } catch (Throwable) { $this->caution('No database connection: the pages table was not checked.'); $db = null; }
        }
        if ($db !== null) {
            $found = $this->columns($db, $pages['table']);
            if ($found === null) {
                $this->bad('Table "' . $pages['table'] . '" not found: run php flo admin:install, then php flo migrate.');
            } elseif (($missing = array_values(array_diff(['id', 'title', 'slug', 'type', 'blocks', 'html', 'body', 'status', 'publish_at', 'unpublish_at', 'parent_id', 'is_home'], array_keys($found)))) !== []) {
                $this->bad('Table "' . $pages['table'] . '" exists but is not the pages table (missing ' . implode(', ', $missing) . '). Name another in config/admin.php: \'pages\' => [\'table\' => …].');
            } else {
                try { $rows = $db->table($pages['table'])->select(['id', 'title', 'slug', 'parent_id', 'is_home'])->get(); } catch (Throwable) { $rows = []; }
                $home = 0;
                foreach ($rows as $row) {
                    if ((int) ($row->is_home ?? 0) === 1) { $home++; continue; }
                    if ((int) ($row->parent_id ?? 0) > 0) {
                        if (!in_array((int) $row->parent_id, array_map(static fn (object $other): int => (int) $other->id, $rows), true)) {
                            $this->caution('Page "' . \FloCMS\Admin\Translation\Translations::get($row->title ?? '', \FloCMS\Admin\Translation\Languages::default()) . '" is under a page that was deleted: it is not shown. Choose another parent page, or restore that page from the trash.');
                        }
                        continue;
                    }
                    foreach (\FloCMS\Admin\Translation\Translations::all($row->slug ?? '') as $lang => $slug) {
                        $first = explode('/', (string) $slug)[0];
                        if ($first !== '' && ($taken = \FloCMS\Admin\Pages\Pages::reservedSegment($first)) !== null) {
                            $this->caution('Page "' . \FloCMS\Admin\Translation\Translations::get($row->title ?? '', (string) $lang) . '" (' . $lang . ') has the address /' . $first . ', which ' . (str_contains($taken, '\\') ? $taken : 'the panel or the framework') . ' answers first: the page is not shown. Change its address.');
                        }
                    }
                }
                $this->ok('Table "' . $pages['table'] . '": ' . count($rows) . ' page' . (count($rows) === 1 ? '' : 's') . ($home > 0 ? ', with a home page' : ''));
                if ($home > 1) { $this->caution($home . ' pages are marked as the home page: only the first is shown.'); }
                if ($home > 0) {
                    $controller = $project->path('controllers/PagesController.php');
                    if (is_file($controller) && !str_contains((string) file_get_contents($controller), 'home_page(')) {
                        $this->caution('A page is marked as the home page, but the site\'s PagesController::index() does not show it. Add as its first line: if ($page = home_page()) { return $page->response(); }');
                    }
                }
            }
        }
        $templates = \FloCMS\Admin\Pages\Templates::all();
        $this->ok('Page templates: ' . implode(', ', array_keys($templates)) . (count($templates) === 1 ? ' (add your own as views/page-templates/<name>.html)' : ''));
        $template = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $project->env('APP_TEMPLATE', 'default')) ?: 'default';
        $layout = $project->path('templates/' . $template . '/layouts/default.html');
        if (is_file($layout) && !str_contains((string) file_get_contents($layout), 'page_meta(')) {
            $this->caution('templates/' . $template . '/layouts/default.html does not print {!! page_meta() !!} in <head>: pages have no title, description or language links for search engines.');
        }
        $cache = $project->path('storage/cache');
        if (is_dir($cache) && !is_writable($cache)) { $this->bad('storage/cache is not writable: page previews cannot be made.'); }
        $this->line('  Sitemap: ' . ($pages['sitemap'] ? '/sitemap.xml' : 'off') . '; a missing translation ' . ($pages['missing'] === '404' ? 'is not found (404)' : 'shows the default language\'s page') . '.');
    }

    private function checkRoles(\FloCMS\Admin\AdminConfig $config, ?Database $db, \FloCMS\CLI\Project $project): void
    {
        if (!$config->roles()['enabled']) { return; }
        $this->section('Roles  (/admin/roles)');
        if (!\FloCMS\Admin\Roles\Roles::coreSupportsRoles()) {
            $this->bad('The Roles page needs flocms-core 2.4 or later: composer require "hostkurd/flocms-core:^2.4" -W. Until then the roles of config/config.php apply.');
            return;
        }
        if (!is_array(\FloCMS\Core\Config::get('permissions')) || !is_array(\FloCMS\Core\Config::get('admin_access_roles'))) {
            $this->line('  Off: config/config.php sets no \'permissions\' or \'admin_access_roles\' map, which the Roles page edits (the skeleton 1.8+ config has both).');
            return;
        }
        if ($db === null) {
            try { $db = $project->database(); $db->query('SELECT 1'); } catch (Throwable) { $this->caution('No database connection: the roles table was not checked.'); return; }
        }
        $table = $config->roles()['table'];
        $found = $this->columns($db, $table);
        if ($found === null) {
            $this->bad('Table "' . $table . '" not found: run php flo admin:install, then php flo migrate. Until then the roles of config/config.php apply.');
            return;
        }
        $missing = array_values(array_diff(['role', 'name', 'level', 'admin_access', 'permissions', 'created_at', 'updated_at'], array_keys($found)));
        if ($missing !== []) {
            $this->bad('Table "' . $table . '" exists but is not the roles table (missing ' . implode(', ', $missing) . '). Name it in config/admin.php: \'roles\' => [\'table\' => \'site_roles\'], then run php flo admin:install and php flo migrate.');
            return;
        }
        $rows = (new \FloCMS\Admin\Roles\RoleStore($db, $table))->rows();
        $config_roles = \FloCMS\Admin\Roles\Roles::configRoles();
        $changed = count(array_intersect_key($rows, $config_roles));
        $this->ok('Table "' . $table . '": ' . ($rows === [] ? 'no changes, the roles of config/config.php apply' : $changed . ' config role(s) changed, ' . (count($rows) - $changed) . ' added in the panel'));
        $this->line('  The page needs the permission ' . \FloCMS\Admin\Roles\Roles::PERMISSION . ' (Super Admin has it through *). php flo admin:roles lists the roles in force; php flo admin:roles-reset undoes changes.');
        if (class_exists(\FloCMS\Admin\Support\Str::appControllerClass('roles'))) {
            $this->caution('The URL /admin/roles belongs to your site, so the panel\'s Roles page is not shown. Rename that controller to use it.');
        }
    }

    /**
     * The trash and the activity log (0.19): their tables, and database cascades the
     * trash cannot undo (child rows removed by ON DELETE CASCADE don't come back).
     *
     * @param array<string, class-string<\FloCMS\Admin\Resource>> $resources
     */
    private function checkHistory(\FloCMS\Admin\AdminConfig $config, ?Database $db, \FloCMS\CLI\Project $project, array $resources): void
    {
        $trash = $config->trash();
        $activity = $config->activity();
        if (!$trash['enabled'] && !$activity['enabled']) { return; }
        $this->section('Trash and activity log  (/admin/trash, /admin/activity)');
        if ($db === null) {
            try { $db = $project->database(); $db->query('SELECT 1'); } catch (Throwable) { $this->caution('No database connection: the trash and activity tables were not checked.'); return; }
        }
        $tables = [];
        if ($trash['enabled']) { $tables[$trash['table']] = ['id', 'resource', 'record_id', 'title', 'data', 'links', 'files', 'batch', 'deleted_by', 'deleted_by_name', 'deleted_at']; }
        if ($activity['enabled']) { $tables[$activity['table']] = ['id', 'action', 'resource', 'record_id', 'title', 'user_id', 'user_name', 'changes', 'meta', 'batch', 'created_at']; }
        foreach ($tables as $table => $columns) {
            $found = $this->columns($db, $table);
            $what = $table === $trash['table'] && $trash['enabled'] ? 'trash' : 'activity log';
            if ($found === null) {
                $this->bad('Table "' . $table . '" not found: run php flo admin:install, then php flo migrate. Until then the ' . $what . ' is off (deletes are final' . ($what === 'trash' ? '' : ', nothing is logged') . ').');
                continue;
            }
            $missing = array_values(array_diff($columns, array_keys($found)));
            if ($missing !== []) {
                $this->bad('Table "' . $table . '" exists but is not the ' . $what . '\'s (missing ' . implode(', ', $missing) . '). Name it in config/admin.php: \'' . ($what === 'trash' ? 'trash' : 'activity') . '\' => [\'table\' => \'site_' . ($what === 'trash' ? 'trash' : 'activity') . '\'], then run php flo admin:install and php flo migrate.');
                continue;
            }
            $this->ok('Table "' . $table . '" (' . $what . ')');
            $this->checkCharset($db, $table);
        }
        if ($trash['enabled']) {
            $this->line('  Trash: records are kept ' . ($trash['days'] > 0 ? $trash['days'] . ' days' : 'until emptied') . '; php flo admin:trash-purge deletes older ones (panel pages do it hourly).');
            foreach ($resources as $slug => $class) {
                if (!$class::usesTrash()) { continue; }
                foreach ($this->cascades($db, $class::getTable()) as $child) {
                    $this->caution('"' . $child . '" deletes its rows ON DELETE CASCADE when a ' . $slug . ' record is deleted: the trash can restore the ' . $slug . ' record but not those rows.');
                }
            }
        }
        if ($activity['enabled']) {
            $this->line('  Activity log: entries are kept ' . ($activity['days'] > 0 ? $activity['days'] . ' days' : 'forever') . ($activity['logins'] ? '; sign-ins are recorded' : '') . '. The Activity page needs the permission ' . \FloCMS\Admin\Activity\Activity::PERMISSION . '.');
        }
        foreach (['trash', 'activity'] as $page) {
            if (Admin::registry()->find($page) !== null || class_exists(\FloCMS\Admin\Support\Str::appControllerClass($page))) {
                $this->caution('The URL /admin/' . $page . ' belongs to your site, so the panel\'s ' . ($page === 'trash' ? 'Trash' : 'Activity') . ' page is not shown. Rename that resource or controller to use it.');
            }
        }
    }

    /** @return list<string> tables whose foreign keys to $table delete their rows with it */
    private function cascades(Database $db, string $table): array
    {
        try {
            if ($db->driver() === 'sqlite') {
                $children = [];
                foreach ($db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN) as $name) {
                    foreach ($db->query('PRAGMA foreign_key_list(`' . str_replace('`', '', (string) $name) . '`)')->fetchAll(\PDO::FETCH_ASSOC) as $key) {
                        if (strcasecmp((string) $key['table'], $table) === 0 && strtoupper((string) $key['on_delete']) === 'CASCADE') { $children[] = (string) $name; }
                    }
                }
                return array_values(array_unique($children));
            }
            $rows = $db->query('SELECT DISTINCT TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND DELETE_RULE = \'CASCADE\'', [$table])->fetchAll(\PDO::FETCH_COLUMN);
            return array_values(array_map('strval', $rows));
        } catch (Throwable) {
            return [];
        }
    }

    private function ok(string $text): void
    {
        $this->line('  <success>✓</success> ' . $text);
    }

    private function caution(string $text): void
    {
        $this->warnings++;
        $this->line('  <fg=yellow>!</> ' . $text);
    }

    private function bad(string $text): void
    {
        $this->errors++;
        $this->line('  <fg=red>✗</> ' . $text);
    }
}
