<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Assets;
use FloCMS\Admin\Auth\QrCode;
use FloCMS\Admin\Auth\SecretBox;
use FloCMS\Admin\Auth\Totp;
use FloCMS\Admin\Auth\TrustedDevices;
use FloCMS\Admin\Auth\TwoFactor;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Appearance;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Controller;
use FloCMS\Core\Csrf;
use FloCMS\Core\Http\Response;
use FloCMS\Core\Router;
use FloCMS\Core\Session;
use FloCMS\Core\TemplateEngine;

/**
 * The second step of signing in (0.35): /admin/two-factor.
 *
 * A fresh sign-in is held here by flocms-core 2.7 (Auth::holdSessionsUsing())
 * until the person enters a code from their app or a backup code, or sets up
 * two-factor sign-in when their role requires it. The actions open without
 * signing in (guest admin actions), so every one checks whose sign-in is held.
 * Five wrong codes in a row end the held sign-in.
 */
final class TwoFactorController extends Controller
{
    public const SETUP_KEY = 'admin_2fa_setup';
    public const CODES_KEY = 'admin_2fa_codes';
    private const WRONG_KEY = 'admin_2fa_wrong';
    private const MAX_WRONG = 5;
    private const SETUP_SECONDS = 600;

    protected array $actionPermissions = ['admin_index' => null, 'admin_backup' => null, 'admin_codes' => null];

    public function admin_index(): Response
    {
        $user = $this->heldUser();
        $id = (int) $user->id;
        if (!TwoFactor::isOn($id)) {
            return TwoFactor::isRequired($user->role ?? null) ? $this->setup($user) : $this->finish($user, false);
        }

        $error = '';
        if ($this->isPost()) {
            $result = TwoFactor::verifyCode($id, (string) ($_POST['code'] ?? ''));
            if ($result === 'ok') { return $this->finish($user, ($_POST['remember'] ?? '') === '1'); }
            $error = $this->failure($result);
        }

        return $this->page('code', [
            'title' => AdminLang::get('two_factor.code_title'), 'error' => $error,
            'action' => Admin::url(TwoFactor::PATH), 'remember' => TrustedDevices::enabled(),
            'days' => Admin::config()->twoFactor()['remember_days'], 'email' => self::masked((string) ($user->email ?? '')),
        ], $error !== '' ? 422 : 200);
    }

    public function admin_backup(): Response
    {
        $user = $this->heldUser();
        if (!TwoFactor::isOn((int) $user->id)) { Router::redirect(Admin::url(TwoFactor::PATH)); }

        $error = '';
        if ($this->isPost()) {
            $result = TwoFactor::verifyBackupCode((int) $user->id, (string) ($_POST['code'] ?? ''));
            if ($result === 'ok') { return $this->finish($user, false); }
            $error = $this->failure($result);
        }

        return $this->page('backup', ['title' => AdminLang::get('two_factor.backup_title'), 'error' => $error, 'action' => Admin::url(TwoFactor::PATH . '/backup')],
            $error !== '' ? 422 : 200);
    }

    /** The backup codes after a setup at sign-in, once; Continue finishes signing in. */
    public function admin_codes(): Response
    {
        $user = $this->heldUser();
        $shown = Session::get(self::CODES_KEY);
        if (!is_array($shown) || (int) ($shown['user'] ?? 0) !== (int) $user->id) {
            Router::redirect(Admin::url(TwoFactor::PATH));
        }
        if ($this->isPost()) {
            Session::delete(self::CODES_KEY);
            return $this->finish($user, false);
        }

        // Shown once: the session keeps only that they were shown, for Continue.
        $codes = array_values(array_map('strval', (array) ($shown['codes'] ?? [])));
        Session::set(self::CODES_KEY, ['user' => (int) $user->id, 'codes' => []]);
        return $this->page('codes', ['title' => AdminLang::get('two_factor.codes_title'), 'codes' => $codes,
            'action' => Admin::url(TwoFactor::PATH . '/codes'), 'file' => self::codesFile($codes)]);
    }

    /** Setting it up at sign-in: the role requires it. */
    private function setup(object $user): Response
    {
        $id = (int) $user->id;
        $pending = Session::get(self::SETUP_KEY);
        $secret = is_array($pending) && (int) ($pending['user'] ?? 0) === $id && time() - (int) ($pending['at'] ?? 0) < self::SETUP_SECONDS
            ? SecretBox::decrypt((string) ($pending['secret'] ?? '')) : null;
        if ($secret === null) {
            $secret = Totp::newSecret();
            Session::set(self::SETUP_KEY, ['user' => $id, 'secret' => SecretBox::encrypt($secret), 'at' => time()]);
        }

        $error = '';
        if ($this->isPost()) {
            if (Totp::verify($secret, (string) ($_POST['code'] ?? '')) !== null) {
                $codes = TwoFactor::enable($id, $secret);
                Session::delete(self::SETUP_KEY);
                Session::set(self::CODES_KEY, ['user' => $id, 'codes' => $codes]);
                Router::redirect(Admin::url(TwoFactor::PATH . '/codes'));
            }
            $error = AdminLang::get('two_factor.setup_wrong');
        }

        $uri = Totp::uri($secret, Admin::config()->twoFactor()['issuer'], (string) ($user->email ?? ''));
        return $this->page('setup', [
            'title' => AdminLang::get('two_factor.setup_title'), 'error' => $error, 'action' => Admin::url(TwoFactor::PATH),
            'qr' => QrCode::encode($uri)->svg(AdminLang::get('two_factor.qr_label')), 'key' => Totp::displayKey($secret), 'uri' => $uri,
            'role' => \FloCMS\Admin\Users\UsersResource::roles()[(int) ($user->role ?? -1)] ?? '',
        ], $error !== '' ? 422 : 200);
    }

    private function finish(object $user, bool $remember): Response
    {
        if ($remember) {
            TrustedDevices::remember((int) $user->id, (string) ($user->password ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
        Session::delete(self::WRONG_KEY);
        $next = Auth::releaseHold();
        Router::redirect(Admin::url($next ?? (Admin::homePath() ?? '')));
        return Response::noContent();
    }

    private function failure(string $result): string
    {
        if ($result === 'locked') {
            return AdminLang::get('two_factor.locked', ['minutes' => TwoFactor::LOCK_MINUTES]);
        }
        $wrong = (int) Session::get(self::WRONG_KEY) + 1;
        if ($wrong >= self::MAX_WRONG) {
            Auth::cancelHold();
            Session::setFlash(AdminLang::get('two_factor.too_many'), 'danger');
            Router::redirect(Admin::url('users/login'));
        }
        Session::set(self::WRONG_KEY, $wrong);
        return AdminLang::get('two_factor.wrong', ['left' => self::MAX_WRONG - $wrong]);
    }

    /** The user whose sign-in is held here; anyone else goes where they belong. */
    private function heldUser(): object
    {
        $id = Auth::heldPath() === TwoFactor::PATH ? Auth::heldUserId() : null;
        if ($id === null) {
            Router::redirect(Admin::url(Session::get('admin_access') ? '' : 'users/login'));
        }
        // Held, but two-factor sign-in isn't working (APP_KEY, tables): never let the sign-in through.
        if (!TwoFactor::available()) {
            try { App::logger()->warning('flocms-admin: two-factor sign-in is on but not ready (' . TwoFactor::status() . '); a sign-in was stopped. Run php flo admin:doctor.'); } catch (\Throwable) { /* no log folder */ }
            Auth::cancelHold();
            Session::setFlash(AdminLang::get('two_factor.unavailable'), 'danger');
            Router::redirect(Admin::url('users/login'));
        }
        $db = App::db() ?? throw new \RuntimeException('No database connection.');
        $user = $db->table('users')->where('id', '=', (int) $id)->first();
        if ($user === null) {
            Auth::cancelHold();
            Router::redirect(Admin::url('users/login'));
        }
        return $user;
    }

    private function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /** "kh•••@hostkurd.com": enough to recognise the account. */
    public static function masked(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) { return ''; }
        return mb_substr($email, 0, min(2, $at)) . '•••' . substr($email, $at);
    }

    /** @param list<string> $codes the codes as a text file to download */
    public static function codesFile(array $codes): string
    {
        $lines = [AdminLang::get('two_factor.codes_file_title', ['brand' => Admin::config()->brand()]), '', ...$codes, '', AdminLang::get('two_factor.codes_file_note')];
        return 'data:text/plain;charset=utf-8,' . rawurlencode(implode("\r\n", $lines) . "\r\n");
    }

    /** @param array<string, mixed> $values */
    private function page(string $screen, array $values, int $status = 200): Response
    {
        $lang = AdminLang::language();
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $path = ['code' => TwoFactor::PATH, 'backup' => TwoFactor::PATH . '/backup', 'setup' => TwoFactor::PATH, 'codes' => TwoFactor::PATH . '/codes'][$screen];
        $languages = [];
        foreach (Languages::all() as $code) {
            $languages[] = ['name' => Languages::name($code), 'code' => $code, 'url' => $base . '/' . rawurlencode($code) . '/admin/' . $path];
        }
        $html = TemplateEngine::renderFile(PageView::viewPath('two-factor'), $values + [
            't' => PageView::translator(), 'screen' => $screen, 'lang' => $lang, 'dir' => PanelView::direction($lang),
            'brand' => Appearance::brand(), 'theme' => Appearance::theme(), 'logo' => Appearance::logo(),
            'brandInitial' => mb_strtoupper(mb_substr(trim(Appearance::brand()), 0, 1)),
            'assets' => Assets::loginTags(), 'csrf' => Csrf::field(), 'languages' => $languages,
            'siteUrl' => Admin::config()->siteLink($base . '/' . rawurlencode($lang) . '/'), 'logoutUrl' => Admin::url('users/logout'),
            'codeUrl' => Admin::url(TwoFactor::PATH), 'backupUrl' => Admin::url(TwoFactor::PATH . '/backup'),
            'error' => '', 'email' => '', 'flash' => self::flash(),
            'errorIcon' => '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/></svg>',
        ]);

        return Response::html($html, $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Frame-Options', 'DENY');
    }

    private static function flash(): string
    {
        $flash = Session::getFlash();
        return $flash !== null ? trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $flash['message']))) : '';
    }
}
