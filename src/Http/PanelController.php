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
    protected function panel(string $title, string $html, ?string $active): string
    {
        $this->data = (new PanelView())->data($title, $html, $active);

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
