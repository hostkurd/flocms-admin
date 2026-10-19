<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;

/** Boot the project, the panel's root and the database for a command (0.25). @internal */
trait BootsPanel
{
    private function bootPanel(): void
    {
        $project = $this->project();
        $project->boot();
        Admin::setRoot($project->root());
        try {
            \FloCMS\Core\App::$db = $project->database();
        } catch (\Throwable $error) {
            $this->fail('No database: ' . $error->getMessage());
        }
    }

    /** The registered resource with this slug. */
    private function resourceFor(mixed $slug): Resource
    {
        $class = is_string($slug) ? Admin::registry()->find($slug) : null;
        if ($class === null) {
            $this->fail('No resource "' . (is_string($slug) ? $slug : '') . '". Registered: ' . implode(', ', array_keys(Admin::registry()->all())));
        }
        return new $class();
    }

    private function importStore(): \FloCMS\Admin\ImportExport\ImportStore
    {
        $status = \FloCMS\Admin\ImportExport\ImportStore::status();
        if ($status === 'off') { $this->fail('Imports are switched off in config/admin.php (\'import\' => false).'); }
        if ($status !== 'ready') { $this->fail('The import tables are missing: run php flo admin:install, then php flo migrate.'); }
        return \FloCMS\Admin\ImportExport\ImportStore::make();
    }

    /** Run batches until the run stops checking, importing or undoing. */
    private function drive(\FloCMS\Admin\ImportExport\Importer $importer, \FloCMS\Admin\ImportExport\ImportRun $run, string $label): \FloCMS\Admin\ImportExport\ImportRun
    {
        $total = max(1, $run->rowsTotal);
        $bar = $this->progress($total);
        $bar->start();
        while (in_array($run->status, ['checking', 'importing', 'undoing'], true)) {
            $before = $run->position;
            $run = $importer->step($run, 500);
            $bar->advance(max(0, $run->position - $before));
            if ($run->position === $before && in_array($run->status, ['checking', 'importing', 'undoing'], true)) { usleep(200000); }
        }
        $bar->finish();
        $this->newLine();
        $this->line($label);
        return $run;
    }
}
