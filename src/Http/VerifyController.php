<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Assets;
use FloCMS\Admin\Auth\EmailVerification;
use FloCMS\Admin\Mail\MailException;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Appearance;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Users\UsersResource;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Controller;
use FloCMS\Core\Csrf;
use FloCMS\Core\Http\Response;
use FloCMS\Core\Router;
use FloCMS\Core\Session;
use FloCMS\Core\TemplateEngine;

/**
 * Email links (0.35): /admin/verify/link/<token> confirms an address, accepts an
 * invitation (the person chooses a password) or applies a changed address.
 * Without a token it is the "Check your email" step of a sign-in held by
 * 'email_verification' => ['required' => true]. Opens without signing in.
 */
final class VerifyController extends Controller
{
    protected array $actionPermissions = ['admin_index' => null, 'admin_link' => null, 'admin_send' => null];

    /** The "Check your email" step of a held sign-in. */
    public function admin_index(): Response
    {
        return $this->waiting();
    }

    /** /admin/verify/link/<token> */
    public function admin_link(): Response
    {
        $token = is_string($this->params[0] ?? null) ? (string) $this->params[0] : '';
        if (!EmailVerification::available()) {
            return $this->invalid($token);
        }
        $found = EmailVerification::find($token);
        if ($found === null) {
            return $this->invalid($token);
        }

        if ($found['kind'] === 'invite') {
            return $this->invitation($token, $found['user']);
        }

        $result = EmailVerification::complete($token);
        if ($result !== 'done') {
            return $result === 'taken'
                ? $this->page('invalid', ['title' => AdminLang::get('verify.taken_title'), 'description' => AdminLang::get('verify.taken_description'), 'path' => 'verify/link/' . $token])
                : $this->invalid($token);
        }
        $this->releaseIfHeld((int) $found['user']->id);

        return $this->page('done', [
            'title' => AdminLang::get('verify.done_title'),
            'description' => AdminLang::get($found['kind'] === 'change' ? 'verify.changed_description' : 'verify.done_description', ['email' => $found['email']]),
            'continueUrl' => Admin::url(Session::get('admin_access') ? (Admin::homePath() ?? '') : 'users/login'), 'path' => 'verify/link/' . $token,
        ]);
    }

    /** Send a new link from the "Check your email" step. */
    public function admin_send(): Response
    {
        $user = $this->heldUser();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                $sent = EmailVerification::sendVerification($user);
                Session::setFlash(AdminLang::get($sent ? 'verify.sent' : 'verify.limited'), $sent ? 'success' : 'warning');
            } catch (MailException|\InvalidArgumentException $error) {
                error_log('flocms-admin: verification email to user ' . $user->id . ' failed: ' . $error->getMessage());
                Session::setFlash(AdminLang::get('verify.mail_failed'), 'danger');
            }
        }
        Router::redirect(Admin::url(EmailVerification::PATH));
        return Response::noContent();
    }

    private function waiting(): Response
    {
        $user = $this->heldUser();
        return $this->page('waiting', [
            'title' => AdminLang::get('verify.waiting_title'), 'email' => (string) ($user->email ?? ''),
            'action' => Admin::url(EmailVerification::PATH . '/send'), 'path' => EmailVerification::PATH,
            'hours' => max(1, intdiv(Admin::config()->emailVerification()['expires'], 60)),
        ]);
    }

    private function invitation(string $token, object $user): Response
    {
        $passwordError = '';
        $confirmError = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $confirm = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
            if (mb_strlen($password) < UsersResource::MIN_PASSWORD || strlen($password) > 4096) {
                $passwordError = AdminLang::get('password.too_short', ['min' => UsersResource::MIN_PASSWORD]);
            } elseif (!hash_equals($password, $confirm)) {
                $confirmError = AdminLang::get('password.mismatch');
            } elseif (EmailVerification::complete($token, $password) === 'done') {
                Session::setFlash(AdminLang::get('verify.invite_done'), 'success');
                Router::redirect(Admin::url('users/login'));
            } else {
                return $this->invalid($token);
            }
        }
        $name = trim((string) ($user->full_name ?? ''));
        return $this->page('invite', [
            'title' => $name !== '' ? AdminLang::get('verify.welcome_name', ['name' => $name]) : AdminLang::get('verify.welcome'),
            'email' => (string) $user->email, 'action' => Admin::url(EmailVerification::PATH . '/link/' . $token), 'path' => 'verify/link/' . $token,
            'passwordError' => $passwordError, 'confirmError' => $confirmError, 'min' => UsersResource::MIN_PASSWORD,
        ], $passwordError !== '' || $confirmError !== '' ? 422 : 200);
    }

    private function invalid(string $token): Response
    {
        return $this->page('invalid', [
            'title' => AdminLang::get('verify.invalid_title'),
            'description' => AdminLang::get('verify.invalid_description', ['hours' => max(1, intdiv(Admin::config()->emailVerification()['expires'], 60))]),
            'path' => 'verify/link/' . mb_substr($token, 0, 64), 'continueUrl' => Admin::url(Session::get('admin_access') ? 'profile/security' : 'users/login'),
        ]);
    }

    private function releaseIfHeld(int $userId): void
    {
        if (Auth::heldPath() === EmailVerification::PATH && Auth::heldUserId() === $userId) {
            Auth::releaseHold();
        }
    }

    private function heldUser(): object
    {
        $id = Auth::heldPath() === EmailVerification::PATH ? Auth::heldUserId() : null;
        if ($id === null) {
            Router::redirect(Admin::url(Session::get('admin_access') ? '' : 'users/login'));
        }
        $user = (App::db() ?? throw new \RuntimeException('No database connection.'))->table('users')->where('id', '=', (int) $id)->first();
        if ($user === null) {
            Auth::cancelHold();
            Router::redirect(Admin::url('users/login'));
        }
        // Verified meanwhile (in another tab): nothing to wait for.
        if (EmailVerification::isVerified($user)) {
            Router::redirect(Admin::url(Auth::releaseHold() ?? (Admin::homePath() ?? '')));
        }
        return $user;
    }

    /** @param array<string, mixed> $values */
    private function page(string $screen, array $values, int $status = 200): Response
    {
        $lang = AdminLang::language();
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $rest = '/admin/' . implode('/', array_map('rawurlencode', explode('/', (string) ($values['path'] ?? EmailVerification::PATH))));
        $languages = [];
        foreach (Languages::all() as $code) {
            $languages[] = ['name' => Languages::name($code), 'code' => $code, 'url' => $base . '/' . rawurlencode($code) . $rest];
        }
        $flash = Session::getFlash();
        $html = TemplateEngine::renderFile(PageView::viewPath('verify'), $values + [
            't' => PageView::translator(), 'screen' => $screen, 'lang' => $lang, 'dir' => PanelView::direction($lang),
            'brand' => Appearance::brand(), 'theme' => Appearance::theme(), 'logo' => Appearance::logo(),
            'brandInitial' => mb_strtoupper(mb_substr(trim(Appearance::brand()), 0, 1)),
            'assets' => Assets::loginTags(), 'csrf' => Csrf::field(), 'languages' => $languages,
            'siteUrl' => $base . '/' . rawurlencode($lang) . '/', 'logoutUrl' => Admin::url('users/logout'), 'loginUrl' => Admin::url('users/login'),
            'description' => '', 'email' => '', 'passwordError' => '', 'confirmError' => '', 'continueUrl' => Admin::url('users/login'),
            'flash' => $flash !== null ? trim(strip_tags((string) $flash['message'])) : '', 'flashType' => $flash !== null ? (string) ($flash['type'] ?? '') : '',
            'errorIcon' => '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/></svg>',
        ]);

        return Response::html($html, $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Frame-Options', 'DENY');
    }
}
