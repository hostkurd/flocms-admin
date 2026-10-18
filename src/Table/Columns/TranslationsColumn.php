<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\RichText;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;

/**
 * How far each record is translated (0.23): one mark per language,
 * EN 4/4 · AR 3/4 · KU 0/4, full, partial or empty, so editors find what
 * still needs translating.
 *
 *     TranslationsColumn::make('translations')
 *
 * It counts the resource's translatable form fields (or ->fields([...])), read
 * from the same JSON columns as the form; nothing is queried per row. The list
 * also gets a "Missing in: <language>" filter unless ->withoutFilter().
 */
final class TranslationsColumn extends Column
{
    /** @var list<string>|null set by ->fields() */
    private ?array $explicit = null;
    /** @var list<string> the form's translatable fields */
    private array $detected = [];
    /** @var list<string> rich text fields: empty markup ("<p></p>") counts as missing */
    private array $rich = [];
    private bool $filter = true;

    /** @param list<string> $fields the translatable columns to count (default: every translatable form field) */
    public function fields(array $fields): static
    {
        foreach ($fields as $field) {
            if (!is_string($field) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field) !== 1) { throw new \InvalidArgumentException('TranslationsColumn::fields() expects column names.'); }
        }
        $this->explicit = array_values(array_unique($fields));
        return $this;
    }

    /** Leave out the "Missing in" filter this column adds to the list. */
    public function withoutFilter(): static
    {
        $this->filter = false;
        return $this;
    }

    public function addsFilter(): bool
    {
        return $this->filter;
    }

    /**
     * @internal Table::prepare() passes the form's translatable and rich text fields.
     * @param list<string> $translatable
     * @param list<string> $rich
     */
    public function useFormFields(array $translatable, array $rich): void
    {
        $this->detected = array_values($translatable);
        $this->rich = array_values($rich);
    }

    /** @return list<string> */
    public function countedFields(): array
    {
        return $this->explicit ?? $this->detected;
    }

    /** @return list<string> */
    public function richFields(): array
    {
        return array_values(array_intersect($this->rich, $this->countedFields()));
    }

    public function sortable(bool $sortable = true): static
    {
        if ($sortable) { throw new \LogicException('The translations column cannot be sorted.'); }
        return $this;
    }

    public function searchable(bool $searchable = true): static
    {
        if ($searchable) { throw new \LogicException('The translations column cannot be searched.'); }
        return $this;
    }

    public function translatable(bool $translatable = true): static
    {
        return $this;
    }

    public function detectTranslatable(bool $translatable): void
    {
    }

    public function isDatabaseColumn(): bool
    {
        return false;
    }

    public function getLabel(): string
    {
        return $this->label !== null ? parent::getLabel() : AdminLang::get('translations.column');
    }

    public function value(object $record): mixed
    {
        return null;
    }

    /** @return array<string, int> language => filled fields */
    public function counts(object $record): array
    {
        $default = Languages::default();
        $counts = array_fill_keys(Languages::all(), 0);
        foreach ($this->countedFields() as $field) {
            $values = Translations::store()->decode(property_exists($record, $field) ? $record->{$field} : null, $default);
            $rich = in_array($field, $this->rich, true);
            foreach (array_keys($counts) as $lang) {
                $value = (string) ($values[$lang] ?? '');
                if ($rich ? !RichText::isEmpty($value) : trim($value) !== '') { $counts[$lang]++; }
            }
        }
        return $counts;
    }

    public function render(object $record): string
    {
        $total = count($this->countedFields());
        if ($total === 0) { return '<span class="fa-muted">&ndash;</span>'; }
        $html = '';
        foreach ($this->counts($record) as $lang => $filled) {
            $state = $filled >= $total ? 'is-full' : ($filled === 0 ? 'is-none' : 'is-partial');
            $text = AdminLang::get('translations.column_state', ['language' => Languages::name($lang), 'filled' => $filled, 'total' => $total]);
            $html .= '<span class="fa-tr-mark ' . $state . '" title="' . Str::e($text) . '"><span aria-hidden="true">' . Str::e(strtoupper($lang))
                . ' <span class="fa-ltr">' . $filled . '/' . $total . '</span></span><span class="fa-sr">' . Str::e($text) . '</span></span>';
        }
        return '<span class="fa-tr-marks">' . $html . '</span>';
    }

    public function cellClass(): string
    {
        return 'fa-cell-translations';
    }
}
