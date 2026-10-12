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
    protected int $columnSpan = 1;

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

    /** 1 = half the form width on large screens, 2 = the full width. */
    public function columnSpan(int $span): static
    {
        $this->columnSpan = $span >= 2 ? 2 : 1;
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

    public function getColumnSpan(): int
    {
        return $this->columnSpan;
    }

    /** Whether the value is written to the table. */
    public function isWritable(): bool
    {
        return !$this->disabled;
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
