<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Support\Str;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo make:admin-page SalesReport [--view] [--form] [--table[=listings]] [--resource=Listing] [--group=Reports] [--force]
 */
final class MakeAdminPageCommand extends Command
{
    use RegistersInConfig;

    protected string $name = 'make:admin-page';

    protected string $description = 'Create a panel page of your own in admin/Pages/ and register it in config/admin.php';

    protected array $examples = [
        'php flo make:admin-page SalesReport --table=listings --group=Reports',
        'php flo make:admin-page ListingsReport --resource=Listing --group=Reports',
        'php flo make:admin-page SalesReport --view --table --group=Reports',
        'php flo make:admin-page ExchangeRates --form --group=Tools',
        'php flo make:admin-page GettingStarted --view',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'Class name, e.g. SalesReport')
            ->option('view', null, Option::VALUE_NONE, 'Also write its view, views/admin/<slug>.html, with the panel\'s UI pieces')
            ->option('form', null, Option::VALUE_NONE, 'A tool page: a form with Save (form(), fill() and submit())')
            ->option('table', null, Option::VALUE_OPTIONAL, 'A read-only table of a database table, with filters: --table=listings (or --table to pick one from a list)')
            ->option('resource', 'r', Option::VALUE_REQUIRED, 'A read-only table of a resource\'s table, with its columns and labels: --resource=Listing')
            ->option('group', 'g', Option::VALUE_REQUIRED, 'The sidebar group, e.g. Reports')
            ->option('force', 'f', Option::VALUE_NONE, 'Overwrite existing files');
    }

    protected function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->invalid('The name must be a class name in StudlyCase, e.g. SalesReport.');
        }
        $group = $this->option('group');
        $group = is_string($group) ? trim($group) : '';
        $view = (bool) $this->option('view');
        $form = (bool) $this->option('form');
        $tableOption = $this->option('table');
        $resourceOption = $this->option('resource');
        $table = $tableOption !== null || $resourceOption !== null;

        $project = $this->project();
        $configFile = $project->path('config/admin.php');
        $config = is_file($configFile) ? (static fn (string $f): mixed => require $f)($configFile) : [];
        $config = is_array($config) ? $config : [];
        $namespace = trim((string) ($config['admin_pages_namespace'] ?? 'App\\Admin\\Pages'), '\\');
        // The folder as config/admin.php names it: relative to the project, or absolute (as AdminConfig reads it).
        $directory = rtrim(str_replace('\\', '/', (string) ($config['admin_pages_path'] ?? 'admin/Pages')), '/');
        $absolute = str_starts_with($directory, '/') || preg_match('~^[A-Za-z]:/~', $directory) === 1;
        $directory = $absolute ? $directory : ltrim($directory, '/');

        $short = preg_replace('/(?<!^)Page$/', '', $name) ?? $name;
        $slug = Str::kebab($short);
        $file = $directory . '/' . $name . '.php';
        $target = $absolute ? $file : $project->path($file);
        $viewName = 'admin/' . $slug;
        $viewFile = 'views/' . $viewName . '.html';
        if (is_file($target) && !$this->option('force')) {
            $this->fail($file . ' already exists (use --force to overwrite).');
        }

        $plan = null;
        if (is_string($resourceOption) && trim($resourceOption) !== '') {
            $plan = $this->resourcePlan($project, trim($resourceOption));
        } elseif ($tableOption !== null) {
            $plan = $this->tablePlan($project, $tableOption);
        }

        [$imports, $body] = $this->body($view, $form, $plan, $viewName);
        $content = Stub::render('admin-page', [
            'namespace' => $namespace,
            'imports' => $imports,
            'class' => $name,
            'slug' => $slug,
            'label' => ucfirst(strtolower(Str::headline($slug))),
            'icon' => $form ? 'settings' : ($table ? 'chart' : 'file'),
            'group' => $group === '' ? 'null' : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $group) . "'",
            'width' => $form && !$table && !$view ? 'narrow' : 'default',
            'body' => $body,
        ]);
        if (!Stub::write($target, $content)) {
            $this->fail('Could not write ' . $file . '.');
        }
        $this->success('Created ' . $file . ($plan !== null ? ' (a table of "' . $plan['table'] . '": ' . implode(', ', array_column($plan['columns'], 'name')) . ')' : ''));
        foreach ($plan['left'] ?? [] as $left) { $this->line('  Left out: ' . $left); }
        if ($plan !== null && array_filter($plan['columns'], static fn (array $c): bool => $c['translatable']) !== []) {
            $this->line('  Translatable columns were found from their values and marked ->translatable().');
        }
        if ($plan !== null && $plan['unread']) {
            $this->warn('The database could not be read, so the table "' . $plan['table'] . '" was not checked: add its columns in table().');
        }

        if ($view) {
            if (is_file($project->path($viewFile)) && !$this->option('force')) {
                $this->note($viewFile . ' already exists: left as it is.');
            } elseif (!Stub::write($project->path($viewFile), Stub::render('admin-page-view', ['class' => $name]))) {
                $this->fail('Could not write ' . $viewFile . '.');
            } else {
                $this->success('Created ' . $viewFile);
            }
        }

        $this->register($configFile, $namespace . '\\' . $name, 'admin_pages', true);
        $this->note('Page ready at /admin/' . $slug . '. Give roles access on the Roles page (Panel pages › panel.' . $slug . ').');
        $this->note('Check it with: php flo admin:doctor');

        return self::SUCCESS;
    }

    /**
     * What the table shows when a table is named: --table=listings reads it from the database.
     *
     * @return array<string, mixed>
     */
    private function tablePlan(\FloCMS\CLI\Project $project, mixed $option): array
    {
        try {
            $db = $project->database();
            $db->query('SELECT 1');
            $inspector = new TableInspector($db);
        } catch (\Throwable $error) {
            if (!is_string($option) || $option === '') {
                $this->fail('Plain --table lists the database\'s tables, but the database could not be read (' . $error->getMessage() . '). Name the table: --table=listings');
            }
            return self::emptyPlan($option) + ['unread' => true];
        }
        if (!is_string($option) || trim($option) === '') {
            $tables = $inspector->tables();
            if ($tables === []) { $this->fail('The database has no tables yet. Create one (php flo migrate), then run this again, or name it: --table=listings'); }
            if (!$this->input->isInteractive()) { $this->fail('Which table? Name it: --table=' . $tables[0] . ' (tables: ' . implode(', ', $tables) . ')'); }
            $option = $tables[(int) $this->choice('Which table should the page list?', array_combine(range(1, count($tables)), $tables) ?: []) - 1];
        }
        $option = trim($option);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $option) !== 1) { $this->invalid('The table must be a table name, e.g. --table=listings.'); }
        if ($inspector->columns($option) === null) {
            $closest = $inspector->closest($option);
            $this->fail('The table "' . $option . '" was not found in the database.' . ($closest !== null ? ' Did you mean --table=' . $closest . '?' : ' Tables: ' . implode(', ', $inspector->tables())));
        }
        return $inspector->plan($option) + ['unread' => false, 'labels' => [], 'resourceSlug' => null];
    }

    /**
     * What the table shows from a resource: its table, its list's columns and labels, rows linked to it.
     *
     * @return array<string, mixed>
     */
    private function resourcePlan(\FloCMS\CLI\Project $project, string $option): array
    {
        try { $project->boot(); } catch (\Throwable) {}
        \FloCMS\Admin\Admin::setRoot($project->root());
        $wanted = strtolower(ltrim($option, '\\'));
        $found = null;
        foreach (\FloCMS\Admin\Admin::registry()->all() as $class) {
            $short = strtolower(substr($class, (int) strrpos($class, '\\') + 1));
            // "Listing", "ListingResource", the full class name or the slug ("listings")
            if (in_array($wanted, [strtolower($class), $short, (string) preg_replace('/resource$/', '', $short), strtolower($class::getSlug())], true)) { $found = $class; break; }
        }
        if ($found === null) {
            $names = array_map(static fn (string $c): string => substr($c, (int) strrpos($c, '\\') + 1), array_values(\FloCMS\Admin\Admin::registry()->all()));
            $this->fail('The resource "' . $option . '" was not found.' . ($names !== [] ? ' Resources: ' . implode(', ', $names) : ' Make one with php flo make:admin-resource ' . $option));
        }
        /** @var class-string<\FloCMS\Admin\Resource> $found */
        $resource = new $found();
        $definition = $resource->table(new \FloCMS\Admin\Table\Table())->prepare($resource->form(new \FloCMS\Admin\Form\Form()));
        $columns = [];
        $labels = [];
        $left = [];
        foreach ($definition->getColumns() as $name => $column) {
            $class = (new \ReflectionClass($column))->getShortName();
            if (!in_array($class, ['TextColumn', 'DateColumn', 'TimeColumn', 'BooleanColumn', 'BadgeColumn'], true) || !$column->isDatabaseColumn()) { $left[] = $name . ' (' . $class . ': add it by hand)'; continue; }
            $columns[] = ['class' => $class === 'BadgeColumn' ? 'TextColumn' : $class, 'name' => (string) $name, 'searchable' => $column->isSearchable(), 'sortable' => $column->isSortable(), 'translatable' => $column->isTranslatable()];
            $label = (new \ReflectionProperty($column, 'label'))->getValue($column);
            if (is_string($label) && $label !== '') { $labels[(string) $name] = $label; }
            if (count($columns) >= TableInspector::MAX_COLUMNS) { break; }
        }
        $table = $found::getTable();
        $plan = ['table' => $table, 'primary' => $found::getPrimaryKey(), 'columns' => $columns !== [] ? $columns : [['class' => 'TextColumn', 'name' => $found::getPrimaryKey(), 'searchable' => false, 'sortable' => true, 'translatable' => false]],
            'date' => null, 'choice' => null, 'left' => $left, 'unread' => false, 'labels' => $labels, 'resourceSlug' => $found::getSlug()];
        // Filters from the table's values, when the database can be read.
        try {
            $inspected = (new TableInspector($project->database()))->plan($table);
            $plan['date'] = $inspected['date'];
            $plan['choice'] = $inspected['choice'];
        } catch (\Throwable) {
        }
        return $plan;
    }

    /** @return array<string, mixed> */
    private static function emptyPlan(string $table): array
    {
        return ['table' => $table, 'primary' => 'id', 'columns' => [['class' => 'TextColumn', 'name' => 'id', 'searchable' => false, 'sortable' => true, 'translatable' => false]],
            'date' => null, 'choice' => null, 'left' => [], 'labels' => [], 'resourceSlug' => null];
    }

    /**
     * @param array<string, mixed>|null $plan
     * @return array{0: string, 1: string} use lines and the class body
     */
    private function body(bool $view, bool $form, ?array $plan, string $viewName): array
    {
        $uses = ['FloCMS\\Admin\\AdminPage'];
        $parts = [];
        if ($plan !== null) {
            [$tableUses, $code] = self::tableCode($plan);
            $uses = array_merge($uses, $tableUses);
            $parts[] = $code;
        }
        if ($view) {
            $parts[] = <<<PHP
    /** The page's own view: views/{$viewName}.html. */
    public function view(): ?string
    {
        return '{$viewName}';
    }

    /** The view's variables; \$filters holds the filters' values. */
    public function data(array \$filters): array
    {
        return ['count' => 0, 'heading' => 'Hello'];
    }
PHP;
        }
        if ($form) {
            if (!in_array('FloCMS\\Admin\\Form\\Form', $uses, true)) { $uses[] = 'FloCMS\\Admin\\Form\\Form'; }
            $uses[] = 'FloCMS\\Admin\\Form\\Fields\\TextInput';
            $parts[] = <<<'PHP'
    protected static string $submitLabel = 'Save';

    /** A form with Save: the same fields and checks as a resource's form. */
    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('rate')->label('Rate')->numeric()->required(),
        ]);
    }

    /** The form's starting values, by field name. */
    public function fill(): array
    {
        return ['rate' => setting('tools.rate')];
    }

    /**
     * The checked values, by field name: save them anywhere. Throw a
     * ValidationException to show an error on a field; return a message to show.
     */
    public function submit(array $data): ?string
    {
        // e.g. $db = \FloCMS\Core\App::db(); $db->table('rates')->insert($data);
        return 'Saved.';
    }
PHP;
        }
        if (!$view && !$form && $plan === null) {
            $parts[] = <<<'PHP'
    /** Dashboard widgets shown on the page (classes made with php flo make:admin-widget). */
    public function widgets(): array
    {
        return [];
    }
PHP;
        }
        $uses = array_values(array_unique($uses));
        sort($uses);
        $imports = "\n" . implode("\n", array_map(static fn (string $use): string => 'use ' . $use . ';', $uses)) . "\n";
        return [$imports, "\n" . implode("\n\n", $parts)];
    }

    /**
     * The filters() and table() of a plan.
     *
     * @param array<string, mixed> $plan
     * @return array{0: list<string>, 1: string}
     */
    private static function tableCode(array $plan): array
    {
        $q = static fn (string $text): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $text) . "'";
        $uses = ['FloCMS\\Admin\\Form\\Form', 'FloCMS\\Admin\\Table\\Table', 'FloCMS\\Core\\Database'];
        $filters = [];
        $conditions = [];
        if ($plan['date'] !== null) {
            $uses[] = 'FloCMS\\Admin\\Form\\Fields\\Date';
            $filters[] = "            Date::make('from')->label('From'),";
            $filters[] = "            Date::make('to')->label('To'),";
            $conditions[] = "                if (isset(\$filters['from'])) { \$query = \$query->where(" . $q($plan['date']) . ", '>=', \$filters['from']); }";
            $conditions[] = "                if (isset(\$filters['to'])) { \$query = \$query->where(" . $q($plan['date']) . ", '<=', \$filters['to'] . ' 23:59:59'); }";
        }
        if ($plan['choice'] !== null) {
            $uses[] = 'FloCMS\\Admin\\Form\\Fields\\Select';
            $options = implode(', ', array_map(static fn (string $value, string $label): string => $q($value) . ' => ' . $q($label), array_keys($plan['choice']['options']), $plan['choice']['options']));
            $filters[] = '            Select::make(' . $q($plan['choice']['column']) . ')->options([' . $options . ']),';
            $conditions[] = "                if (isset(\$filters[" . $q($plan['choice']['column']) . "])) { \$query = \$query->where(" . $q($plan['choice']['column']) . ", '=', \$filters[" . $q($plan['choice']['column']) . "]); }";
        }
        $columnLines = [];
        foreach ($plan['columns'] as $column) {
            $uses[] = 'FloCMS\\Admin\\Table\\Columns\\' . $column['class'];
            $line = '                ' . $column['class'] . '::make(' . $q($column['name']) . ')';
            if (isset($plan['labels'][$column['name']])) { $line .= '->label(' . $q($plan['labels'][$column['name']]) . ')'; }
            if ($column['translatable']) { $line .= '->translatable()'; }
            if ($column['searchable']) { $line .= '->searchable()'; }
            if ($column['sortable']) { $line .= '->sortable()'; }
            $columnLines[] = $line . ',' . ($column['translatable'] && $plan['resourceSlug'] === null ? ' // translatable: its values are {"en": …}' : '');
        }
        $code = '';
        if ($filters !== []) {
            $code .= "    /** Filters at the top: their values are kept in the address and reach data(), table() and widgets. */\n"
                . "    public function filters(Form \$form): Form\n    {\n        return \$form->schema([\n" . implode("\n", $filters) . "\n        ]);\n    }\n\n";
        }
        $source = $plan['resourceSlug'] !== null ? 'the resource ' . $plan['resourceSlug'] : 'the table ' . $plan['table'];
        $code .= "    /** A read-only table of " . $source . ": search, sort, pages, column choice and a CSV download. */\n"
            . "    public function table(Table \$table): Table\n    {\n        return \$table\n"
            . "            ->query(function (Database \$db, array \$filters): Database {\n"
            . "                \$query = \$db->table(" . $q($plan['table']) . ");\n"
            . ($conditions !== [] ? implode("\n", $conditions) . "\n" : '')
            . "                return \$query;\n            })\n"
            . "            ->columns([\n" . implode("\n", $columnLines) . "\n            ])\n";
        if ($plan['resourceSlug'] !== null) {
            $uses[] = 'FloCMS\\Admin\\Admin';
            $code .= "            ->url(fn (object \$row): string => Admin::url(" . $q($plan['resourceSlug'] . '/edit/') . " . \$row->" . $plan['primary'] . "))\n";
        }
        $code .= "            ->defaultSort(" . $q($plan['primary']) . ", 'desc');\n    }";
        return [array_values(array_unique($uses)), $code];
    }
}
