<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\Auth;
use FloCMS\Core\Lang;
use FloCMS\Core\Session;

/**
 * Data of the panel shell (sidebar, top bar, flash message) around a page.
 * The shell is the content of the site's own admin layout.
 *
 * @internal
 */
final class PanelView
{
    public static function template(): string
    {
        return PageView::viewPath('panel');
    }

    /** @return array<string, mixed> */
    public function data(string $title, string $page, ?string $activeSlug): array
    {
        $lang = AdminLang::language();
        $siteBase = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') . '/' . rawurlencode($lang) : '';

        $nav = [];
        foreach (Admin::registry()->all() as $slug => $class) {
            /** @var class-string<Resource> $class */
            if (!Auth::can($class::permission('view'))) {
                continue;
            }
            $nav[] = [
                'label' => $class::getPluralLabel(),
                'url' => Admin::url($slug),
                'active' => $slug === $activeSlug,
                'icon' => Icons::svg($class::getIcon()),
            ];
        }

        $flash = Session::getFlash();
        if ($flash !== null) {
            $type = in_array($flash['type'] ?? '', ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
            // Messages set elsewhere in the site may contain <br>: shown as text
            $message = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $flash['message'])));
            if ($message !== '') {
                \FloCMS\Admin\Notifications\Notification::make()->title($message)->{$type}()->send();
            }
        }

        $user = (string) (Session::get('fullname') ?: Session::get('email') ?: '');
        $registry = Admin::registry();
        $usersEnabled = $registry->find('accounts') !== null;

        return [
            't' => PageView::translator(),
            'title' => $title,
            'brand' => \FloCMS\Admin\Support\Appearance::brand(),
            'page' => $page,
            'nav' => $nav,
            'notifications' => \FloCMS\Admin\Notifications\NotificationView::pending(),
            'user' => $user,
            'dir' => self::direction($lang),
            'lang' => $lang,
            'dashboardUrl' => Admin::url('dashboard'),
            'dashboardActive' => $activeSlug === 'dashboard',
            'usersUrl' => $usersEnabled && Auth::can('users.manage') ? Admin::url('accounts') : null,
            'usersActive' => $activeSlug === 'accounts',
            'settingsUrl' => \FloCMS\Admin\Http\SettingsController::allowedPages() !== [] ? Admin::url('settings') : null,
            'settingsActive' => $activeSlug === 'settings',
            'settingsIcon' => Icons::svg('settings'),
            'profileUrl' => $usersEnabled ? Admin::url('profile') : null,
            'logoutUrl' => Admin::url('users/logout'),
            'siteUrl' => $siteBase !== '' ? $siteBase . '/' : '/',
            'dashboardIcon' => Icons::svg('dashboard'),
            'usersIcon' => Icons::svg('users'),
            'logoutIcon' => Icons::svg('logout'),
            'menuIcon' => Icons::svg('menu'),
            'assets' => \FloCMS\Admin\Assets::tags(),
            'navigation' => Admin::config()->reactiveEnabled(),
            'panelBase' => Admin::url(),
            'theme' => \FloCMS\Admin\Support\Appearance::theme(),
            'sidebar' => \FloCMS\Admin\Support\Appearance::sidebar(),
            'contentWidth' => \FloCMS\Admin\Support\Appearance::contentWidth(),
            'density' => \FloCMS\Admin\Support\Appearance::density(),
            'logo' => \FloCMS\Admin\Support\Appearance::logo(),
            'brandInitial' => self::initial(\FloCMS\Admin\Support\Appearance::brand()),
            'userInitial' => self::initial($user),
            'breadcrumbs' => $this->breadcrumbs($title, $activeSlug),
            'languages' => self::languageLinks($lang),
            'globeIcon' => Icons::svg('globe'),
            'chevronIcon' => Icons::svg('chevron-down', 'fa-icon fa-icon-sm'),
            'externalIcon' => Icons::svg('external'),
            'profileIcon' => Icons::svg('user'),
        ];
    }

    /** Uppercase first letter for the logo mark and avatar; empty when there is no text. */
    private static function initial(string $text): string
    {
        $text = trim($text);
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1));
    }

    /**
     * Where the current page sits: Dashboard; Site / Users; Content / Posts; Content / Posts / New post.
     *
     * @return list<array{label: string, url: ?string, current: bool}>
     */
    private function breadcrumbs(string $title, ?string $activeSlug): array
    {
        $items = $this->trail($title, $activeSlug);
        $last = array_key_last($items);
        foreach ($items as $index => $item) { $items[$index]['current'] = $index === $last; }
        return $items;
    }

    /** @return list<array{label: string, url: ?string}> */
    private function trail(string $title, ?string $activeSlug): array
    {
        if ($activeSlug === null || in_array($activeSlug, ['dashboard', 'profile'], true)) {
            return [['label' => $title, 'url' => null]];
        }
        if ($activeSlug === 'settings') {
            $items = [['label' => AdminLang::get('nav.site'), 'url' => null], ['label' => AdminLang::get('settings.title'), 'url' => null]];
            if ($title !== AdminLang::get('settings.title')) { $items[] = ['label' => $title, 'url' => null]; }
            return $items;
        }
        if ($activeSlug === 'accounts') {
            return [['label' => AdminLang::get('nav.site'), 'url' => null], ['label' => $title, 'url' => null]];
        }
        $class = Admin::registry()->find($activeSlug);
        if ($class === null) {
            return [['label' => $title, 'url' => null]];
        }
        /** @var class-string<Resource> $class */
        $plural = $class::getPluralLabel();
        $items = [['label' => AdminLang::get('nav.resources'), 'url' => null]];
        if ($title === $plural) {
            $items[] = ['label' => $plural, 'url' => null];
        } else {
            $items[] = ['label' => $plural, 'url' => Admin::url($activeSlug)];
            $items[] = ['label' => $title, 'url' => null];
        }
        return $items;
    }

    /**
     * The current admin page in every site language. Switching language is an
     * ordinary page load (the language is part of the URL).
     *
     * @return list<array{code: string, name: string, url: string, current: bool}>
     */
    private static function languageLinks(string $current): array
    {
        $languages = Languages::all();
        if (count($languages) < 2) {
            return [];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '');
        $query = parse_url($uri, PHP_URL_QUERY);
        $rest = preg_match('~/admin(/[^?#]*)?$~', $path, $match) === 1 ? trim($match[1] ?? '', '/') : 'dashboard';
        $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
        $links = [];
        foreach ($languages as $code) {
            $links[] = [
                'code' => $code,
                'name' => Languages::name($code),
                'url' => $base . '/' . rawurlencode($code) . '/admin' . ($rest !== '' ? '/' . $rest : '') . '?' . (is_string($query) && $query !== '' ? $query . '&' : '') . PanelController::SWITCH_MARKER . '=1',
                'current' => $code === $current,
            ];
        }
        return $links;
    }

    /** The site's lng.dir for the admin language, else the package's RTL list. */
    public static function direction(string $lang): string
    {
        $dir = Lang::get('lng.dir', '');
        if (is_string($dir) && in_array(strtolower($dir), ['rtl', 'ltr'], true)) {
            return strtolower($dir);
        }

        return Languages::direction($lang);
    }

}
