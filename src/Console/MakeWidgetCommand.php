<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Support\Str;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo make:admin-widget PostStats [--type=stats|records|chart] [--resource=Post] [--force]
 */
final class MakeWidgetCommand extends Command
{
    use RegistersInConfig;

    private const TYPES = ['stats', 'records', 'chart'];

    protected string $name = 'make:admin-widget';

    protected string $description = 'Create a dashboard widget in admin/Widgets/ and register it in config/admin.php';

    protected array $examples = [
        'php flo make:admin-widget PostStats --resource=Post',
        'php flo make:admin-widget RecentPosts --type=records --resource=Post',
        'php flo make:admin-widget PostsChart --type=chart --resource=Post',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'Class name, e.g. PostStats or RecentPosts')
            ->option('type', 't', Option::VALUE_REQUIRED, 'stats (default), records or chart')
            ->option('resource', 'r', Option::VALUE_REQUIRED, 'The resource it reads, e.g. Post or PostResource (required for records)')
            ->option('force', 'f', Option::VALUE_NONE, 'Overwrite an existing widget file');
    }

    protected function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->invalid('The name must be a class name in StudlyCase, e.g. PostStats or RecentPosts.');
        }
        $type = strtolower((string) ($this->option('type') ?? 'stats'));
        if (!in_array($type, self::TYPES, true)) {
            $this->invalid('Unknown type "' . $type . '": use stats, records or chart.');
        }

        $project = $this->project();
        $configFile = $project->path('config/admin.php');
        $config = is_file($configFile) ? (static fn (string $f): mixed => require $f)($configFile) : [];
        $config = is_array($config) ? $config : [];
        $namespace = trim((string) ($config['widgets_namespace'] ?? 'App\\Admin\\Widgets'), '\\');
        $directory = trim(str_replace('\\', '/', (string) ($config['widgets_path'] ?? 'admin/Widgets')), '/');
        $resourcesNamespace = trim((string) ($config['resources_namespace'] ?? 'App\\Admin\\Resources'), '\\');
        $resourcesDirectory = trim(str_replace('\\', '/', (string) ($config['resources_path'] ?? 'admin/Resources')), '/');

        $resource = null;
        $option = $this->option('resource');
        if ($option !== null && $option !== '') {
            $resource = preg_replace('/Resource$/', '', ltrim(trim((string) $option), '\\')) . 'Resource';
            if (preg_match('/^[A-Z][A-Za-z0-9]*Resource$/', $resource) !== 1) {
                $this->invalid('The resource must be a class name such as Post or PostResource.');
            }
        } elseif ($type === 'records') {
            $this->invalid('A records widget lists one resource: add --resource=Post.');
        }

        $file = $directory . '/' . $name . '.php';
        if (is_file($project->path($file)) && !$this->option('force')) {
            $this->fail($file . ' already exists (use --force to overwrite).');
        }

        $short = $resource ?? '';
        $imports = $resource === null ? '' : "\nuse " . $resourcesNamespace . '\\' . $resource . ';';
        $title = ucfirst(strtolower(Str::headline(preg_replace('/Widget$/', '', $name) ?? $name)));
        $content = Stub::render('widget-' . $type, [
            'namespace' => $namespace,
            'imports' => $imports,
            'class' => $name,
            'title' => $title,
            'resources' => $resource === null ? '' : $short . '::class',
            'resource' => $short . '::class',
            'column' => 'title',
            'body' => $this->body($type, $short),
        ]);

        if (!Stub::write($project->path($file), $content)) {
            $this->fail('Could not write ' . $file . '.');
        }
        $this->success('Created ' . $file . ' (' . $type . ' widget)');

        if ($resource !== null && !is_file($project->path($resourcesDirectory . '/' . $resource . '.php'))) {
            $this->warn($resourcesDirectory . '/' . $resource . '.php was not found. Create it first: php flo make:admin-resource ' . substr($resource, 0, -8));
        }

        $this->register($configFile, $namespace . '\\' . $name, 'widgets');
        $this->note('Check it with: php flo admin:doctor');

        return self::SUCCESS;
    }

    private function body(string $type, string $resource): string
    {
        if ($type === 'stats') {
            return $resource === ''
                ? "        // Read records with \$context->query(PostResource::class) and list that resource in \$resources.\n"
                    . "        return [\n            Stat::make('Example', 0)->description('Replace with your own numbers'),\n        ];"
                : "        \$records = \$context->query({$resource}::class);\n\n"
                    . "        return [\n            Stat::make('Total', \$records->count())->resource({$resource}::class),\n"
                    . "            // Stat::make('Published', \$records->where('status', '=', 'published')->count())->color('success'),\n        ];";
        }

        return $resource === ''
            ? "        // Read records with \$context->query(PostResource::class) and list that resource in \$resources.\n"
                . "        return ['labels' => ['Mon', 'Tue', 'Wed'], 'values' => [3, 5, 2]];"
            : "        \$records = \$context->query({$resource}::class);\n\n"
                . "        // One bar per value; replace with your own groups, e.g. by status.\n"
                . "        return ['labels' => ['All'], 'values' => [\$records->count()]];";
    }
}
