<?php
declare(strict_types=1);

namespace FloCMS\Admin\Notifications;

use FloCMS\Admin\Admin;

/**
 * Where a stored notification leads (0.41). Kept without the language, so each
 * person opens it in their own panel language:
 *
 * - a panel page: "inquiries/edit/5" (also given as a full panel URL such as
 *   Admin::url('inquiries/edit/5'), which is shortened to that);
 * - a page of this website: "/en/properties/villa-a1".
 *
 * Nothing else is accepted (no other site, no "//", no "javascript:"), so the
 * open link can never send someone away from the site.
 *
 * @internal
 */
final class Target
{
    private const SAFE = '#^[A-Za-z0-9/_\-.%~+,;=@!$\'()*]*$#D';
    private const SAFE_QUERY = '~^[^\x00-\x20\x7F"<>\\\\`{}|^]*$~D';

    /** The stored form of a URL or panel path, or null when it isn't one of this site's. */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 1000 || str_contains($url, '\\') || str_contains($url, '#') || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) { return null; }
        $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
        $origin = preg_match('~^https?://[^/]+~i', $base, $m) === 1 ? strtolower($m[0]) : '';
        $basePath = (string) parse_url($base === '' ? '/' : $base, PHP_URL_PATH);
        $basePath = rtrim($basePath, '/');

        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $url) === 1) {
            // A full URL: only this site's address
            if (preg_match('~^https?://[^/?#]+~i', $url, $m) !== 1 || $origin === '' || strtolower($m[0]) !== $origin) { return null; }
            $url = substr($url, strlen($m[0]));
            if ($url === '') { $url = '/'; }
        } elseif (str_starts_with($url, '//')) {
            return null;
        } elseif (!str_starts_with($url, '/')) {
            // A panel path such as "inquiries/edit/5"
            return self::clean(ltrim($url, '/'), false);
        }

        // A path from the site root: the panel's own (any language) becomes a panel path.
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        if ($basePath !== '' && !($path === $basePath || str_starts_with($path, $basePath . '/'))) { return null; }
        $relative = substr($path, strlen($basePath));
        if (preg_match('~^/[a-z]{2,3}(?:-[a-z0-9]{2,8})?/admin(?:/(.*))?$~iD', $relative, $m) === 1) {
            return self::clean(($m[1] ?? '') . (is_string($query) && $query !== '' ? '?' . $query : ''), false);
        }
        return self::clean($relative . (is_string($query) && $query !== '' ? '?' . $query : ''), true);
    }

    /** The address to open for a stored target in the current panel language, or null. */
    public static function resolve(?string $stored): ?string
    {
        if ($stored === null || $stored === '') { return null; }
        $query = '';
        if (($at = strpos($stored, '?')) !== false) { $query = substr($stored, $at); $stored = substr($stored, 0, $at); }
        if (str_starts_with($stored, '//') || str_contains($stored, '..') || preg_match(self::SAFE, $stored) !== 1 || ($query !== '' && preg_match(self::SAFE_QUERY, $query) !== 1)) { return null; }
        if (str_starts_with($stored, '/')) {
            $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
            return $base . $stored . $query;
        }
        return Admin::url('') . ($stored !== '' ? '/' . $stored : '') . $query;
    }

    private static function clean(string $value, bool $site): ?string
    {
        $query = '';
        if (($at = strpos($value, '?')) !== false) { $query = substr($value, $at); $value = substr($value, 0, $at); }
        // Letters of any language and spaces are kept, percent-encoded (a Greek slug, "?q=sea view").
        $encode = static fn (array $m): string => rawurlencode($m[0]);
        $value = (string) preg_replace_callback('#[^A-Za-z0-9/_\-.%~+,;=@!$\'()*]#', $encode, $value);
        $query = (string) preg_replace_callback('~[\x80-\xFF "<>`{}|^]~', $encode, $query);
        if (str_contains(rawurldecode($value), '..')) { return null; }
        if ($site) {
            if (!str_starts_with($value, '/') || str_starts_with($value, '//')) { return null; }
        } else {
            $value = trim($value, '/');
            if ($value === '') { $value = 'dashboard'; }
        }
        if (str_contains($value, '..') || preg_match(self::SAFE, $value) !== 1) { return null; }
        if ($query === '?') { $query = ''; }
        if ($query !== '' && preg_match(self::SAFE_QUERY, $query) !== 1) { return null; }
        return strlen($value . $query) > 1024 ? null : $value . $query;
    }
}
