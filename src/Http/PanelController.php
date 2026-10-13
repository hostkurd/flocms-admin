<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Core\Controller;
use FloCMS\Core\Router;

/**
 * Base of the panel's controllers: renders a page inside the panel shell.
 *
 * @internal
 */
abstract class PanelController extends Controller
{
    /** Put $html in the panel shell; returns the view FloCMS renders. */
    protected function panel(string $title, string $html, ?string $active): string|\FloCMS\Core\Http\Response
    {
        $this->data = (new PanelView())->data($title, $html, $active);

        if ($this->request->header('X-Flo-Admin') === 'navigate') {
            return \FloCMS\Core\Http\Response::html(\FloCMS\Core\TemplateEngine::renderFile(PanelView::template(), $this->data))
                ->header('Cache-Control', 'no-store')->header('Vary', 'X-Flo-Admin');
        }
        header('Cache-Control: no-store');
        return PanelView::template();
    }

    protected function isPost(): bool
    {
        return $this->request->method() === 'POST';
    }

    protected function redirect(string $url): never
    {
        Router::redirect($url);
        exit;
    }
}
