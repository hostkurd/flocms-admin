<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo admin:blocks [--force=slider.html]
 *
 * Copies the default block templates to views/blocks/ and their styles and
 * scripts to public/assets/flo-blocks/ (and, with pages, the default page
 * template to views/page-templates/), so the site owns them. A file that is
 * already there is never replaced, except the one named with --force.
 */
final class BlocksCommand extends Command
{
    protected string $name = 'admin:blocks';

    protected string $description = 'Copy the block templates, styles and slider script into the site (never overwrites)';

    protected array $examples = [
        'php flo admin:blocks',
        'php flo admin:blocks --force=slider.html',
        'php flo admin:blocks --force=slider.js',
    ];

    protected function configure(Definition $definition): void
    {
        $definition->option('force', 'f', Option::VALUE_REQUIRED, 'Replace this one file (e.g. slider.html or blocks.css) with the package\'s');
    }

    protected function handle(): int
    {
        $project = $this->project();
        $package = dirname(__DIR__, 2);
        $force = basename(trim((string) ($this->option('force') ?? '')));
        $copied = 0;
        $kept = 0;
        $known = false;
        $groups = [
            ['from' => $package . '/resources/blocks', 'to' => 'views/blocks', 'pattern' => '*.html'],
            ['from' => $package . '/resources/site', 'to' => 'public/assets/flo-blocks', 'pattern' => '*.{css,js}'],
        ];
        // The default page template too, on a site with pages (0.30).
        \FloCMS\Admin\Admin::setRoot($project->root());
        if (\FloCMS\Admin\Admin::config()->pages()['enabled']) { $groups[] = ['from' => $package . '/resources/pages', 'to' => 'views/page-templates', 'pattern' => '*.html']; }
        foreach ($groups as $group) {
            foreach (glob($group['from'] . '/' . $group['pattern'], GLOB_BRACE) ?: [] as $source) {
                $name = basename($source);
                $target = $group['to'] . '/' . $name;
                if ($name === $force) { $known = true; }
                if (is_file($project->path($target)) && $name !== $force) { $kept++; continue; }
                if (!Stub::write($project->path($target), (string) file_get_contents($source))) { $this->fail('Could not write ' . $target . '.'); }
                $this->success(($name === $force ? 'Replaced ' : 'Copied ') . $target);
                $copied++;
            }
        }
        if ($force !== '' && !$known) { $this->invalid('There is no block file named "' . $force . '". Files: the templates (slider.html …, and default.html for pages), blocks.css, slider.css, slider.js, gallery.css, gallery.js.'); }
        if ($kept > 0) { $this->note($kept . ' file' . ($kept === 1 ? ' was' : 's were') . ' already there and kept (--force=<file> replaces one).'); }
        if ($copied > 0) {
            $this->note('They are yours now: panel updates never change them. Put {!! blocks_assets() !!} in your layout\'s <head>.');
        }
        return self::SUCCESS;
    }
}
