<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Translations;
use InvalidArgumentException;
use LogicException;

/**
 * Base class of every form field.
 *
 * A field's value goes through three shapes:
 *  - the stored value (a column of the record),
 *  - the form state: a string, or ['en' => '...', 'ar' => '...'] when translatable,
 *  - the value written back by dehydrate().
 */
abstract class Field
{
    protected string $name;
    protected ?string $label = null;
    /** @var list<string> */
    protected array $rules = [];
    protected mixed $default = null;
    protected ?string $help = null;
    protected ?string $placeholder = null;
    protected bool $disabled = false;
    protected bool $translatable = false;
    protected ?bool $nullable = null;
    protected bool $unique = false;
    protected bool $requiredOnCreate = false;
    protected int|string $columnSpan = 1;
    protected bool $hiddenFromActivity = false;
    private ?array $visibleCondition = null;
    private ?array $requiredCondition = null;
    private bool $visible = true;
    private bool $omitted = false;
    private ?string $fixed = null;
    /**
     * Leave the field out of this form entirely (0.22): not shown, not validated
     * and not written. A relation manager omits the parent's key and its
     * hideFields(); the server fills the key itself. @internal
     */
    public function omit(?string $value = null): static { $this->omitted = true; $this->fixed = $value; $this->visible = false; $this->visibleCondition = null; $this->requiredCondition = null; return $this; }
    public function isOmitted(): bool { return $this->omitted; }
    /** The value an omitted field holds anyway (a relation manager's parent key), so other fields and rules see it. */
    public function fixedValue(): ?string { return $this->fixed; }
    public function visibleWhen(string $field, string|array $values): static { $this->visibleCondition = self::condition($field, $values); return $this; }
    public function requiredWhen(string $field, string|array $values): static { $this->requiredCondition = self::condition($field, $values); return $this; }
    private static function condition(string $field, string|array $values): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field) !== 1) { throw new InvalidArgumentException('Invalid dependency field name.'); }
        foreach ((array) $values as $value) { if (!is_string($value)) { throw new InvalidArgumentException('Condition values must be strings.'); } }
        return [$field, array_values((array) $values)];
    }
    public function dependencies(): array { return array_values(array_unique(array_filter([$this->visibleCondition[0] ?? null, $this->requiredCondition[0] ?? null]))); }
    public function isVisible(): bool { return $this->visible; }
    public function resolve(array $state): static
    {
        $field = clone $this;
        if ($this->omitted) { return $field; }
        if ($this->visibleCondition !== null) { [$parent, $values] = $this->visibleCondition; $field->visible = in_array($state[$parent] ?? '', $values, true); }
        if ($this->requiredCondition !== null) { [$parent, $values] = $this->requiredCondition; $field->required(in_array($state[$parent] ?? '', $values, true)); }
        return $field;
    }

    final public function __construct(string $name)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid field name "' . $name . '": use letters, digits and "_" (the column name).');
        }

        $this->name = $name;
        $this->setUp();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    /** Hook for subclasses that need defaults. */
    protected function setUp(): void
    {
    }

    /* ---------------------------------------------------------------
     * Fluent configuration
     * ------------------------------------------------------------- */

    public function label(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    /** FloCMS validation rules, e.g. 'required|max:190'. */
    public function rules(string|array $rules): static
    {
        $list = is_array($rules) ? $rules : explode('|', $rules);
        foreach ($list as $rule) {
            $rule = trim((string) $rule);
            if ($rule !== '' && !in_array($rule, $this->rules, true)) {
                $this->rules[] = $rule;
            }
        }

        return $this;
    }

    public function required(bool $required = true): static
    {
        $this->rules = array_values(array_filter($this->rules, static fn (string $r): bool => $r !== 'required'));
        if ($required) {
            array_unshift($this->rules, 'required');
        }

        return $this;
    }

    /** Required on the create page only (a password that may stay unchanged on edit). */
    public function requiredOnCreate(bool $required = true): static
    {
        $this->requiredOnCreate = $required;
        return $this;
    }

    public function default(mixed $value): static
    {
        $this->default = $value;
        return $this;
    }

    public function help(string $text): static
    {
        $this->help = $text;
        return $this;
    }

    public function placeholder(string $text): static
    {
        $this->placeholder = $text;
        return $this;
    }

    /** Shown but never written. */
    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;
        return $this;
    }

    /** Store an empty value as NULL (default for translatable and date fields) or as ''. */
    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;
        return $this;
    }

    /** The value must not exist in another row of the table. */
    public function unique(bool $unique = true): static
    {
        $this->unique = $unique;
        return $this;
    }

    /** Span 1–4 columns, or the full containing grid. */
    public function columnSpan(int|string $span): static
    {
        if ($span !== 'full' && (!is_int($span) || $span < 1 || $span > 4)) { throw new InvalidArgumentException('Use columnSpan(1–4) or columnSpan("full").'); }
        $this->columnSpan = $span;
        return $this;
    }

    /** One value per content language, stored as JSON (see the README). */
    public function translatable(bool $translatable = true): static
    {
        if ($translatable && !$this->supportsTranslation()) {
            throw new LogicException(static::class . ' cannot be translatable; only text fields can.');
        }

        $this->translatable = $translatable;
        return $this;
    }

    /* ---------------------------------------------------------------
     * Reading the configuration
     * ------------------------------------------------------------- */

    /**
     * Keep this field's values out of the activity log (0.19): a change is shown as
     * "Changed. The value is not recorded." Password fields are never logged at all.
     */
    public function hiddenFromActivity(bool $hidden = true): static
    {
        $this->hiddenFromActivity = $hidden;
        return $this;
    }

    public function isHiddenFromActivity(): bool
    {
        return $this->hiddenFromActivity;
    }

    /* CSV export and import (0.25) */
    protected bool $hiddenFromExport = false;
    protected bool $notImportable = false;
    /** @var list<string> */
    protected array $importAliases = [];

    /** Leave this field out of CSV exports (passwords are never exported). */
    public function hiddenFromExport(bool $hidden = true): static { $this->hiddenFromExport = $hidden; return $this; }
    public function isHiddenFromExport(): bool { return $this->hiddenFromExport; }

    /** Never fill this field from an imported file. */
    public function notImportable(bool $not = true): static { $this->notImportable = $not; return $this; }
    public function isNotImportable(): bool { return $this->notImportable; }

    /** More column headings the import recognises for this field: ['Preis', 'السعر']. */
    public function importAliases(array $aliases): static
    {
        $this->importAliases = array_values(array_filter(array_map(static fn ($a): string => is_scalar($a) ? trim((string) $a) : '', $aliases), static fn (string $a): bool => $a !== ''));
        return $this;
    }
    /** @return list<string> */
    public function getImportAliases(): array { return $this->importAliases; }

    /** The label as written (a lang key or text), to match headings in every panel language. */
    public function rawLabel(): ?string { return $this->label; }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return AdminLang::label($this->label ?? Str::headline($this->name));
    }

    /** @return list<string> */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function isRequired(): bool
    {
        return in_array('required', $this->rules, true);
    }

    public function isRequiredOnCreate(): bool
    {
        return $this->requiredOnCreate;
    }

    /** Rules for this page: 'required' is added on create for requiredOnCreate() fields. */
    public function rulesFor(bool $isEdit): array
    {
        if ($this->requiredOnCreate && !$isEdit && !$this->isRequired()) {
            return array_merge(['required'], $this->rules);
        }

        return $this->rules;
    }

    public function getHelp(): ?string
    {
        return $this->help === null ? null : AdminLang::label($this->help);
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder === null ? null : AdminLang::label($this->placeholder);
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function isNullable(): bool
    {
        return $this->nullable ?? $this->translatable;
    }

    public function getColumnSpan(): int|string
    {
        return $this->columnSpan;
    }

    /** Whether the value is a column of the resource's table (false: kept elsewhere, e.g. a pivot table). */
    public function storesInColumn(): bool
    {
        return true;
    }

    /**
     * Write a value kept outside the record's row (storesInColumn() false). Runs
     * inside the save transaction, after the row was inserted or updated, with
     * the saved row.
     *
     * @param string|array<string, string> $state
     */
    public function saveRelated(\FloCMS\Core\Database $db, object $record, string|array $state): void
    {
    }

    /**
     * Whether the input carries this field at all. A field that is missing (an
     * old draft, a form without it) keeps the stored value instead of being
     * emptied. Plain fields always count as submitted.
     *
     * @param array<string, mixed> $input
     */
    public function submitted(array $input): bool
    {
        return true;
    }

    /** Whether the value is written to the table. */
    public function isWritable(): bool
    {
        return !$this->disabled && $this->visible;
    }

    public function supportsTranslation(): bool
    {
        return false;
    }

    /* ---------------------------------------------------------------
     * Values
     * ------------------------------------------------------------- */

    /**
     * Form state for a record (edit) or for a new record (create: the default).
     *
     * @return string|array<string, string>
     */
    public function stateFromRecord(?object $record, string $defaultLang): string|array
    {
        if ($record === null || !property_exists($record, $this->name)) {
            return $this->defaultState($defaultLang);
        }

        $raw = $record->{$this->name};

        if ($this->translatable) {
            return Translations::store()->decode($raw, $defaultLang);
        }

        return $this->formatForInput($raw);
    }

    /**
     * Form state from submitted input.
     *
     * @param array<string, mixed> $input
     * @param list<string> $languages
     * @return string|array<string, string>
     */
    public function stateFromInput(array $input, array $languages): string|array
    {
        $value = $input[$this->name] ?? null;

        if ($this->translatable) {
            $values = [];
            $submitted = is_array($value) ? $value : [];
            foreach ($languages as $lang) {
                $item = $submitted[$lang] ?? '';
                $values[$lang] = is_scalar($item) ? $this->normalizeInput((string) $item) : '';
            }

            return $values;
        }

        if (is_array($value) || is_object($value)) {
            return '';
        }

        return $this->normalizeInput((string) ($value ?? ''));
    }

    /**
     * Fill derived values before validation (Slug fills itself from its source).
     *
     * @param array<string, string|array<string, string>> $state
     * @return array<string, string|array<string, string>>
     */
    public function prepare(array $state, string $defaultLang): array
    {
        return $state;
    }

    /**
     * The value to write to the column.
     *
     * @param string|array<string, string> $state
     */
    public function dehydrate(string|array $state): mixed
    {
        if ($this->translatable) {
            $encoded = Translations::store()->encode(is_array($state) ? $state : []);
            return $encoded ?? ($this->isNullable() ? null : '');
        }

        $state = is_array($state) ? '' : $state;

        if ($state === '') {
            return $this->isNullable() ? null : '';
        }

        return $state;
    }

    /**
     * Whether an empty submitted value means "keep the stored value" (passwords).
     *
     * @param string|array<string, string> $state
     */
    public function skipsWrite(string|array $state, bool $isEdit): bool
    {
        return false;
    }

    /**
     * A check of this field type beyond the FloCMS rules, e.g. a select value
     * that is not one of the options. Returns [message key, replacements] or null.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public function typeError(string $value): ?array
    {
        return null;
    }

    /** @return string|array<string, string> */
    protected function defaultState(string $defaultLang): string|array
    {
        $default = $this->default instanceof \Closure ? ($this->default)() : $this->default;

        if ($this->translatable) {
            if (is_array($default)) {
                return Translations::store()->decode($default, $defaultLang);
            }

            return $default === null || $default === '' ? [] : [$defaultLang => (string) $default];
        }

        return $this->formatForInput($default);
    }

    /** Stored value → text shown in the input. */
    protected function formatForInput(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    protected function normalizeInput(string $value): string
    {
        // Normalise line endings and drop surrounding whitespace
        return trim(str_replace("\r\n", "\n", $value));
    }

    /* ---------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------- */

    /**
     * The HTML control for one value. $name is the input name ("title" or
     * "title[ar]", or '' for a control that must not be submitted).
     *
     * @param array<string, string> $attributes extra attributes (dir, lang, data-*)
     */
    abstract public function renderControl(string $id, string $name, string $value, array $attributes = []): string;

    /** Whether the control should open the wide translation dialog. */
    public function isLong(): bool
    {
        return false;
    }

    /** @param array<string, string|bool|null> $attributes */
    protected static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $html .= ' ' . $key;
            if ($value !== true) {
                $html .= '="' . Str::e($value) . '"';
            }
        }

        return $html;
    }

    /**
     * Attributes every control shares.
     *
     * @param array<string, string> $extra
     * @return array<string, string|bool|null>
     */
    protected function baseAttributes(string $id, string $name, array $extra): array
    {
        return array_merge([
            'id' => $id,
            'name' => $name === '' ? null : $name,
            'disabled' => $this->disabled,
            'placeholder' => $this->getPlaceholder(),
        ], $extra);
    }
}
