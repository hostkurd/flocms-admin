<?php
declare(strict_types=1);

namespace FloCMS\Admin\Settings;

use Closure;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use InvalidArgumentException;

/**
 * One page of website settings, declared in admin/settings.php:
 *
 *     SettingsPage::make('site')->label('General')->icon('settings')
 *         ->description('Name, tagline and logo')
 *         ->schema([
 *             TextInput::make('title')->translatable()->required(),
 *             ImageUpload::make('logo')->translatable(),
 *         ]);
 *
 * Each field is stored under "<page>.<field>" ("site.title") and read on the
 * website with setting('site.title').
 */
final class SettingsPage
{
    /** Page names: lower case letters, digits and "_", starting with a letter. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    private string $name;
    private ?string $label = null;
    private string $icon = 'settings';
    private ?string $description = null;
    private array $components = [];
    private int $columns = 2;
    private string $permission = 'settings.manage';
    private bool $badges = false;
    private ?Closure $beforeSave = null;
    private ?Closure $afterSave = null;

    private function __construct(string $name)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Invalid settings page name "' . $name . '": use lower case letters, digits and "_", starting with a letter.');
        }
        $this->name = $name;
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    /** One of the panel's icon names (see Icons), shown in the list of pages. */
    public function icon(string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    /** A short line under the label in the list of pages and under the page title. */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /** Fields and layouts, exactly as in a resource form. */
    public function schema(array $components): self
    {
        $this->components = $components;
        (new Form())->schema($components); // fail early on duplicate names or wrong items
        return $this;
    }

    public function columns(int $columns): self
    {
        if ($columns < 1 || $columns > 4) { throw new InvalidArgumentException('Settings pages support 1–4 columns.'); }
        $this->columns = $columns;
        return $this;
    }

    /** Permission needed to open and save this page (default settings.manage). */
    public function permission(string $permission): self
    {
        if (preg_match('/^[A-Za-z0-9_.*-]+$/D', $permission) !== 1) { throw new InvalidArgumentException('Invalid permission "' . $permission . '".'); }
        $this->permission = $permission;
        return $this;
    }

    /**
     * Translatable fields of this page use the language badge and dialog of
     * resource forms instead of the page-level "Editing: language" switch.
     */
    public function translationBadges(bool $badges = true): self
    {
        $this->badges = $badges;
        return $this;
    }

    /**
     * Change the values before they are stored: fn (array $values): array,
     * keyed by field name; translatable values are ['en' => …, 'ar' => …].
     */
    public function beforeSave(Closure $callback): self
    {
        $this->beforeSave = $callback;
        return $this;
    }

    /** Runs after the values are stored and the cache is rebuilt: fn (array $values): void. */
    public function afterSave(Closure $callback): self
    {
        $this->afterSave = $callback;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return AdminLang::label($this->label ?? Str::headline($this->name));
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getDescription(): ?string
    {
        return $this->description === null ? null : AdminLang::label($this->description);
    }

    public function getPermission(): string
    {
        return $this->permission;
    }

    public function usesBadges(): bool
    {
        return $this->badges;
    }

    public function form(): Form
    {
        return (new Form())->columns($this->columns)->schema($this->components);
    }

    /** @return array<string, Field> fields whose value is stored, by name */
    public function storedFields(): array
    {
        return array_filter($this->form()->fields(), static fn (Field $f): bool => !$f instanceof \FloCMS\Admin\Form\Fields\Placeholder && !$f->isDisabled());
    }

    public function key(string $field): string
    {
        return $this->name . '.' . $field;
    }

    /** @param array<string, mixed> $values */
    public function runBeforeSave(array $values): array
    {
        if ($this->beforeSave === null) { return $values; }
        $result = ($this->beforeSave)($values);
        if (!is_array($result)) { throw new \LogicException('beforeSave() of settings page "' . $this->name . '" must return the values array.'); }
        return $result;
    }

    /** @param array<string, mixed> $values */
    public function runAfterSave(array $values): void
    {
        if ($this->afterSave !== null) { ($this->afterSave)($values); }
    }
}
