<?php
declare(strict_types=1);
namespace FloCMS\Admin\Auth;
use FloCMS\Admin\{Admin, Assets, Http\PageView, Http\PanelView, Support\AdminLang, Translation\Languages};
use FloCMS\Core\{Csrf, Session, TemplateEngine};

/** Presentation only: the skeleton owns credentials, CSRF, throttling and sessions. */
final class LoginView
{
    public static function render(): ?string
    {
        if (!Admin::config()->isInstalled() || !Admin::config()->loginThemeEnabled()) { return null; }
        $lang = AdminLang::language();
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $languages = [];
        foreach (Languages::all() as $code) { $languages[] = ['name' => Languages::name($code), 'code' => $code, 'url' => $base . '/' . rawurlencode($code) . '/admin/users/login']; }
        $flash = Session::getFlash();
        $message = $flash !== null ? trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $flash['message']))) : '';
        $email = $_POST['email'] ?? '';
        return TemplateEngine::renderFile(PageView::viewPath('login'), [
            't' => PageView::translator(), 'brand' => AdminLang::label(Admin::config()->brand()), 'lang' => $lang, 'dir' => PanelView::direction($lang),
            'languages' => $languages, 'message' => $message, 'csrf' => Csrf::field(), 'email' => is_string($email) ? mb_substr($email, 0, 254) : '',
            'assets' => Assets::loginTags(), 'action' => Admin::url('users/login'), 'siteUrl' => $base . '/' . rawurlencode($lang) . '/',
            'signedIn' => (bool) Session::get('isloggedin'), 'logoutUrl' => Admin::url('users/logout'),
        ]);
    }
}
