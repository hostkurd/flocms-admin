<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\CLI\Console\Command;

/**
 * php flo make:admin-settings
 */
final class MakeSettingsCommand extends Command
{
    protected string $name = 'make:admin-settings';

    protected string $description = 'Create admin/settings.php with example settings pages (General, Contact)';

    protected string $help = 'Writes admin/settings.php (or the file set as settings_path in config/admin.php) when it does not exist yet. Values are read on the website with setting(\'site.title\').';

    protected function handle(): int
    {
        $project = $this->project();
        Admin::setRoot($project->root());
        $file = Admin::config()->settingsPath();
        $relative = ltrim(str_replace([str_replace('\\', '/', $project->root()), '\\'], ['', '/'], str_replace('\\', '/', $file)), '/');

        if (is_file($file)) {
            $this->note($relative . ' already exists: add pages to it with SettingsPage::make(...).');
            return self::SUCCESS;
        }
        if (!Stub::write($file, Stub::render('settings', []))) {
            $this->fail('Could not write ' . $relative . ': check the folder permissions.');
        }
        $this->success('Created ' . $relative);
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. php flo migrate            (creates the settings table, if not done yet)');
        $this->line('  2. Open /admin/settings');
        $this->line("  3. In a template: {{ setting('site.title') }}");

        return self::SUCCESS;
    }
}
