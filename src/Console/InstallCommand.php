<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\CLI\Console\Command;

/**
 * php flo admin:install
 */
final class InstallCommand extends Command
{
    protected string $name = 'admin:install';

    protected string $description = 'Set up flocms-admin: create config/admin.php and admin/Resources/';

    protected string $help = 'Creates config/admin.php (never overwrites it) and the admin/Resources/ folder for your resource classes. No other file of the site is changed.';

    protected function handle(): int
    {
        $project = $this->project();
        $config = $project->path('config/admin.php');

        if (is_file($config)) {
            $this->note('config/admin.php already exists: left as it is.');
        } elseif (Stub::write($config, Stub::render('config', []))) {
            $this->success('Created config/admin.php');
        } else {
            $this->fail('Could not write config/admin.php: check the folder permissions.');
        }

        $resources = $project->path('admin/Resources');
        if (is_dir($resources)) {
            $this->note('admin/Resources/ already exists.');
        } elseif (Stub::write($resources . '/.gitkeep', '')) {
            $this->success('Created admin/Resources/');
        } else {
            $this->fail('Could not create admin/Resources/: check the folder permissions.');
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. php flo make:admin-resource Post --migration');
        $this->line('  2. php flo migrate');
        $this->line('  3. Open /admin/posts (log in as an admin first)');
        $this->newLine();
        $this->line('Optional: link the panel from the dashboard (views/pages/admin_index.html):');
        $this->line('  <a href="{{ SITE_URI . \'/\' . ACTIVE_LANG . \'/admin/posts\' }}">Posts</a>');
        $this->newLine();
        $this->line('Check the setup at any time with: php flo admin:doctor');

        return self::SUCCESS;
    }
}
