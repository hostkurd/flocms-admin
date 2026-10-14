<?php
declare(strict_types=1);
namespace FloCMS\Admin\Table\Filters;
use FloCMS\Core\Database;

final class DateRangeFilter extends Filter
{
    public function value(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['from', 'until']) !== []) { throw new \InvalidArgumentException('Invalid date range.'); }
        $result = ['from' => '', 'until' => ''];
        foreach ($result as $key => $_) {
            $date = $value[$key] ?? '';
            if (!is_string($date) || ($date !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))))) { throw new \InvalidArgumentException('Invalid date.'); }
            $result[$key] = $date;
        }
        if ($result['from'] !== '' && $result['until'] !== '' && $result['from'] > $result['until']) { throw new \InvalidArgumentException('Reversed date range.'); }
        return $result;
    }
    public function apply(Database $query, string|array $value): Database
    {
        foreach (['from' => '>=', 'until' => '<='] as $key => $operator) {
            if (($value[$key] ?? '') !== '') { $query = $query->whereRaw('DATE(`' . $this->column . '`) ' . $operator . ' ?', [$value[$key]]); }
        }
        return $query;
    }
}
