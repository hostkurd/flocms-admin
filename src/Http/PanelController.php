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
        if ($this->request->method() === 'GET' && ($url = self::languageRedirect()) !== null) {
            $this->redirect($url);
        }
        \FloCMS\Admin\Support\Housekeeping::noteLogin();
        if ($this->request->method() === 'GET') { \FloCMS\Admin\Support\Housekeeping::run(); }
        $this->data = (new PanelView())->data($title, $html, $active);

        if ($this->request->header('X-Flo-Admin') === 'navigate') {
            return \FloCMS\Core\Http\Response::html(\FloCMS\Core\TemplateEngine::renderFile(PanelView::template(), $this->data))
                ->header('Cache-Control', 'no-store')->header('Vary', 'X-Flo-Admin');
        }
        header('Cache-Control: no-store');
        return PanelView::template();
    }

    /** Query parameter on the top-bar language links: the choice becomes the user's preference. */
    public const SWITCH_MARKER = 'switch_language';

    /**
     * Where to send a GET request for the user's preferred panel language, or
     * null to stay. A language chosen from the top-bar menu (marked with
     * ?switch_language=1) replaces a preferred language the user has set.
     */
    public static function languageRedirect(): ?string
    {
        $preferences = \FloCMS\Admin\Preferences\Preferences::current();
        if ($preferences === null) {
            return null;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '');
        parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
        // The admin page itself ("posts/edit/5"), wherever the site lives
        if (preg_match('~/admin(?:/([^?#]*))?$~', $path, $match) !== 1) {
            return null;
        }
        $page = trim($match[1] ?? '', '/');
        $current = \FloCMS\Admin\Support\AdminLang::language();
        $languages = \FloCMS\Admin\Translation\Languages::all();
        $preferred = $preferences->get('language');
        $switching = ($query[self::SWITCH_MARKER] ?? null) === '1';
        unset($query[self::SWITCH_MARKER]);

        if ($switching) {
            if (is_string($preferred) && $preferred !== '' && in_array($current, $languages, true)) {
                $preferences->set('language', $current);
            }
            $target = $current;
        } elseif (is_string($preferred) && $preferred !== $current && in_array($preferred, $languages, true)) {
            $target = $preferred;
        } else {
            return null;
        }

        // Built like Admin::url(): the site's own address, never one taken from the request
        $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
        $url = $base . '/' . rawurlencode($target) . '/admin' . ($page !== '' ? '/' . implode('/', array_map('rawurlencode', array_map('rawurldecode', explode('/', $page)))) : '');
        $url .= $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $here = (preg_match('~^https?://[^/]+~i', $base, $origin) === 1 ? $origin[0] : '') . $uri;
        return !$switching && $url === $here ? null : $url;
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
