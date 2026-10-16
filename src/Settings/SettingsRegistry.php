<?php
declare(strict_types=1);

namespace FloCMS\Admin\Settings;

use FloCMS\Admin\AdminConfig;
use Throwable;

/**
 * The settings pages of the site (admin/settings.php) and the panel's own
 * "Admin panel" page, by name, plus the reasons pages were skipped.
 */
final class SettingsRegistry
{
    /** Page names the package uses itself. */
    public const RESERVED = ['panel'];

    /** @var array<string, SettingsPage> */
    private array $pages = [];
    /** @var list<string> */
    private array $problems = [];
    private bool $fileFound = false;

    public static function fromConfig(AdminConfig $config): self
    {
        $registry = new self();
        $file = $config->settingsPath();
        if (is_file($file)) {
            $registry->fileFound = true;
            try {
                $pages = (static fn (string $__file): mixed => require $__file)($file);
            } catch (Throwable $e) {
                $registry->problems[] = basename($file) . ': ' . $e->getMessage();
                $pages = [];
            }
            if (!is_array($pages)) {
                $registry->problems[] = basename($file) . ' must return an array of SettingsPage objects';
                $pages = [];
            }
            foreach ($pages as $page) {
                $registry->add($page);
            }
        }
        if ($config->panelSettingsEnabled()) {
            $registry->add(PanelSettings::page(), true);
        }

        return $registry;
    }

    public function add(mixed $page, bool $builtIn = false): bool
    {
        if (!$page instanceof SettingsPage) {
            $this->problems[] = 'admin/settings.php: every item must be a SettingsPage (got ' . get_debug_type($page) . ')';
            return false;
        }
        $name = $page->getName();
        if (!$builtIn && in_array($name, self::RESERVED, true)) {
            $this->problems[] = 'settings page "' . $name . '" is reserved for the panel\'s own settings; choose another name';
            return false;
        }
        if (isset($this->pages[$name])) {
            $this->problems[] = 'settings page "' . $name . '" is declared twice';
            return false;
        }
        try {
            foreach ($page->form()->fields() as $field) {
                if ($field instanceof \FloCMS\Admin\Form\Fields\Slug) {
                    throw new \LogicException('field "' . $field->getName() . '": Slug fields are for records, not settings');
                }
            }
        } catch (Throwable $e) {
            $this->problems[] = 'settings page "' . $name . '": ' . $e->getMessage();
            return false;
        }
        $this->pages[$name] = $page;
        return true;
    }

    /** @return array<string, SettingsPage> site pages first, in declaration order, then the panel page */
    public function all(): array
    {
        return $this->pages;
    }

    public function find(string $name): ?SettingsPage
    {
        return $this->pages[$name] ?? null;
    }

    /** Whether admin/settings.php exists. */
    public function fileFound(): bool
    {
        return $this->fileFound;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * Every stored key with its field: "site.title" => [page, field].
     *
     * @return array<string, array{0: SettingsPage, 1: \FloCMS\Admin\Form\Field}>
     */
    public function fields(): array
    {
        $fields = [];
        foreach ($this->pages as $page) {
            foreach ($page->storedFields() as $name => $field) {
                $fields[$page->key($name)] = [$page, $field];
            }
        }
        return $fields;
    }
}
