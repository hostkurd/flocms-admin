<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Support\Str;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo make:admin-resource Property [--table=properties] [--slug=properties] [--form=modal] [--migration] [--force]
 */
final class MakeResourceCommand extends Command
{
    protected string $name = 'make:admin-resource';

    protected string $description = 'Create an admin resource class in admin/Resources/';

    protected array $examples = [
        'php flo make:admin-resource Property',
        'php flo make:admin-resource Property --migration',
        'php flo make:admin-resource BlogPost --table=posts --slug=posts',
        'php flo make:admin-resource City --form=modal',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'Singular name, e.g. Property or BlogPost')
            ->option('table', 't', Option::VALUE_REQUIRED, 'Database table (default: plural snake case of the name)')
            ->option('slug', 's', Option::VALUE_REQUIRED, 'URL segment (default: the table with hyphens)')
            ->option('form', null, Option::VALUE_REQUIRED, 'How Create and Edit open: page (default), modal or slide-over')
            ->option('migration', 'm', Option::VALUE_NONE, 'Also create a migration for the table')
            ->option('force', 'f', Option::VALUE_NONE, 'Overwrite an existing resource file');
    }

    protected function handle(): int
    {
        $name = preg_replace('/Resource$/', '', trim((string) $this->argument('name'))) ?? '';
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->invalid('The name must be a class name in StudlyCase, e.g. Property or BlogPost.');
        }

        $snake = str_replace('-', '_', Str::kebab($name));
        $table = (string) ($this->option('table') ?? Str::plural($snake));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            $this->invalid('Invalid table name "' . $table . '".');
        }

        $slug = (string) ($this->option('slug') ?? str_replace('_', '-', strtolower($table)));
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            $this->invalid('Invalid slug "' . $slug . '": use lower case letters, digits and single hyphens.');
        }

        $formMode = (string) ($this->option('form') ?? 'page');
        if (!in_array($formMode, ['page', 'modal', 'slide-over'], true)) {
            $this->invalid('Invalid --form "' . $formMode . '": use page, modal or slide-over.');
        }

        $project = $this->project();
        $configFile = $project->path('config/admin.php');
        $config = is_file($configFile) ? (static fn (string $f): mixed => require $f)($configFile) : [];
        $config = is_array($config) ? $config : [];
        $namespace = trim((string) ($config['resources_namespace'] ?? 'App\\Admin\\Resources'), '\\');
        $directory = trim(str_replace('\\', '/', (string) ($config['resources_path'] ?? 'admin/Resources')), '/');

        $class = $name . 'Resource';
        $file = $project->path($directory . '/' . $class . '.php');
        if (is_file($file) && !$this->option('force')) {
            $this->fail($directory . '/' . $class . '.php already exists (use --force to overwrite).');
        }

        $withMigration = (bool) $this->option('migration');
        $label = Str::headline($snake);
        $content = Stub::render('resource', [
            'namespace' => $namespace,
            'class' => $class,
            'table' => $table,
            'slug' => $slug,
            'timestamps' => $withMigration ? 'true' : 'false',
            'formMode' => $formMode,
            'label' => ucfirst(strtolower($label)),
            'pluralLabel' => Str::headline($slug),
            'dateColumn' => $withMigration ? "\n                DateColumn::make('updated_at')->sortable()->toggleable()," : '',
        ]);
        if (!$withMigration) {
            $content = str_replace("use FloCMS\\Admin\\Table\\Columns\\DateColumn;\n", '', $content);
        }

        if (!Stub::write($file, $content)) {
            $this->fail('Could not write ' . $directory . '/' . $class . '.php.');
        }
        $this->success('Created ' . $directory . '/' . $class . '.php (URL: /admin/' . $slug . ', table: ' . $table . ')');

        if (class_exists(Str::appControllerClass($slug)) || in_array($slug, ['users', 'pages'], true)) {
            $this->warn('The application already has a controller for "' . $slug . '", which wins over the admin. Use --slug to pick another URL.');
        }

        if ($withMigration) {
            $existing = glob($project->path('database/migrations') . DIRECTORY_SEPARATOR . '*_create_' . $table . '_table.php') ?: [];
            if ($existing !== []) {
                $this->note('A migration for "' . $table . '" already exists: ' . basename($existing[0]));
            } else {
                $migration = 'database/migrations/' . date('Y_m_d_His') . '_create_' . $table . '_table.php';
                if (!Stub::write($project->path($migration), Stub::render('migration', ['table' => $table, 'class' => $class]))) {
                    $this->fail('Could not write ' . $migration . '.');
                }
                $this->success('Created ' . $migration . ' (run: php flo migrate)');
            }
        }

        if (!is_file($configFile)) {
            $this->note('config/admin.php is missing: run php flo admin:install to switch the panel on.');
        }

        return self::SUCCESS;
    }
}
