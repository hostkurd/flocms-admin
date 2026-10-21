<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Admin\Admin;

/**
 * Previews (0.30): the page as the editor has it, saved or not, behind a
 * link with a random token that works for 30 minutes, also for someone
 * without an account (/<lang>/flo-preview/<token>).
 */
final class PagePreview
{
    public const SECONDS = 1800;

    private static function directory(): string
    {
        return Admin::root() . '/storage/cache/page-previews';
    }

    /** Keep a page's values for a preview; returns the token. */
    public static function store(object $row, string $lang): string
    {
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The preview folder storage/cache/page-previews could not be created.');
        }
        foreach (glob($directory . '/*.json') ?: [] as $old) {
            if ((int) @filemtime($old) < time() - self::SECONDS) { @unlink($old); }
        }
        $token = bin2hex(random_bytes(16));
        $data = json_encode(['expires' => time() + self::SECONDS, 'lang' => $lang, 'row' => $row], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($directory . '/' . $token . '.json', $data, LOCK_EX) === false) {
            throw new \RuntimeException('The preview could not be written to storage/cache/page-previews.');
        }
        return $token;
    }

    /** The preview of a token, or null when it is unknown or expired. */
    public static function find(string $token): ?Page
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) { return null; }
        $file = self::directory() . '/' . $token . '.json';
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($data) || (int) ($data['expires'] ?? 0) < time() || !is_array($data['row'] ?? null)) { return null; }
        return new Page((object) $data['row'], is_string($data['lang'] ?? null) ? $data['lang'] : Pages::language(), true);
    }

    public static function url(string $token, string $lang): string
    {
        $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
        return $base . '/' . $lang . '/flo-preview/' . $token;
    }
}
