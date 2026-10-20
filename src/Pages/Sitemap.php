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
            if (!Pages::isLive($row) || (int) ($row->noindex ?? 0) === 1) { continue; }
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
        return $xml . '</urlset>' . "\n";
    }

    public static function response(): Response
    {
        return Response::make(self::xml())->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
