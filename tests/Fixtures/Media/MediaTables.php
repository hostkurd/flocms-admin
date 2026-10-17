<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Media;

use FloCMS\Core\Database;

/** Builds the media library tables with the real migration admin:install writes. */
final class MediaTables
{
    public static function create(Database $db, string $table = 'media', string $usage = 'media_usage'): void
    {
        $db->query('DROP TABLE IF EXISTS `' . $usage . '`');
        $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        self::migration($table, $usage)->up($db);
    }

    public static function migration(string $table = 'media', string $usage = 'media_usage'): object
    {
        $file = sys_get_temp_dir() . '/flo-admin-media-migration-' . $table . '-' . $usage . '-' . getmypid() . '.php';
        file_put_contents($file, \FloCMS\Admin\Console\Stub::render('media-migration', ['table' => $table, 'usage_table' => $usage]));
        try { return require $file; } finally { @unlink($file); }
    }

    /**
     * Four items (1–3 images, 4 a video) with real files under $root, so deletes can be checked.
     *
     * @return array<int, list<string>> id => its files (relative to $root)
     */
    public static function seed(Database $db, ?string $root = null): array
    {
        $rows = [
            ['image', 'library/2026/10/web/sea.jpg', 'library/2026/10/thumb/sea.jpg', 'image/jpeg', 1200, 800, 'sea-view.jpg', 'Sea view', '{"en":"Sea at sunset","ar":"البحر عند الغروب"}'],
            ['image', 'library/2026/10/web/garden.png', 'library/2026/10/thumb/garden.png', 'image/png', 300, 200, 'garden.png', '', null],
            ['image', 'library/2026/10/web/hall.webp', null, 'image/webp', 1920, 1080, 'hall.webp', 'Hall', '{"en":"Entrance hall"}'],
            ['video', 'library/2026/10/tour.mp4', null, 'video/mp4', null, null, 'tour.mp4', 'Tour', null],
        ];
        $files = [];
        foreach ($rows as $index => [$kind, $path, $thumb, $mime, $w, $h, $name, $title, $alt]) {
            $db->query('INSERT INTO media (kind, path, thumb_path, mime, bytes, width, height, file_name, title, alt, uploaded_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$kind, $path, $thumb, $mime, 1000 + $index, $w, $h, $name, $title, $alt, 1, '2026-10-1' . $index . ' 10:00:00', '2026-10-1' . $index . ' 10:00:00']);
            $files[$index + 1] = array_values(array_filter([$path, $thumb]));
            if ($root !== null) {
                foreach ($files[$index + 1] as $file) {
                    if (!is_dir(dirname($root . '/' . $file))) { mkdir(dirname($root . '/' . $file), 0777, true); }
                    file_put_contents($root . '/' . $file, 'x');
                }
            }
        }
        return $files;
    }
}
