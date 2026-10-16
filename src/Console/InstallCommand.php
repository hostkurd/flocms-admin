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

    protected string $help = 'Creates config/admin.php (never overwrites it), the admin/Resources/ folder for your resource classes, and the migrations of the settings and preferences tables (once). Running it again only adds what is missing.';

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

        // Tables of the settings pages and of personal preferences (written once)
        \FloCMS\Admin\Admin::setRoot($project->root());
        $table = \FloCMS\Admin\Admin::config()->settingsTable();
        foreach (['settings-migration' => $table, 'preferences-migration' => 'admin_user_preferences'] as $stub => $name) {
            $existing = glob($project->path('database/migrations') . DIRECTORY_SEPARATOR . '*_create_' . $name . '_table.php') ?: [];
            if ($existing !== []) {
                $this->note('A migration for "' . $name . '" already exists: ' . basename($existing[0]));
                continue;
            }
            $migration = 'database/migrations/' . date('Y_m_d_His') . '_create_' . $name . '_table.php';
            if (Stub::write($project->path($migration), Stub::render($stub, ['table' => $name]))) {
                $this->success('Created ' . $migration);
            } else {
                $this->fail('Could not write ' . $migration . ': check the folder permissions.');
            }
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. php flo migrate                              (settings and preferences tables)');
        $this->line('  2. php flo make:admin-resource Post --migration');
        $this->line('  3. php flo make:admin-settings                  (website settings pages, optional)');
        $this->line('  4. Log in, then open /admin/dashboard');
        $this->newLine();
        $this->line('On skeleton 1.8+, /admin opens the dashboard by itself. On older skeletons, link it');
        $this->line('from views/pages/admin_index.html:');
        $this->line('  <a href="{{ SITE_URI . \'/\' . ACTIVE_LANG . \'/admin/dashboard\' }}">Admin panel</a>');
        $this->newLine();
        $this->line('Check the setup at any time with: php flo admin:doctor');

        return self::SUCCESS;
    }
}
