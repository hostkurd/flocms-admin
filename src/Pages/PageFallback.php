<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

/**
 * Answers the website's addresses no controller answers (flocms-core 2.6
 * App::fallbackUsing()): a published page, a preview, or /sitemap.xml.
 * Anything else stays a 404.
 */
final class PageFallback
{
    public static function handle(string $path, Request $request): ?Response
    {
        if (!$request->isMethod('GET', 'HEAD') || !Pages::enabled()) { return null; }
        $path = trim($path, '/');
        $lang = Pages::language();
        if ($path === 'sitemap.xml') {
            return \FloCMS\Admin\Admin::config()->pages()['sitemap'] ? Sitemap::response() : null;
        }
        if (preg_match('~^flo-preview/([a-f0-9]{32})$~D', $path, $m) === 1) {
            $page = PagePreview::find($m[1]);
            return $page?->response();
        }
        return Pages::find($path, $lang)?->response();
    }
}
