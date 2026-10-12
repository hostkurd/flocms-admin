<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Languages;
use FloCMS\CLI\Console\Command;
use FloCMS\Core\Database;
use Throwable;

/**
 * php flo admin:doctor — checks the admin setup and every resource.
 */
final class DoctorCommand extends Command
{
    protected string $name = 'admin:doctor';

    protected string $description = 'Check the flocms-admin setup: config, resources, slugs, tables and the admin layout';

    private int $errors = 0;
    private int $warnings = 0;

    protected function handle(): int
    {
        $project = $this->project();
        $project->boot();
        Admin::setRoot($project->root());
        Languages::reset();

        $this->title('flocms-admin ' . Admin::VERSION);

        $config = Admin::config();
        if (!$config->isInstalled()) {
            $this->fail('config/admin.php not found: run php flo admin:install');
        }
        $this->ok('config/admin.php found');

        $languages = Languages::all();
        $this->ok('Languages: ' . implode(', ', $languages) . ' (default ' . Languages::default() . ')');

        $this->checkLayout();
        $this->checkPackagePages($config->usersEnabled());

        $registry = Admin::registry();
        foreach ($registry->problems() as $problem) {
            $this->bad($problem);
        }

        $resources = $registry->all();
        if ($resources === [] && $registry->problems() === []) {
            $this->caution('No resources yet: php flo make:admin-resource Post --migration');
        }

        $db = null;
        if ($resources !== []) {
            try {
                $db = $project->database();
                $db->query('SELECT 1');
                $this->ok('Database connection works');
            } catch (Throwable $e) {
                $this->bad('Database: ' . $e->getMessage());
            }
        }

        if ($config->usersEnabled() && $db === null) {
            try {
                $db = $project->database();
                $db->query('SELECT 1');
            } catch (Throwable $e) {
                $this->bad('Database: ' . $e->getMessage());
                $db = null;
            }
        }

        foreach ($resources as $slug => $class) {
            $this->checkResource($slug, $class, $db);
        }

        if ($config->usersEnabled() && ($users = $registry->find('accounts')) !== null) {
            $this->checkResource('accounts', $users, $db, ['is_verified', 'verify_type', 'token']);
        }

        $this->newLine();
        if ($this->errors > 0) {
            $this->error($this->errors . ' problem(s), ' . $this->warnings . ' warning(s).');
            return self::FAILURE;
        }

        $this->success($this->warnings > 0 ? 'Usable, with ' . $this->warnings . ' warning(s).' : 'Everything looks good.');

        return self::SUCCESS;
    }

    /**
     * @param class-string<Resource> $class
     * @param list<string> $extraColumns columns written besides the form fields
     */
    private function checkResource(string $slug, string $class, ?Database $db, array $extraColumns = []): void
    {
        $this->section($class . '  (/admin/' . $slug . ')');

        try {
            $resource = new $class();
            $form = $resource->form(new Form());
            $table = $resource->table(new Table());
        } catch (Throwable $e) {
            $this->bad('form() or table() fails: ' . $e->getMessage());
            return;
        }

        $this->ok('Permissions: ' . $class::permission('view') . ', ' . $class::permission('create') . ', '
            . $class::permission('update') . ', ' . $class::permission('delete'));

        if ($db === null) {
            return;
        }

        $columns = $this->columns($db, $class::getTable());
        if ($columns === null) {
            $this->bad('Table "' . $class::getTable() . '" not found (run php flo migrate?)');
            return;
        }
        $this->ok('Table "' . $class::getTable() . '" (' . count($columns) . ' columns)');

        $needed = [$class::getPrimaryKey() => 'primary key'];
        foreach ($form->writableFields() as $name => $field) {
            $needed[$name] = 'form field';
        }
        foreach ($table->getColumns() as $name => $column) {
            $needed[$name] ??= 'list column';
        }
        foreach ($extraColumns as $name) {
            $needed[$name] = 'set on create';
        }
        if ($class::usesTimestamps()) {
            $needed['created_at'] = 'timestamps';
            $needed['updated_at'] = 'timestamps';
        }

        foreach ($needed as $name => $why) {
            if (!array_key_exists(strtolower($name), $columns)) {
                $this->bad('Column "' . $name . '" (' . $why . ') is missing');
            }
        }

        foreach ($form->translatableNames() as $name) {
            $type = $columns[strtolower($name)] ?? null;
            if ($type !== null && preg_match('/json|text|longtext|mediumtext|clob/i', $type) !== 1) {
                $this->caution('Column "' . $name . '" is translatable but its type is ' . $type . ': use JSON (or a TEXT type)');
            }
        }
    }

    /** @return array<string, string>|null lower-case column name => type, null when the table does not exist */
    private function columns(Database $db, string $table): ?array
    {
        try {
            if ($db->driver() === 'sqlite') {
                $rows = $db->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(\PDO::FETCH_ASSOC);
                $columns = [];
                foreach ($rows as $row) {
                    $columns[strtolower((string) $row['name'])] = (string) $row['type'];
                }
                return $columns === [] ? null : $columns;
            }

            $rows = $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC);
            $columns = [];
            foreach ($rows as $row) {
                $columns[strtolower((string) $row['Field'])] = (string) $row['Type'];
            }

            return $columns;
        } catch (Throwable) {
            return null;
        }
    }

    /** The package's own pages, and whether /admin can open the dashboard. */
    private function checkPackagePages(bool $usersEnabled): void
    {
        $pages = ['dashboard' => 'Dashboard'];
        if ($usersEnabled) {
            $pages += ['accounts' => 'Users', 'profile' => 'Profile'];
        }
        foreach ($pages as $slug => $name) {
            $controller = \FloCMS\Admin\Support\Str::appControllerClass($slug);
            if (class_exists($controller)) {
                $this->bad($name . ' page (/admin/' . $slug . ') is taken by the application controller ' . $controller);
            }
        }
        $this->ok('Panel pages: /admin/' . implode(', /admin/', array_keys($pages)));

        $pages = (string) @file_get_contents($this->project()->path('controllers/PagesController.php'));
        if (!str_contains($pages, 'admin.home')) {
            $this->caution('/admin shows the skeleton\'s page (skeleton 1.8+ opens the dashboard): link /admin/dashboard from views/pages/admin_index.html');
        }
    }

    private function checkLayout(): void
    {
        $project = $this->project();
        $template = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $project->env('APP_TEMPLATE', 'default')) ?: 'default';

        foreach ([$template, 'default'] as $name) {
            $file = $project->path('templates/' . $name . '/layouts/admin.html');
            if (!is_file($file)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            if (!str_contains($content, "\$data['content']") && !str_contains($content, '$content')) {
                $this->bad('templates/' . $name . '/layouts/admin.html does not print $data[\'content\']: the panel would not show');
            } else {
                $this->ok('Admin layout: templates/' . $name . '/layouts/admin.html');
            }
            if (!str_contains($content, 'viewport')) {
                $this->caution('The admin layout has no <meta name="viewport">: the panel will not fit phones');
            }
            return;
        }

        $this->bad('No layouts/admin.html in templates/' . $template . '/ or templates/default/');
    }

    private function ok(string $text): void
    {
        $this->line('  <success>✓</success> ' . $text);
    }

    private function caution(string $text): void
    {
        $this->warnings++;
        $this->line('  <fg=yellow>!</> ' . $text);
    }

    private function bad(string $text): void
    {
        $this->errors++;
        $this->line('  <fg=red>✗</> ' . $text);
    }
}
