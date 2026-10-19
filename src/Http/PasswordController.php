<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Assets;
use FloCMS\Admin\Auth\PasswordReset;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Appearance;
use FloCMS\Admin\Support\ClientIp;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Users\UsersResource;
use FloCMS\Core\Controller;
use FloCMS\Core\Csrf;
use FloCMS\Core\Http\Response;
use FloCMS\Core\Router;
use FloCMS\Core\Session;
use FloCMS\Core\TemplateEngine;

/**
 * Forgot password (0.26): /admin/password/forgot and /admin/password/reset/<token>.
 *
 * Both actions open without signing in (App::allowGuestAdminActions(), core
 * 2.5+). Pages are whole documents like the login page, never cached, and
 * send no referrer, so a reset link can't leak to another site.
 */
final class PasswordController extends Controller
{
    private const SENT_KEY = 'admin_reset_sent';

    protected array $actionPermissions = ['admin_forgot' => null, 'admin_reset' => null];

    public function admin_forgot(): Response
    {
        if (Session::get('admin_access')) {
            Router::redirect(Admin::url('dashboard'));
        }
        if (!PasswordReset::available()) {
            return $this->page('unavailable', ['title' => AdminLang::get('password.unavailable_title'), 'description' => AdminLang::get('password.unavailable_description')]);
        }

        $sent = Session::get(self::SENT_KEY);
        if (($this->params[0] ?? null) === 'sent' && is_string($sent) && $sent !== '') {
            return $this->page('sent', ['title' => AdminLang::get('password.sent_title'), 'email' => $sent]);
        }

        $email = '';
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $email = is_string($_POST['email'] ?? null) ? mb_substr(trim($_POST['email']), 0, 254) : '';
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $error = AdminLang::get('password.email_invalid');
            } else {
                $link = PasswordReset::request($email, ClientIp::get());
                if ($link !== null) {
                    PasswordReset::later(static fn () => PasswordReset::sendLink($link['user'], $link['token']));
                }
                Session::set(self::SENT_KEY, $email);
                Router::redirect(Admin::url('password/forgot/sent'));
            }
        }

        return $this->page('forgot', [
            'title' => AdminLang::get('password.forgot_title'), 'description' => AdminLang::get('password.forgot_description'),
            'email' => $email, 'emailError' => $error,
        ], $error !== '' ? 422 : 200);
    }

    public function admin_reset(): Response
    {
        if (!PasswordReset::available()) {
            return $this->page('unavailable', ['title' => AdminLang::get('password.unavailable_title'), 'description' => AdminLang::get('password.unavailable_description')]);
        }
        $token = is_string($this->params[0] ?? null) ? (string) $this->params[0] : '';
        $user = PasswordReset::find($token, ClientIp::get());
        if ($user === null) {
            return $this->invalid();
        }

        $passwordError = '';
        $confirmError = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $confirm = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
            if (mb_strlen($password) < UsersResource::MIN_PASSWORD || strlen($password) > 4096) {
                $passwordError = AdminLang::get('password.too_short', ['min' => UsersResource::MIN_PASSWORD]);
            } elseif (!hash_equals($password, $confirm)) {
                $confirmError = AdminLang::get('password.mismatch');
            } else {
                $changed = PasswordReset::complete($token, $password);
                if ($changed === null) {
                    return $this->invalid();
                }
                PasswordReset::later(static fn () => PasswordReset::sendChanged($changed));
                Session::delete(self::SENT_KEY);
                Session::setFlash(AdminLang::get('password.done'), 'success');
                Router::redirect(Admin::url('users/login'));
            }
        }

        return $this->page('reset', [
            'title' => AdminLang::get('password.reset_title'), 'email' => (string) ($user->email ?? ''),
            'action' => Admin::url('password/reset/' . $token), 'passwordError' => $passwordError, 'confirmError' => $confirmError,
        ], $passwordError !== '' || $confirmError !== '' ? 422 : 200);
    }

    private function invalid(): Response
    {
        return $this->page('invalid', [
            'title' => AdminLang::get('password.invalid_title'),
            'description' => AdminLang::get('password.invalid_description', ['minutes' => Admin::config()->passwordReset()['expires']]),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function page(string $screen, array $values, int $status = 200): Response
    {
        $lang = AdminLang::language();
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        $rest = preg_replace('#^(?:[a-z]{2,3}(?:-[A-Za-z0-9]+)?/)?admin/#', '', $path) ?? 'password/forgot';
        $languages = [];
        foreach (Languages::all() as $code) {
            $languages[] = ['name' => Languages::name($code), 'code' => $code, 'url' => $base . '/' . rawurlencode($code) . '/admin/' . $rest];
        }
        $html = TemplateEngine::renderFile(PageView::viewPath('password'), $values + [
            't' => PageView::translator(), 'screen' => $screen, 'lang' => $lang, 'dir' => PanelView::direction($lang),
            'brand' => Appearance::brand(), 'theme' => Appearance::theme(), 'logo' => Appearance::logo(),
            'brandInitial' => mb_strtoupper(mb_substr(trim(Appearance::brand()), 0, 1)),
            'assets' => Assets::loginTags(), 'csrf' => Csrf::field(), 'languages' => $languages,
            'siteUrl' => $base . '/' . rawurlencode($lang) . '/', 'loginUrl' => Admin::url('users/login'),
            'forgotUrl' => Admin::url('password/forgot'), 'action' => Admin::url('password/forgot'),
            'description' => '', 'email' => '', 'emailError' => '', 'passwordError' => '', 'confirmError' => '',
            'min' => UsersResource::MIN_PASSWORD, 'minutes' => Admin::config()->passwordReset()['expires'],
            'errorIcon' => '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/></svg>',
        ]);

        return Response::html($html, $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Frame-Options', 'DENY');
    }
}
