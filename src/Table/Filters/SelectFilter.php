<?php
declare(strict_types=1);
namespace FloCMS\Admin\Table\Filters;
use FloCMS\Core\Database;

class SelectFilter extends Filter
{
    protected array $options = [];
    public function options(array $options): static
    {
        if (count($options) > 100) { throw new \InvalidArgumentException('Too many filter options.'); }
        foreach ($options as $key => $label) {
            if ((string) $key === '' || strlen((string) $key) > 190 || !is_string($label)) { throw new \InvalidArgumentException('Invalid filter option.'); }
        }
        $this->options = $options; return $this;
    }
    public function getOptions(): array { return $this->options; }
    public function value(mixed $value): string
    {
        if (!is_string($value) || ($value !== '' && !array_key_exists($value, $this->options))) { throw new \InvalidArgumentException('Invalid filter option.'); }
        return $value;
    }
    public function apply(Database $query, string|array $value): Database { return $value === '' ? $query : $query->where($this->column, '=', $value); }
}
