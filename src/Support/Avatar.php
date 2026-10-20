<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

use FloCMS\Admin\Admin;
use FloCMS\Admin\AdminConfig;

/**
 * A person's avatar (0.28): their photo, or the first letter of their name in
 * one of six colours picked from their id, so a person keeps the same colour
 * everywhere (lists, the top bar, Activity).
 *
 *     Avatar::html('Lana Karim', $user->image, $user->id)
 */
final class Avatar
{
    public const COLORS = 6;

    /** @var array<int, array{name: string, image: string}>|null users by id, loaded as needed */
    private static ?array $users = null;

    /**
     * @param string|null $image a stored public upload, a path under public/, or an http(s) URL
     * @param int|string|null $seed what picks the colour (the user id); null: from the name
     */
    public static function html(string $name, ?string $image, int|string|null $seed, int $size = 36, string $class = ''): string
    {
        $classes = trim('fa-avatar fa-avatar-size ' . $class);
        $style = $size !== 36 ? ' data-size="' . $size . '"' : '';
        $url = self::url($image);
        if ($url !== null) {
            return '<img class="' . Str::e($classes . ' fa-avatar-img') . '" src="' . Str::e($url) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async"' . $style . '>';
        }
        return '<span class="' . Str::e($classes . ' fa-avatar-c' . self::color($seed ?? $name)) . '" aria-hidden="true"' . $style . '>' . Str::e(self::initial($name)) . '</span>';
    }

    /** The first letter of the name, upper case where the script has one; "?" without a name. */
    public static function initial(string $name): string
    {
        $name = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $name) ?? '');
        if ($name === '') { return '?'; }
        return mb_strtoupper(mb_substr($name, 0, 1));
    }

    /** 1–6, the same for the same seed. */
    public static function color(int|string $seed): int
    {
        return (int) (crc32((string) $seed) % self::COLORS) + 1;
    }

    /**
     * Where the image can be loaded from, or null for the letter: a public
     * upload of the panel, a file under the site's public/ folder, or an
     * http(s) address. Anything else (a private path, a missing file) is null.
     */
    public static function url(?string $image): ?string
    {
        $image = trim((string) $image);
        if ($image === '' || strlen($image) > 500) { return null; }
        if (preg_match('~^https?://[^\s"\'<>]+$~i', $image) === 1) { return $image; }
        if (str_contains($image, '..') || str_contains($image, '\\') || preg_match('/\.(jpe?g|png|gif|webp)$/iD', $image) !== 1) { return null; }
        $relative = ltrim($image, '/');
        $public = Admin::config()->publicUploads();
        if (is_file($public['root'] . '/' . $relative)) { return $public['url'] . '/' . $relative; }
        if (is_file(rtrim(Admin::config()->root(), '/') . '/public/' . $relative)) { return AdminConfig::sitePath() . '/' . $relative; }
        return null;
    }

    /** The avatar of a user by id (from the users table), with $fallback as the name when there is no such user. */
    public static function forUser(?int $id, string $fallback = '', int $size = 36, string $class = ''): string
    {
        if ($id === null || $id <= 0) { return self::html($fallback, null, $fallback === '' ? 0 : $fallback, $size, $class); }
        $user = self::user($id);
        return self::html($user['name'] !== '' ? $user['name'] : $fallback, $user['image'], $id, $size, $class);
    }

    /** Load these users' names and photos in one query, before showing their avatars. */
    public static function preload(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0 && !isset(self::$users[$id]))));
        if ($ids === []) { return; }
        self::$users ??= [];
        foreach ($ids as $id) { self::$users[$id] = ['name' => '', 'image' => '']; }
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (\FloCMS\Core\App::db()?->table('users')->select(['id', 'full_name', 'image'])->whereIn('id', $chunk)->get() ?? [] as $row) {
                    self::$users[(int) $row->id] = ['name' => (string) ($row->full_name ?? ''), 'image' => (string) ($row->image ?? '')];
                }
            }
        } catch (\Throwable) {
            // A users table without an image column: letters only.
        }
    }

    /** @return array{name: string, image: string} */
    private static function user(int $id): array
    {
        if (!isset(self::$users[$id])) { self::preload([$id]); }
        return self::$users[$id] ?? ['name' => '', 'image' => ''];
    }

    /** Forget the loaded users (after a photo changed, and in tests). */
    public static function reset(): void
    {
        self::$users = null;
    }
}
