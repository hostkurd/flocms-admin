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
    public const VERSION = '0.20.0';

    private static bool $booted = false;
    private static ?string $root = null;
    private static ?AdminConfig $config = null;
    private static ?ResourceRegistry $registry = null;
    private static ?\FloCMS\Admin\Settings\SettingsRegistry $settings = null;
    private static bool $problemsLogged = false;

    /** Register the controller resolver (once). Called from bootstrap.php. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        App::resolveControllersUsing(static fn (string $controller, string $route): ?string => self::resolve($controller, $route));
        if (Config::get('auth.login_view') === null) { Config::set('auth.login_view', static fn (): ?string => \FloCMS\Admin\Auth\LoginView::render()); }

        // Where the skeleton's /admin page sends logged-in users (skeleton 1.8+).
        // A site can set its own 'admin.home' in config/config.php, which runs later.
        if (Config::get('admin.home') === null) {
            Config::set('admin.home', static fn (): ?string => self::homePath());
        }
    }

    /** 'dashboard' when the panel is set up (config/admin.php exists), else null. */
    public static function homePath(): ?string
    {
        return self::config()->isInstalled() ? 'dashboard' : null;
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
        if ($controller === 'profile' && $config->usersEnabled()) {
            return ProfileController::class;
        }
        if ($registry->find($controller) !== null) {
            return ResourceController::class;
        }
        // The trash and the activity log (0.19); a site resource with that slug keeps it.
        if ($controller === 'trash' && $config->trash()['enabled']) {
            return \FloCMS\Admin\Http\TrashController::class;
        }
        if ($controller === 'activity' && $config->activity()['enabled']) {
            return \FloCMS\Admin\Http\ActivityController::class;
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
        \FloCMS\Admin\Settings\Settings::reset();
    }

    public static function config(): AdminConfig
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $root = self::root();
        $file = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'admin.php';
        $values = [];
        $installed = is_file($file);

        if ($installed) {
            $loaded = (static fn (string $__file): mixed => require $__file)($file);
            $values = is_array($loaded) ? $loaded : [];
        }

        return self::useConfig($values, $installed, $root);
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
        \FloCMS\Admin\Settings\Settings::reset();
        \FloCMS\Admin\Media\MediaLibrary::reset();
        \FloCMS\Admin\Trash\Trash::reset();
        \FloCMS\Admin\Activity\Activity::reset();
        Languages::setRtl(self::$config->rtlLanguages());

        return self::$config;
    }

    public static function registry(): ResourceRegistry
    {
        return self::$registry ??= ResourceRegistry::fromConfig(self::config());
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
        \FloCMS\Admin\Settings\Settings::reset();
        \FloCMS\Admin\Preferences\Preferences::reset();
        \FloCMS\Admin\Media\MediaLibrary::reset();
        \FloCMS\Admin\Trash\Trash::reset();
        \FloCMS\Admin\Activity\Activity::reset();
        self::$problemsLogged = false;
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
        if (self::$problemsLogged || $registry->problems() === []) {
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
        } catch (Throwable) {
            // Logging must never break a page
        }
    }
}
