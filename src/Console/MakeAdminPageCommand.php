<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Support\Str;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo make:admin-page SalesReport [--view] [--form] [--table] [--group=Reports] [--force]
 */
final class MakeAdminPageCommand extends Command
{
    use RegistersInConfig;

    protected string $name = 'make:admin-page';

    protected string $description = 'Create a panel page of your own in admin/Pages/ and register it in config/admin.php';

    protected array $examples = [
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
            ->option('table', null, Option::VALUE_NONE, 'A read-only table from a query, with filters')
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
        $table = (bool) $this->option('table');

        $project = $this->project();
        $configFile = $project->path('config/admin.php');
        $config = is_file($configFile) ? (static fn (string $f): mixed => require $f)($configFile) : [];
        $config = is_array($config) ? $config : [];
        $namespace = trim((string) ($config['admin_pages_namespace'] ?? 'App\\Admin\\Pages'), '\\');
        $directory = trim(str_replace('\\', '/', (string) ($config['admin_pages_path'] ?? 'admin/Pages')), '/');

        $short = preg_replace('/(?<!^)Page$/', '', $name) ?? $name;
        $slug = Str::kebab($short);
        $file = $directory . '/' . $name . '.php';
        $viewName = 'admin/' . $slug;
        $viewFile = 'views/' . $viewName . '.html';
        if (is_file($project->path($file)) && !$this->option('force')) {
            $this->fail($file . ' already exists (use --force to overwrite).');
        }

        [$imports, $body] = $this->body($view, $form, $table, $viewName);
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
        if (!Stub::write($project->path($file), $content)) {
            $this->fail('Could not write ' . $file . '.');
        }
        $this->success('Created ' . $file);

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

    /** @return array{0: string, 1: string} use lines and the class body */
    private function body(bool $view, bool $form, bool $table, string $viewName): array
    {
        $uses = ['FloCMS\\Admin\\AdminPage'];
        $parts = [];
        if ($table) {
            $uses[] = 'FloCMS\\Admin\\Form\\Form';
            $uses[] = 'FloCMS\\Admin\\Form\\Fields\\Date';
            $uses[] = 'FloCMS\\Admin\\Table\\Table';
            $uses[] = 'FloCMS\\Admin\\Table\\Columns\\TextColumn';
            $uses[] = 'FloCMS\\Admin\\Table\\Columns\\DateColumn';
            $uses[] = 'FloCMS\\Core\\Database';
            $parts[] = <<<'PHP'
    /** Filters at the top: their values are kept in the address and reach data(), table() and widgets. */
    public function filters(Form $form): Form
    {
        return $form->schema([
            Date::make('from')->label('From'),
            Date::make('to')->label('To'),
        ]);
    }

    /** A read-only table from any query: search, sort, pages, column choice and a CSV download. */
    public function table(Table $table): Table
    {
        return $table
            ->query(function (Database $db, array $filters): Database {
                $query = $db->table('bookings'); // your table
                if (isset($filters['from'])) { $query = $query->where('created_at', '>=', $filters['from']); }
                if (isset($filters['to'])) { $query = $query->where('created_at', '<=', $filters['to'] . ' 23:59:59'); }
                return $query;
            })
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                DateColumn::make('created_at')->label('Date')->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
PHP;
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
        if (!$view && !$form && !$table) {
            $parts[] = <<<'PHP'
    /** Dashboard widgets shown on the page (classes made with php flo make:admin-widget). */
    public function widgets(): array
    {
        return [];
    }
PHP;
        }
        $imports = "\n" . implode("\n", array_map(static fn (string $use): string => 'use ' . $use . ';', $uses)) . "\n";
        return [$imports, "\n" . implode("\n\n", $parts)];
    }
}
