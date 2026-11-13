<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Core\Http\Response;

/**
 * /sitemap.xml (0.30): the published, indexable pages in every language
 * they have, with the other languages as alternates. A site with its own
 * public/sitemap.xml or a sitemap controller keeps it.
 */
final class Sitemap
{
    public static function xml(): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach (Pages::rows() as $row) {
            if (!Pages::isVisible($row) || (int) ($row->noindex ?? 0) === 1) { continue; }
            $alternates = Pages::alternates($row);
            foreach ($alternates as $lang => $url) {
                $xml .= "  <url>\n    <loc>" . $e($url) . "</loc>\n";
                if (($time = Pages::time($row->updated_at ?? null)) !== null) { $xml .= '    <lastmod>' . gmdate('Y-m-d', $time) . "</lastmod>\n"; }
                if (count($alternates) > 1) {
                    foreach ($alternates as $other => $href) { $xml .= '    <xhtml:link rel="alternate" hreflang="' . $e($other) . '" href="' . $e($href) . '"/>' . "\n"; }
                }
                $xml .= "  </url>\n";
            }
        }
        $xml .= self::extensionEntries($e);
        return $xml . '</urlset>' . "\n";
    }

    /** Addresses packages add with Admin::extend() (0.43); same-site paths become full URLs. */
    private static function extensionEntries(callable $e): string
    {
        $xml = '';
        foreach (\FloCMS\Admin\Admin::extension()->getSitemaps() as $entries) {
            try {
                foreach ($entries() as $entry) {
                    $loc = self::absolute(is_array($entry) ? (string) ($entry['loc'] ?? '') : '');
                    if ($loc === null) { continue; }
                    $xml .= "  <url>\n    <loc>" . $e($loc) . "</loc>\n";
                    $lastmod = $entry['lastmod'] ?? null;
                    $time = is_int($lastmod) ? $lastmod : (is_string($lastmod) && $lastmod !== '' ? strtotime($lastmod) : false);
                    if (is_int($time)) { $xml .= '    <lastmod>' . gmdate('Y-m-d', $time) . "</lastmod>\n"; }
                    $alternates = is_array($entry['alternates'] ?? null) ? $entry['alternates'] : [];
                    foreach ($alternates as $lang => $href) {
                        $href = self::absolute((string) $href);
                        if ($href !== null && preg_match('/^(?:[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*|x-default)$/D', (string) $lang) === 1) {
                            $xml .= '    <xhtml:link rel="alternate" hreflang="' . $e((string) $lang) . '" href="' . $e($href) . '"/>' . "\n";
                        }
                    }
                    $xml .= "  </url>\n";
                }
            } catch (\Throwable $error) {
                \FloCMS\Admin\Support\Log::error('A sitemap function from Admin::extend() failed: ' . $error->getMessage());
            }
        }
        return $xml;
    }

    /** A full http(s) URL as is; a path starting with / joined to the site's address; anything else is dropped. */
    private static function absolute(string $url): ?string
    {
        // Valid UTF-8 without spaces or control characters (they would break the XML)
        if (preg_match('#^https?://[^\x00-\x20\x7F<>"]+$#iDu', $url) === 1) { return $url; }
        if (preg_match('#^/(?!/)[^\x00-\x20\x7F<>"]*$#Du', $url) !== 1) { return null; }
        // The same base as the pages' own addresses
        return (defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '') . $url;
    }

    public static function response(): Response
    {
        return Response::make(self::xml())->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
