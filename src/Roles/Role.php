<?php
declare(strict_types=1);

namespace FloCMS\Admin\Roles;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Translations;

/**
 * One role as the Roles page shows it: a role of config/config.php (possibly
 * changed in the panel) or one added in the panel.
 */
final class Role
{
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_CHANGED = 'changed';
    public const SOURCE_CUSTOM = 'custom';

    /**
     * @param array<string, string>|string $name language code => name, or one name for all languages
     * @param list<string> $permissions patterns ('name', 'prefix.*', '*')
     */
    public function __construct(
        public readonly int $number,
        public readonly array|string $name,
        public readonly int $level,
        public readonly bool $adminAccess,
        public readonly array $permissions,
        public readonly string $source,
    ) {
    }

    /** The name in the panel's language (a config name may be a lang key). */
    public function label(?string $lang = null): string
    {
        if (is_array($this->name)) {
            $text = Translations::get($this->name, $lang ?? AdminLang::language());
            return $text !== '' ? $text : '#' . $this->number;
        }
        $text = AdminLang::label($this->name);
        return $text !== '' ? $text : '#' . $this->number;
    }

    /** @return array<string, string> language => name, for the translatable Name field */
    public function names(): array
    {
        return is_array($this->name) ? $this->name : [\FloCMS\Admin\Translation\Languages::default() => $this->label(\FloCMS\Admin\Translation\Languages::default())];
    }

    public function fromConfig(): bool
    {
        return $this->source !== self::SOURCE_CUSTOM;
    }

    public function changed(): bool
    {
        return $this->source === self::SOURCE_CHANGED;
    }

    public function holdsEverything(): bool
    {
        return in_array('*', $this->permissions, true);
    }

    /** Whether this role's patterns grant $permission (as FloCMS\Core\Auth::can() matches them). */
    public function grants(string $permission): bool
    {
        foreach ($this->permissions as $pattern) {
            if (Roles::matches($pattern, $permission)) { return true; }
        }
        return false;
    }

    /** @param list<string> $permissions */
    public function with(array|string|null $name = null, ?int $level = null, ?bool $adminAccess = null, ?array $permissions = null, ?string $source = null): self
    {
        return new self($this->number, $name ?? $this->name, $level ?? $this->level, $adminAccess ?? $this->adminAccess,
            $permissions ?? $this->permissions, $source ?? $this->source);
    }
}
