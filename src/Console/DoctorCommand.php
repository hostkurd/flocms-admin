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
        foreach ($table->getFilters() as $filter) { if (!($filter instanceof \FloCMS\Admin\Table\Filters\RelationFilter && $filter->getPivot() !== null)) { $needed[$filter->getColumn()] ??= 'filter column'; } }
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
