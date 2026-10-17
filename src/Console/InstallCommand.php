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

    protected string $help = 'Creates config/admin.php (never overwrites it), the admin/Resources/ folder for your resource classes, and the migrations of the settings, preferences, media library, trash and activity log tables (once). Running it again only adds what is missing.';

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
        // (the settings table is FloCMS's own: a site that already has a migration for it keeps that one)
        // and of the media library (0.17), named as config/admin.php says ('media_library' => ['table' => …])
        $values = is_file($config) ? (static fn (string $file): mixed => require $file)($config) : [];
        $settings = new \FloCMS\Admin\AdminConfig(is_array($values) ? $values : [], $project->path(''), true);
        $library = $settings->mediaLibrary();
        $migrations = [
            ['settings-migration', \FloCMS\Admin\Settings\SettingsStore::TABLE, []],
            ['preferences-migration', 'admin_user_preferences', []],
        ];
        if ($library['enabled']) {
            $migrations[] = ['media-migration', $library['table'], ['usage_table' => $library['usage_table']]];
            // Folders, fingerprint and focal point (0.18): changes the media table, so it is written last.
            $migrations[] = ['media-folders-migration', $library['folders_table'], ['table' => $library['table'], 'folders_table' => $library['folders_table']]];
        }
        // The trash and the activity log (0.19), one migration for both tables.
        $trash = $settings->trash();
        $activity = $settings->activity();
        if ($trash['enabled'] || $activity['enabled']) {
            $migrations[] = ['trash-activity-migration', $trash['table'] . '_and_' . $activity['table'], ['trash_table' => $trash['table'], 'activity_table' => $activity['table']], 'tables'];
        }
        // Each new file a second later than the one before, so they run in this order.
        $time = time();
        foreach ($migrations as $migration) {
            [$stub, $name, $more] = $migration;
            $suffix = $migration[3] ?? 'table';
            $existing = glob($project->path('database/migrations') . DIRECTORY_SEPARATOR . '*_create_' . $name . '_' . $suffix . '.php') ?: [];
            if ($existing !== []) {
                $this->note('A migration for "' . $name . '" already exists: ' . basename($existing[0]));
                continue;
            }
            $file = 'database/migrations/' . date('Y_m_d_His', $time++) . '_create_' . $name . '_' . $suffix . '.php';
            if (Stub::write($project->path($file), Stub::render($stub, $more + ['table' => $name]))) {
                $this->success('Created ' . $file);
            } else {
                $this->fail('Could not write ' . $file . ': check the folder permissions.');
            }
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. php flo migrate                              (settings, preferences, media library, trash and activity tables)');
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
