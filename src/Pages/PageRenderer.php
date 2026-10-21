<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Admin\Blocks\BlockRenderer;
use FloCMS\Core\Template;
use FloCMS\Core\TemplateEngine;

/**
 * Shows a page the way the site shows its other pages (0.30): the page's
 * template (with $page and $content) inside the site's layout, which gets
 * $content as usual; the layout prints {!! page_meta() !!} in its <head>.
 */
final class PageRenderer
{
    public static function render(Page $page): string
    {
        $content = self::view(Templates::path($page->template()), ['page' => $page, 'content' => $page->content()]);
        $layout = self::layout();
        return $layout === null ? $content : self::view($layout, ['content' => $content, 'page' => $page]);
    }

    private static function layout(): ?string
    {
        if (!defined('TEMPLATES_PATH') || !defined('DS')) { return null; }
        try { return Template::getLayoutPath('default'); } catch (\Throwable) { return null; }
    }

    /** @param array<string, mixed> $vars */
    private static function view(string $path, array $vars): string
    {
        if (defined('VIEWS_PATH') && defined('DS')) { return TemplateEngine::renderFile($path, $vars); }
        return BlockRenderer::evaluate($path, $vars);
    }
}
