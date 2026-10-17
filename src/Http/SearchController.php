<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Search\GlobalSearch;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Core\HttpException;
use FloCMS\Core\TemplateEngine;

/**
 * Ctrl+K search (0.20): /admin/search?q=… answers JSON to the search dialog
 * (Accept: application/json) and a results page without JavaScript. Every
 * signed-in panel user may search; each source shows only what they may open.
 */
final class SearchController extends PanelController
{
    protected array $actionPermissions = ['admin_index' => null];

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        if (!Admin::config()->search()['enabled']) { throw new HttpException(404); }
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        if (count($this->params) > 0) { throw new HttpException(404); }
        $value = $this->request->queryValue('q', '');
        if (!is_string($value) || strlen($value) > 400) { throw new HttpException(400); }
        $result = (new GlobalSearch())->run($value);

        if (str_contains((string) $this->request->header('Accept'), 'application/json')) {
            return \FloCMS\Core\Http\Response::json($result)
                ->header('Cache-Control', 'no-store')->header('Vary', 'Accept')->header('X-Content-Type-Options', 'nosniff');
        }

        $html = TemplateEngine::renderFile(PageView::viewPath('search'), [
            't' => PageView::translator(),
            'query' => $result['query'],
            'groups' => $result['groups'],
            'total' => $result['total'],
            'partial' => $result['partial'],
            'action' => Admin::url('search'),
            'searchIcon' => Icons::svg('search'),
        ]);
        $title = $result['query'] === '' ? AdminLang::get('search.title') : AdminLang::get('search.title_for', ['query' => $result['query']]);
        return $this->panel($title, $html, 'search');
    }
}
