<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Media\MediaUsage;
use FloCMS\CLI\Console\Command;

/**
 * php flo admin:media-usage — rebuild "Used in" of the media library from the
 * stored records (for records changed with SQL or before 0.17).
 */
final class MediaUsageCommand extends Command
{
    protected string $name = 'admin:media-usage';

    protected string $description = 'Rebuild where media library items are used, from every resource and settings page';

    protected function handle(): int
    {
        $project = $this->project();
        $project->boot();
        Admin::setRoot($project->root());
        try {
            \FloCMS\Core\App::$db = $project->database();
        } catch (\Throwable $error) {
            $this->fail('No database: ' . $error->getMessage());
        }
        if (!MediaLibrary::enabled()) {
            $this->fail('The media library is not set up: run php flo admin:install and php flo migrate.');
        }
        $counts = MediaUsage::rebuild();
        if ($counts === []) {
            $this->note('No resource or settings page has a MediaPicker or RichEditor field.');
        }
        foreach ($counts as $owner => $count) {
            $this->line('  ' . $owner . ': ' . $count . ($owner === 'settings' ? ' page(s)' : ' record(s)'));
        }
        $this->success('Usage rebuilt.');
        return self::SUCCESS;
    }
}
