<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Support\Str;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo make:admin-block LatestProjects [--force]
 *
 * Writes the block's class (admin/Blocks/LatestProjects.php) and its template
 * (views/blocks/latest_projects.html), and registers the class in config/admin.php.
 */
final class MakeBlockCommand extends Command
{
    use RegistersInConfig;

    protected string $name = 'make:admin-block';

    protected string $description = 'Create a block for the Builder field: its class in admin/Blocks/ and its template in views/blocks/';

    protected array $examples = [
        'php flo make:admin-block LatestProjects',
        'php flo make:admin-block Testimonials --force',
    ];

    protected function configure(Definition $definition): void
    {
        $definition
            ->argument('name', Argument::REQUIRED, 'Class name, e.g. LatestProjects')
            ->option('force', 'f', Option::VALUE_NONE, 'Overwrite the class and the template if they exist');
    }

    protected function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->invalid('The name must be a class name in StudlyCase, e.g. LatestProjects.');
        }
        $name = preg_replace('/Block$/', '', $name) ?: $name;
        $type = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name));
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $type) !== 1) {
            $this->invalid('The name gives the type "' . $type . '", which is too long; use a shorter name.');
        }
        $core = array_map(static fn (string $class): string => $class::type(), \FloCMS\Admin\Blocks\BlockRegistry::CORE);
        if (in_array($type, $core, true)) {
            $this->warn('"' . $type . '" is a block of the panel: yours will replace it everywhere.');
        }

        $project = $this->project();
        $configFile = $project->path('config/admin.php');
        $config = is_file($configFile) ? (static fn (string $f): mixed => require $f)($configFile) : [];
        $config = is_array($config) ? $config : [];
        $namespace = trim((string) ($config['blocks_namespace'] ?? 'App\\Admin\\Blocks'), '\\');
        $directory = trim(str_replace('\\', '/', (string) ($config['blocks_path'] ?? 'admin/Blocks')), '/');

        $file = $directory . '/' . $name . '.php';
        $template = 'views/blocks/' . $type . '.html';
        $force = (bool) $this->option('force');
        foreach ([$file, $template] as $path) {
            if (is_file($project->path($path)) && !$force) { $this->fail($path . ' already exists (use --force to overwrite).'); }
        }
        $label = ucfirst(strtolower(Str::headline($name)));
        $replace = ['namespace' => $namespace, 'class' => $name, 'type' => $type, 'label' => $label, 'css' => str_replace('_', '-', $type),
            'heading_var' => '{{ $heading }}', 'item_var' => '{{ (string) $item }}'];
        if (!Stub::write($project->path($file), Stub::render('block', $replace))) { $this->fail('Could not write ' . $file . '.'); }
        $this->success('Created ' . $file);
        if (!Stub::write($project->path($template), Stub::render('block-template', $replace))) { $this->fail('Could not write ' . $template . '.'); }
        $this->success('Created ' . $template);

        $this->register($configFile, $namespace . '\\' . $name, 'blocks');
        $this->note('Offer it in a Builder: Builder::make(\'content\') (all blocks) or ->blocks([..., \'' . $type . '\']). Check it with: php flo admin:doctor');

        return self::SUCCESS;
    }
}
