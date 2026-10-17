<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Media\MediaUsage;
use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo admin:media-import [--dry-run] — add images and videos already in the
 * media folder (rich text uploads made before 0.17) to the library. Nothing is
 * moved, renamed or resized; imported images have no thumb version.
 */
final class MediaImportCommand extends Command
{
    private const TYPES = ['image/jpeg' => 'image', 'image/png' => 'image', 'image/gif' => 'image', 'image/webp' => 'image', 'video/mp4' => 'video', 'video/webm' => 'video'];

    protected string $name = 'admin:media-import';

    protected string $description = 'Add the images and videos already in the media folder to the media library, then rebuild where they are used';

    protected array $examples = ['php flo admin:media-import --dry-run', 'php flo admin:media-import'];

    protected function configure(Definition $definition): void
    {
        $definition->option('dry-run', null, Option::VALUE_NONE, 'Only list what would be added');
    }

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
        if (MediaLibrary::status() === 'upgrade') {
            $this->fail('The media library needs its 0.18 migration (folders): run php flo admin:install and php flo migrate.');
        }
        if (!MediaLibrary::enabled()) {
            $this->fail('The media library is not set up: run php flo admin:install and php flo migrate.');
        }
        $root = rtrim(str_replace('\\', '/', Admin::config()->media()['root']), '/');
        if (!is_dir($root)) {
            $this->note('The media folder ' . $root . ' does not exist: nothing to import.');
            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');
        $store = MediaLibrary::store();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $added = 0;
        $skipped = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) { continue; }
            $path = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
            // Thumb versions belong to their web version; never import them as items of their own.
            if (preg_match('~(^|/)thumb/~', $path) === 1 || str_starts_with(basename($path), '.')) { continue; }
            $kind = self::TYPES[(string) $finfo->file($file->getPathname())] ?? null;
            if ($kind === null) { continue; }
            if ($store->hasPath($path)) { $skipped++; continue; }
            if ($dry) { $this->line('  would add ' . $path); $added++; continue; }
            $size = $kind === 'image' ? @getimagesize($file->getPathname()) : false;
            $store->create(['kind' => $kind, 'path' => $path, 'thumb_path' => null, 'mime' => (string) $finfo->file($file->getPathname()),
                'bytes' => (int) $file->getSize(), 'width' => is_array($size) ? (int) $size[0] : null, 'height' => is_array($size) ? (int) $size[1] : null,
                'file_name' => mb_substr($file->getFilename(), 0, 190), 'hash' => hash_file('sha256', $file->getPathname()) ?: null]);
            $added++;
        }
        // Fingerprints for items added before 0.18, so later uploads of the same file are noticed.
        // (Images uploaded through the library keep the fingerprint of the original file; for these
        // older ones only the stored file is left, which a later upload of the original won't match.)
        $fingerprinted = 0;
        if (!$dry) {
            foreach ($store->connection()->table(MediaLibrary::table())->whereRaw('`hash` IS NULL', [])->get() as $row) {
                $absolute = $root . '/' . ltrim((string) $row->path, '/');
                if (str_contains((string) $row->path, '..') || !is_file($absolute)) { continue; }
                $hash = hash_file('sha256', $absolute);
                if ($hash !== false) { $store->setHash((int) $row->id, $hash); $fingerprinted++; }
            }
        }
        if ($dry) {
            $this->success($added . ' file(s) would be added; ' . $skipped . ' already in the library.');
            return self::SUCCESS;
        }
        $this->success($added . ' file(s) added; ' . $skipped . ' already in the library.');
        if ($fingerprinted > 0) { $this->line('  Fingerprints added to ' . $fingerprinted . ' item(s), for the duplicate check.'); }
        $counts = MediaUsage::rebuild();
        $this->line('  Usage rebuilt for ' . count($counts) . ' resource(s)/settings.');
        return self::SUCCESS;
    }
}
