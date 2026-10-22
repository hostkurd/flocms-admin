<?php
declare(strict_types=1);

namespace FloCMS\Admin\PanelPages;

use FloCMS\Admin\AdminConfig;
use FloCMS\Admin\AdminPage;
use FloCMS\Admin\ResourceRegistry;
use FloCMS\Admin\Support\Str;
use ReflectionClass;

/**
 * The site's own panel pages (0.36) by slug, from config/admin.php
 * ('admin_pages'), and the reasons others were skipped.
 */
final class PanelPageRegistry
{
    /** Slugs of the panel's own pages and controllers, whether or not they are turned on. */
    public const TAKEN = ['dashboard', 'accounts', 'profile', 'settings', 'media', 'password', 'two-factor', 'verify', 'trash', 'activity',
        'mailings', 'imports', 'roles', 'page-preview', 'blocks-preview', 'search', 'site-pages', 'flo-admin'];

    /** @var array<string, class-string<AdminPage>> */
    private array $pages = [];
    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, true> */
    private static array $autoloaders = [];

    public static function fromConfig(AdminConfig $config, ResourceRegistry $resources): self
    {
        $registry = new self();
        self::registerAutoloader($config->adminPagesNamespace(), $config->adminPagesPath());
        $raw = $config->all()['admin_pages'] ?? [];
        if (!is_array($raw)) {
            $registry->problems[] = "'admin_pages' must be a list of page classes";
            return $registry;
        }
        if (count($raw) > 100) {
            $registry->problems[] = "'admin_pages' lists more than 100 pages";
            return $registry;
        }
        foreach ($raw as $class) {
            if (!is_string($class)) {
                $registry->problems[] = "'admin_pages': list page classes as Name::class strings (got " . get_debug_type($class) . ')';
                continue;
            }
            $registry->add($class, $resources, $config);
        }
        return $registry;
    }

    public function add(string $class, ResourceRegistry $resources, AdminConfig $config): bool
    {
        $class = ltrim($class, '\\');
        if (!class_exists($class)) {
            $this->problems[] = $class . ': ' . self::missing($class, $config);
            return false;
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isSubclassOf(AdminPage::class) || !$reflection->isInstantiable()) {
            $this->problems[] = $class . ': must be an instantiable subclass of ' . AdminPage::class;
            return false;
        }
        $class = $reflection->getName();
        /** @var class-string<AdminPage> $class */
        $errors = $class::configurationErrors();
        foreach ($errors as $error) {
            $this->problems[] = $class . ': ' . $error;
        }
        if ($errors !== []) {
            return false;
        }
        $slug = $class::getSlug();
        if (in_array($class, $this->pages, true)) {
            $this->problems[] = $class . ': listed twice';
            return false;
        }
        if (isset($this->pages[$slug])) {
            $this->problems[] = $class . ': slug "' . $slug . '" is already used by ' . $this->pages[$slug];
            return false;
        }
        if (in_array($slug, self::TAKEN, true) || in_array($slug, ResourceRegistry::packagePages(), true)) {
            $this->problems[] = $class . ': slug "' . $slug . '" is one of the panel\'s own pages; set another $slug';
            return false;
        }
        if (($resource = $resources->find($slug)) !== null) {
            $this->problems[] = $class . ': slug "' . $slug . '" is used by the resource ' . $resource . '; set another $slug';
            return false;
        }
        $controller = Str::appControllerClass($slug);
        if (in_array($slug, ResourceRegistry::RESERVED, true) || class_exists($controller)) {
            $this->problems[] = $class . ': slug "' . $slug . '" is taken by the application controller ' . $controller . ', which always wins; set another $slug';
            return false;
        }
        $this->pages[$slug] = $class;
        return true;
    }

    /** @return class-string<AdminPage>|null */
    public function find(string $slug): ?string
    {
        return $this->pages[$slug] ?? null;
    }

    /** @return class-string<AdminPage>|null the registered page of this class */
    public function findClass(string $class): ?string
    {
        $class = ltrim($class, '\\');
        foreach ($this->pages as $registered) {
            if (strcasecmp($registered, $class) === 0) { return $registered; }
        }
        return null;
    }

    /**
     * Pages in sidebar order: by sort, then label.
     *
     * @return array<string, class-string<AdminPage>>
     */
    public function all(): array
    {
        $pages = $this->pages;
        uasort($pages, static fn (string $a, string $b): int => [$a::getSort(), $a::getLabel()] <=> [$b::getSort(), $b::getLabel()]);
        return $pages;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    private static function missing(string $class, AdminConfig $config): string
    {
        $namespace = $config->adminPagesNamespace();
        if ($namespace === '' || !str_starts_with($class, $namespace . '\\')) {
            return 'class not found: it is outside admin_pages_namespace (' . $namespace . '), so Composer must autoload it';
        }
        $file = $config->adminPagesPath() . '/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
        $root = rtrim($config->root(), '/\\') . '/';
        $shown = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
        return is_file($file)
            ? 'class not found in ' . $shown . ': check its namespace and class name'
            : 'class not found: expected the file ' . $shown . ' (create it with: php flo make:admin-page ' . basename($file, '.php') . ')';
    }

    /** PSR-4 autoloading of admin/Pages, so the site's composer.json needs no entry. */
    private static function registerAutoloader(string $namespace, string $path): void
    {
        $key = $namespace . '|' . $path;
        if ($namespace === '' || isset(self::$autoloaders[$key])) {
            return;
        }
        self::$autoloaders[$key] = true;
        $prefix = $namespace . '\\';
        spl_autoload_register(static function (string $class) use ($prefix, $path): void {
            if (!str_starts_with($class, $prefix)) { return; }
            $relative = substr($class, strlen($prefix));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $relative) !== 1) { return; }
            $file = rtrim($path, '/\\') . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) { require_once $file; }
        });
    }
}
