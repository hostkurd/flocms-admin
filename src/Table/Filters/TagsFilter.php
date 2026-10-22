<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Filters;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Core\Database;

/**
 * Records whose TagsInput column holds a tag (0.37): a text box, with the
 * suggestions offered while typing. Whole tags only, regardless of capitals:
 * "Sea View" finds "sea view", "sea" does not.
 *
 *     TagsFilter::make('keywords')->suggestions(['villa', 'sea view'])
 */
final class TagsFilter extends Filter
{
    /** @var list<string> */
    private array $suggestions = [];

    /** @param list<string> $suggestions */
    public function suggestions(array $suggestions): static
    {
        $this->suggestions = array_slice(array_values(array_filter($suggestions, static fn ($s): bool => is_string($s) && $s !== '')), 0, 500);
        return $this;
    }

    public function value(mixed $value): string
    {
        if (!is_string($value)) { throw new \InvalidArgumentException('Invalid tag.'); }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (mb_strlen($value) > 100) { throw new \InvalidArgumentException('Invalid tag.'); }
        return $value;
    }

    public function apply(Database $query, string|array $value): Database
    {
        return is_string($value) && $value !== '' ? ListMatch::any($query, $this->column, [$value]) : $query;
    }

    public function renderFilterInput(string $id, string|array $value, string $invalid): ?string
    {
        $list = $this->suggestions !== [] ? ' list="' . Str::e($id . '-list') . '"' : '';
        $html = '<input class="fa-input" type="text" id="' . Str::e($id) . '" name="filter[' . Str::e($this->getName()) . ']" data-admin-filter="' . Str::e($this->getName()) . '"'
            . ' value="' . Str::e(is_string($value) ? $value : '') . '" placeholder="' . Str::e(AdminLang::get('filters.any_tag')) . '" autocomplete="off"' . $list . $invalid . '>';
        if ($this->suggestions !== []) {
            $html .= '<datalist id="' . Str::e($id . '-list') . '">';
            foreach ($this->suggestions as $suggestion) { $html .= '<option value="' . Str::e($suggestion) . '"></option>'; }
            $html .= '</datalist>';
        }
        return $html;
    }

    public function describeFilterValue(string|array $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
