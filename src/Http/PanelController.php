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
        $current = \FloCMS\Admin\Support\AdminLang::language();
        $preferred = $preferences->get('language');
        $languages = \FloCMS\Admin\Translation\Languages::all();
        $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
        $build = static function (string $lang) use ($path, $query, $base): string {
            unset($query[self::SWITCH_MARKER]);
            $url = (string) preg_replace('~/[a-z]{2,3}(-[a-z0-9]{2,8})?/admin(?=/|$)~', '/' . rawurlencode($lang) . '/admin', $path, 1);
            $prefix = $base !== '' && str_starts_with($base, 'http') ? (string) preg_replace('~^(https?://[^/]+).*$~', '$1', $base) : '';
            return $prefix . $url . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        };

        if (($query[self::SWITCH_MARKER] ?? null) === '1') {
            if (is_string($preferred) && $preferred !== '' && in_array($current, $languages, true)) {
                $preferences->set('language', $current);
            }
            return $build($current);
        }
        if (is_string($preferred) && $preferred !== $current && in_array($preferred, $languages, true) && preg_match('~/admin(/|$)~', $path) === 1) {
            return $build($preferred);
        }
        return null;
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
