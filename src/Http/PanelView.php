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
            $flash = ['type' => $type, 'message' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $flash['message'])))];
        }

        $user = (string) (Session::get('fullname') ?: Session::get('email') ?: '');

        return [
            't' => PageView::translator(),
            'title' => $title,
            'brand' => AdminLang::label(Admin::config()->brand()),
            'page' => $page,
            'nav' => $nav,
            'flash' => $flash,
            'user' => $user,
            'dir' => self::direction($lang),
            'lang' => $lang,
            'dashboardUrl' => Admin::url(),
            'usersUrl' => Auth::can('users.manage') ? Admin::url('users') : null,
            'logoutUrl' => Admin::url('users/logout'),
            'siteUrl' => $siteBase !== '' ? $siteBase . '/' : '/',
            'dashboardIcon' => Icons::svg('dashboard'),
            'usersIcon' => Icons::svg('users'),
            'logoutIcon' => Icons::svg('logout'),
            'menuIcon' => Icons::svg('menu'),
            'css' => self::asset('admin.css'),
            'js' => self::asset('admin.js'),
        ];
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

    private static function asset(string $file): string
    {
        $content = @file_get_contents(dirname(__DIR__, 2) . '/resources/assets/' . $file);

        // Printed inside <style>/<script>: make sure the content cannot close the tag
        return $content === false ? '' : str_ireplace(['</style', '</script'], ['<\/style', '<\/script'], $content);
    }
}
