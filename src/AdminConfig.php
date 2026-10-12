<?php
declare(strict_types=1);

namespace FloCMS\Admin;

use FloCMS\Admin\Translation\Languages;

/**
 * Values of config/admin.php (written by `php flo admin:install`), with defaults.
 */
final class AdminConfig
{
    /** @param array<string, mixed> $values */
    public function __construct(
        private readonly array $values,
        private readonly string $root,
        private readonly bool $installed
    ) {
    }

    /** Whether config/admin.php exists: without it the package stays out of the way. */
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<class-string<Resource>> classes listed explicitly; [] means "scan resources_path" */
    public function resources(): array
    {
        $list = $this->values['resources'] ?? [];

        return array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
    }

    public function resourcesPath(): string
    {
        $path = (string) ($this->values['resources_path'] ?? 'admin/Resources');
        $path = str_replace('\\', '/', $path);

        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;

        return $absolute ? rtrim($path, '/') : rtrim($this->root, '/\\') . '/' . trim($path, '/');
    }

    public function resourcesNamespace(): string
    {
        return trim((string) ($this->values['resources_namespace'] ?? 'App\\Admin\\Resources'), '\\');
    }

    public function brand(): string
    {
        $brand = $this->values['brand'] ?? null;

        return is_string($brand) && $brand !== '' ? $brand : 'Admin';
    }

    public function perPage(): int
    {
        return max(1, min(500, (int) ($this->values['per_page'] ?? 25)));
    }

    /** @return list<string> */
    public function rtlLanguages(): array
    {
        $list = $this->values['rtl_languages'] ?? Languages::DEFAULT_RTL;

        return array_values(array_map('strval', is_array($list) ? $list : Languages::DEFAULT_RTL));
    }

    /** Whether the built-in users management (/admin/accounts) and profile page are on. */
    public function usersEnabled(): bool
    {
        return ($this->values['users'] ?? true) !== false;
    }

    public function permissionPrefix(): string
    {
        $prefix = trim((string) ($this->values['permission_prefix'] ?? 'content'), '.');

        return $prefix === '' ? 'content' : $prefix;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
