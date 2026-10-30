<?php
declare(strict_types=1);

namespace FloCMS\Admin;

use FloCMS\Admin\Http\DashboardController;
use FloCMS\Admin\Http\ProfileController;
use FloCMS\Admin\Http\ResourceController;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\App;
use FloCMS\Core\Config;
use FloCMS\Core\Env;
use Throwable;

/**
 * Entry point of the package: boot, configuration, registry and URLs.
 */
final class Admin
{
    public const VERSION = '0.39.4';

    private static bool $booted = false;
    private static ?string $root = null;
    private static ?AdminConfig $config = null;
    private static ?ResourceRegistry $registry = null;
    private static ?\FloCMS\Admin\Settings\SettingsRegistry $settings = null;
    private static ?\FloCMS\Admin\PanelPages\PanelPageRegistry $panelPages = null;
    private static bool $problemsLogged = false;

    /** Register the controller resolver (once). Called from bootstrap.php. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        App::resolveControllersUsing(static fn (string $controller, string $route): ?string => self::resolve($controller, $route));
        // Roles edited on the Roles page (0.21, flocms-core 2.4+); read only when a permission is checked.
        \FloCMS\Admin\Roles\Roles::boot();
        // Forgot password (0.26, flocms-core 2.5+): its two pages open without signing in,
        // and a new password ends the account's other sessions.
        if (method_exists(App::class, 'allowGuestAdminActions')) {
            App::allowGuestAdminActions('password', ['admin_forgot', 'admin_reset'], \FloCMS\Admin\Http\PasswordController::class);
            // A mailing's unsubscribe link (0.28) opens without signing in.
            App::allowGuestAdminActions('mailings', ['admin_unsubscribe'], \FloCMS\Admin\Http\MailingsController::class);
        }
        \FloCMS\Admin\Auth\PasswordStamp::boot();
        // Login security (0.35, flocms-core 2.7+): the sign-in steps, when turned on in config/admin.php.
        self::bootLoginSecurity();
        // Pages (0.30, flocms-core 2.6+): addresses no controller answers may be pages.
        if (method_exists(App::class, 'fallbackUsing')) {
            App::fallbackUsing(static fn (string $path, \FloCMS\Core\Http\Request $request): ?\FloCMS\Core\Http\Response => \FloCMS\Admin\Pages\PageFallback::handle($path, $request));
        }
        if (Config::get('auth.login_view') === null) { Config::set('auth.login_view', static fn (): ?string => \FloCMS\Admin\Auth\LoginView::render()); }

        // Where the skeleton's /admin page sends logged-in users (skeleton 1.8+).
        // A site can set its own 'admin.home' in config/config.php, which runs later.
        if (Config::get('admin.home') === null) {
            Config::set('admin.home', static fn (): ?string => self::homePath());
        }
    }

    private static bool $twoFactorBooted = false;
    private static bool $verificationBooted = false;
    /** @var array{two_factor: bool, email_verification: bool}|null the switches registered when the panel started from config/admin.php */
    private static ?array $bootSwitches = null;
    private static bool $lateSwitchWarned = false;

    /**
     * Two-factor sign-in and email verification hold fresh sign-ins (flocms-core
     * 2.7). Registered only when config/admin.php may turn them on, so a site
     * that doesn't use them is never asked for a sign-in step.
     *
     * At boot (Composer's autoload) the site hasn't read .env yet, so running the
     * file gives Env::get() its default. A switch is registered when running the
     * file turns it on, or when ConfigSwitches sees in its code that it may be
     * on (a value from .env) (0.39.4). That early read is not kept: config()
     * reads the file again after .env, and each hold checks the real settings
     * when the request is checked. useConfig() registers the switches of the
     * settings it gets (commands, tests).
     */
    private static function bootLoginSecurity(?AdminConfig $config = null): void
    {
        if (!self::$booted) { return; }
        if ($config === null) {
            if (self::$config !== null) {
                $config = self::$config;
            } elseif (self::$root !== null || defined('ROOT')) {
                // From the file's code (a switch from .env), and from running it (a switch built
                // in another file); the result of running it is not kept: .env isn't read yet.
                $switches = \FloCMS\Admin\Support\ConfigSwitches::scan(self::configFile());
                try {
                    [$values, $installed, $root] = self::readConfigFile();
                    $early = new AdminConfig($values, $root, $installed);
                    $switches['two_factor'] = $switches['two_factor'] || $early->twoFactor()['enabled'];
                    $switches['email_verification'] = $switches['email_verification'] || $early->emailVerification()['enabled'];
                } catch (Throwable) {
                    // The file needs something the site sets up later: the code alone decides.
                }
                self::$bootSwitches = $switches;
                self::registerLoginSecurity(self::$bootSwitches['two_factor'], self::$bootSwitches['email_verification']);
                return;
            } else {
                return;
            }
        }
        self::registerLoginSecurity($config->twoFactor()['enabled'], $config->emailVerification()['enabled']);
    }

    private static function registerLoginSecurity(bool $twoFactor, bool $verification): void
    {
        if (!self::$twoFactorBooted && $twoFactor) {
            self::$twoFactorBooted = true;
            \FloCMS\Admin\Auth\TwoFactor::boot();
        }
        if (!self::$verificationBooted && $verification) {
            self::$verificationBooted = true;
            \FloCMS\Admin\Auth\EmailVerification::boot();
        }
    }

    /**
     * A switch that is on but wasn't registered when the panel started (off
     * when the file ran early, and built in a way ConfigSwitches can't follow,
     * such as a value from .env in another file) can't be asked at sign-in: it is turned off for this
     * request, so the panel shows what is enforced, and a warning is logged.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function withoutLateSwitches(array $values, string $root, bool $installed): array
    {
        if (self::$bootSwitches === null) { return $values; }
        $config = new AdminConfig($values, $root, $installed);
        $late = [];
        foreach (['two_factor' => $config->twoFactor()['enabled'], 'email_verification' => $config->emailVerification()['enabled']] as $key => $enabled) {
            if ($enabled && !self::$bootSwitches[$key]) {
                $late[] = $key;
                if (is_array($values[$key] ?? null)) { $values[$key]['enabled'] = false; } else { $values[$key] = false; }
            }
        }
        if ($late !== [] && !self::$lateSwitchWarned) {
            self::$lateSwitchWarned = true;
            try {
                App::logger()->log('warning', '[flocms-admin] ' . implode(' and ', array_map(static fn (string $key): string => "'" . $key . "'", $late))
                    . ' in config/admin.php is on, but the panel could not see it when it started, so it stays off.'
                    . ' Write it in config/admin.php itself (true, an array, or a value such as Env::get()). php flo admin:doctor reports it too.');
            } catch (Throwable) {
                // Logging must never break a page
            }
        }

        return $values;
    }

    /**
     * Where /admin goes when the panel is set up (config/admin.php exists), else
     * null: the dashboard, or the panel page set as 'home' (0.36) for people who
     * may open it.
     */
    public static function homePath(): ?string
    {
        if (!self::config()->isInstalled()) { return null; }
        $page = self::homePage();
        return $page !== null ? $page::getSlug() : 'dashboard';
    }

    /**
     * The panel page set as 'home' in config/admin.php, when it is registered and
     * the signed-in person may open it; else null (the dashboard).
     *
     * @return class-string<AdminPage>|null
     */
    public static function homePage(): ?string
    {
        $home = self::config()->home();
        if ($home === null) { return null; }
        try {
            $class = self::panelPages()->findClass($home);
            return $class !== null && (new $class())->allowed() ? $class : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The controller resolver: ResourceController for /admin/<slug> when
     * <slug> is a registered resource, otherwise null (FloCMS answers 404).
     */
    public static function resolve(string $controller, string $route): ?string
    {
        if ($route === 'default' && $controller === 'flo-admin') { return \FloCMS\Admin\Http\AssetController::class; }
        if ($route !== 'admin') {
            return null;
        }

        $config = self::config();
        if (!$config->isInstalled()) {
            return null;
        }

        $registry = self::registry();
        self::logProblems($registry);

        if ($controller === 'dashboard') {
            return DashboardController::class;
        }
        if ($controller === 'settings') {
            return \FloCMS\Admin\Http\SettingsController::class;
        }
        if ($controller === 'media' && \FloCMS\Admin\Media\MediaLibrary::enabled()) {
            return \FloCMS\Admin\Http\MediaController::class;
        }
        if ($controller === 'password' && $config->passwordReset()['enabled']) {
            return \FloCMS\Admin\Http\PasswordController::class;
        }
        // Login security (0.35): the sign-in steps and the email links.
        if ($controller === \FloCMS\Admin\Auth\TwoFactor::PATH && $config->twoFactor()['enabled']) {
            return \FloCMS\Admin\Http\TwoFactorController::class;
        }
        if ($controller === \FloCMS\Admin\Auth\EmailVerification::PATH && $config->emailVerification()['enabled']) {
            return \FloCMS\Admin\Http\VerifyController::class;
        }
        if ($controller === 'profile' && $config->usersEnabled()) {
            return ProfileController::class;
        }
        if ($registry->find($controller) !== null) {
            return ResourceController::class;
        }
        // The site's own panel pages (0.36)
        if (self::panelPages()->find($controller) !== null) {
            return \FloCMS\Admin\Http\CustomPageController::class;
        }
        // The trash and the activity log (0.19); a site resource with that slug keeps it.
        if ($controller === 'trash' && $config->trash()['enabled']) {
            return \FloCMS\Admin\Http\TrashController::class;
        }
        if ($controller === 'activity' && $config->activity()['enabled']) {
            return \FloCMS\Admin\Http\ActivityController::class;
        }
        // Emails to users (0.28); a site resource with that slug keeps it.
        if ($controller === 'mailings' && $config->mailings()['enabled']) {
            return \FloCMS\Admin\Http\MailingsController::class;
        }
        // Site → Imports (0.25); a site resource with that slug keeps it.
        if ($controller === 'imports' && $config->import()['enabled']) {
            return \FloCMS\Admin\Http\ImportsController::class;
        }
        // Roles and permissions (0.21); a site resource with that slug keeps it.
        if ($controller === 'roles' && $config->roles()['enabled']) {
            return \FloCMS\Admin\Http\RolesController::class;
        }
        // A page's preview (0.30)
        if ($controller === 'page-preview' && $config->pages()['enabled']) {
            return \FloCMS\Admin\Http\PagePreviewController::class;
        }
        // Pages › Blocks preview (0.33)
        if ($controller === 'blocks-preview' && $config->pages()['enabled']) {
            return \FloCMS\Admin\Http\BlocksPreviewController::class;
        }
        // Ctrl+K search (0.20); a site resource with that slug keeps it.
        if ($controller === 'search' && $config->search()['enabled']) {
            return \FloCMS\Admin\Http\SearchController::class;
        }

        return null;
    }

    /** Project root: ROOT, or the one set by a command or a test. */
    public static function root(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }

        return defined('ROOT') ? (string) constant('ROOT') : (string) getcwd();
    }

    public static function setRoot(?string $root): void
    {
        self::$root = $root === null ? null : rtrim($root, '/\\');
        self::$config = null;
        self::$registry = null;
        self::$settings = null;
        self::$panelPages = null;
        \FloCMS\Admin\Settings\Settings::reset();
        \FloCMS\Admin\Blocks\BlockRegistry::reset();
    }

    public static function config(): AdminConfig
    {
        if (self::$config !== null) {
            return self::$config;
        }

        [$values, $installed, $root] = self::readConfigFile();

        return self::useConfig(self::withoutLateSwitches($values, $root, $installed), $installed, $root);
    }

    /** The site's config/admin.php. */
    public static function configFile(): string
    {
        return self::root() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'admin.php';
    }

    /** @return array{0: array<string, mixed>, 1: bool, 2: string} the values, whether the file exists, the root */
    private static function readConfigFile(): array
    {
        $root = self::root();
        $file = self::configFile();
        $values = [];
        $installed = is_file($file);

        if ($installed) {
            $loaded = (static fn (string $__file): mixed => require $__file)($file);
            $values = is_array($loaded) ? $loaded : [];
        }

        return [$values, $installed, $root];
    }

    /**
     * Use these settings instead of config/admin.php (tests).
     *
     * @param array<string, mixed> $values
     */
    public static function useConfig(array $values, bool $installed = true, ?string $root = null): AdminConfig
    {
        self::$config = new AdminConfig($values, $root ?? self::root(), $installed);
        self::$registry = null;
        self::$settings = null;
        self::$panelPages = null;
        \FloCMS\Admin\Settings\Settings::reset();
        \FloCMS\Admin\Media\MediaLibrary::reset();
        \FloCMS\Admin\Trash\Trash::reset();
        \FloCMS\Admin\Activity\Activity::reset();
        \FloCMS\Admin\Roles\Roles::reset();
        \FloCMS\Admin\Relations\RelationManager::reset();
        Languages::setRtl(self::$config->rtlLanguages());
        \FloCMS\Admin\Auth\TwoFactor::reset();
        \FloCMS\Admin\Auth\EmailVerification::reset();
        self::bootLoginSecurity(self::$config);

        return self::$config;
    }

    public static function registry(): ResourceRegistry
    {
        return self::$registry ??= ResourceRegistry::fromConfig(self::config());
    }

    /** The site's own panel pages (0.36), from 'admin_pages' in config/admin.php. */
    public static function panelPages(): \FloCMS\Admin\PanelPages\PanelPageRegistry
    {
        return self::$panelPages ??= \FloCMS\Admin\PanelPages\PanelPageRegistry::fromConfig(self::config(), self::registry());
    }

    /** The settings pages: admin/settings.php and the panel's own page. */
    public static function settings(): \FloCMS\Admin\Settings\SettingsRegistry
    {
        return self::$settings ??= \FloCMS\Admin\Settings\SettingsRegistry::fromConfig(self::config());
    }

    /** Forget configuration and resources (tests). The resolver stays registered. */
    public static function reset(): void
    {
        self::$root = null;
        self::$config = null;
        self::$registry = null;
        self::$settings = null;
        self::$panelPages = null;
        \FloCMS\Admin\Settings\Settings::reset();
        \FloCMS\Admin\Preferences\Preferences::reset();
        \FloCMS\Admin\Media\MediaLibrary::reset();
        \FloCMS\Admin\Trash\Trash::reset();
        \FloCMS\Admin\Activity\Activity::reset();
        \FloCMS\Admin\Roles\Roles::reset();
        \FloCMS\Admin\Relations\RelationManager::reset();
        self::$problemsLogged = false;
        self::$bootSwitches = null;
        self::$lateSwitchWarned = false;
        Languages::reset();
    }

    /**
     * Absolute URL of an admin page in the current language:
     * url('properties/edit/5') → https://site/en/admin/properties/edit/5
     *
     * @param array<string, scalar|null> $query
     */
    public static function url(string $path = '', array $query = []): string
    {
        $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
        $lang = defined('ACTIVE_LANG') ? (string) constant('ACTIVE_LANG') : Languages::default();

        $url = $base . '/' . rawurlencode($lang) . '/admin';
        $path = trim($path, '/');
        if ($path !== '') {
            $url .= '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
        }

        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');

        return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** In debug mode, write skipped resources to storage/logs/app.log once per request. */
    private static function logProblems(ResourceRegistry $registry): void
    {
        if (self::$problemsLogged) {
            return;
        }
        self::$problemsLogged = true;

        try {
            if (Env::get('APP_DEBUG') !== true) {
                return;
            }
            foreach ($registry->problems() as $problem) {
                App::logger()->log('warning', '[flocms-admin] Resource skipped: ' . $problem);
            }
            foreach (self::panelPages()->problems() as $problem) {
                App::logger()->log('warning', '[flocms-admin] Panel page skipped: ' . $problem);
            }
        } catch (Throwable) {
            // Logging must never break a page
        }
    }
}
