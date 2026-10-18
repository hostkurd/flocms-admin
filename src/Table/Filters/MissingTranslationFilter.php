<?php
declare(strict_types=1);
namespace FloCMS\Admin\Table\Filters;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\Database;

/**
 * "Missing in: Arabic" (0.23): records where at least one translatable field
 * is empty in that language. A TranslationsColumn adds it to the list with the
 * same fields; it can also be declared on its own:
 *
 *     MissingTranslationFilter::make('missing_in')->fields(['title', 'summary'])
 *
 * One condition per field on the JSON columns (plain legacy text counts as the
 * default language), so the list keeps a single query.
 */
final class MissingTranslationFilter extends SelectFilter
{
    /** @var list<string> */
    private array $fields = [];
    /** @var list<string> */
    private array $rich = [];

    protected function setUp(): void
    {
        $options = [];
        foreach (Languages::all() as $lang) {
            if ($lang !== Languages::default()) { $options[$lang] = Languages::name($lang); }
        }
        $this->options($options);
    }

    public function getLabel(): string
    {
        $label = parent::getLabel();
        return $label === \FloCMS\Admin\Support\Str::headline($this->getName()) ? AdminLang::get('translations.missing_in') : $label;
    }

    /**
     * @param list<string> $fields translatable columns
     * @param list<string> $rich those holding rich text ("<p></p>" counts as empty)
     */
    public function fields(array $fields, array $rich = []): static
    {
        foreach ($fields as $field) {
            if (!is_string($field) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field) !== 1) { throw new \InvalidArgumentException('MissingTranslationFilter::fields() expects column names.'); }
        }
        $this->fields = array_values(array_unique($fields));
        $this->rich = array_values(array_intersect($rich, $this->fields));
        return $this;
    }

    /** @return list<string> */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function apply(Database $query, string|array $value): Database
    {
        if ($value === '' || !is_string($value) || $this->fields === []) { return $query; }
        $store = Translations::store();
        $conditions = [];
        foreach ($this->fields as $field) {
            $expression = $store->sortExpression($query, $field, $value, Languages::default());
            $conditions[] = "COALESCE(TRIM({$expression}), '') = ''";
            if (in_array($field, $this->rich, true)) {
                $conditions[] = "TRIM({$expression}) IN ('<p></p>', '<p><br></p>', '<p><br/></p>')";
            }
        }
        return $query->whereRaw('(' . implode(' OR ', $conditions) . ')', []);
    }
}
