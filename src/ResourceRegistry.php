<?php
declare(strict_types=1);

namespace FloCMS\Admin;

use FloCMS\Admin\Support\Str;
use ReflectionClass;

/**
 * The usable resources of the site, by slug, and the reasons others were skipped.
 */
final class ResourceRegistry
{
    /** URL segments the skeleton's own controllers use. */
    public const RESERVED = ['users', 'pages'];

    /** URL segments of this package's own pages. */
    public const PACKAGE_PAGES = ['dashboard', 'accounts', 'profile', 'settings', 'media', 'password', 'page-preview'];

    /** @var array<string, class-string<Resource>> slug => class */
    private array $resources = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, true> slugs of the package's built-in resources */
    private array $builtIn = [];

    /** @var array<string, true> */
    private static array $autoloaders = [];

    public static function fromConfig(AdminConfig $config): self
    {
        $registry = new self();
        self::registerAutoloader($config->resourcesNamespace(), $config->resourcesPath());

        $classes = $config->resources();
        if ($classes === []) {
            $classes = self::discover($config->resourcesNamespace(), $config->resourcesPath());
        }

        foreach ($classes as $class) {
            $registry->add($class);
        }

        if ($config->usersEnabled()) {
            $registry->add(\FloCMS\Admin\Users\UsersResource::class, true);
        }
        // Site → Pages (0.30), with 'pages' => true
        if ($config->pages()['enabled']) {
            $registry->add(\FloCMS\Admin\Pages\PagesResource::class, true);
        }

        return $registry;
    }

    /** Add a resource class; problems are collected instead of thrown. */
    public function add(string $class, bool $builtIn = false): bool
    {
        $class = ltrim($class, '\\');

        if (!class_exists($class)) {
            $this->problems[] = $class . ': class not found';
            return false;
        }
        if (!is_subclass_of($class, Resource::class) || !(new ReflectionClass($class))->isInstantiable()) {
            $this->problems[] = $class . ': must be an instantiable subclass of ' . Resource::class;
            return false;
        }

        /** @var class-string<Resource> $class */
        $errors = $class::configurationErrors();
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->problems[] = $class . ': ' . $error;
            }
            return false;
        }

        try {
            foreach ((new $class())->form(new \FloCMS\Admin\Form\Form())->fields() as $field) {
                if ($field instanceof \FloCMS\Admin\Form\Fields\FileUpload && $field->isTranslatable()) {
                    $this->problems[] = $class . ': field "' . $field->getName() . '" is a translatable upload; translatable uploads are supported on settings pages only';
                    return false;
                }
            }
        } catch (\Throwable) {
            // A form that cannot be built here is reported where it is used (admin:doctor, the page)
        }

        $slug = $class::getSlug();

        if (isset($this->resources[$slug])) {
            $this->problems[] = $class . ': slug "' . $slug . '" is already used by ' . $this->resources[$slug];
            return false;
        }

        if (!$builtIn && in_array($slug, self::PACKAGE_PAGES, true)) {
            $this->problems[] = $class . ': slug "' . $slug . '" is reserved for the admin panel\'s own pages ('
                . implode(', ', self::PACKAGE_PAGES) . '); set another $slug';
            return false;
        }

        $controller = Str::appControllerClass($slug);
        if (in_array($slug, self::RESERVED, true) || class_exists($controller)) {
            $this->problems[] = $class . ': slug "' . $slug . '" is taken by the application controller '
                . $controller . ', which always wins; set another $slug (e.g. "' . $slug . '-admin")';
            return false;
        }

        $this->resources[$slug] = $class;
        if ($builtIn) {
            $this->builtIn[$slug] = true;
        }

        return true;
    }

    /** @return class-string<Resource>|null */
    public function find(string $slug): ?string
    {
        return $this->resources[$slug] ?? null;
    }

    public function isBuiltIn(string $slug): bool
    {
        return isset($this->builtIn[$slug]);
    }

    /**
     * The site's own resources in navigation order (built-in ones excluded).
     *
     * @return array<string, class-string<Resource>>
     */
    public function all(): array
    {
        $resources = array_diff_key($this->resources, $this->builtIn);
        uasort($resources, static function (string $a, string $b): int {
            /** @var class-string<Resource> $a */
            /** @var class-string<Resource> $b */
            return [$a::getNavigationSort(), $a::getPluralLabel()] <=> [$b::getNavigationSort(), $b::getPluralLabel()];
        });

        return $resources;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<string> */
    private static function discover(string $namespace, string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = glob(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '*Resource.php') ?: [];
        sort($files);

        return array_map(
            static fn (string $file): string => $namespace . '\\' . basename($file, '.php'),
            $files
        );
    }

    /**
     * PSR-4 autoloading of the site's resource classes, so the site's
     * composer.json does not need an entry for them.
     */
    private static function registerAutoloader(string $namespace, string $path): void
    {
        $key = $namespace . '|' . $path;
        if (isset(self::$autoloaders[$key])) {
            return;
        }
        self::$autoloaders[$key] = true;

        $prefix = $namespace . '\\';
        spl_autoload_register(static function (string $class) use ($prefix, $path): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
                return;
            }
            $file = rtrim($path, '/\\') . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
