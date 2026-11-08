<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Admin, Support\AdminLang, Support\Dates};

/**
 * The dashboard's period (0.42): the last N days up to now, chosen with the
 * period buttons (config 'dashboard_periods', default 7, 30, 90 and 365 days;
 * 'dashboard_period' the one that opens, default 30). Widgets read it from
 * WidgetContext::period(); previous() is the same length just before it.
 *
 *     $now = $query->where('created_at', '>=', $period->from())->count();
 *     $before = $query->where('created_at', '>=', $period->previous()->from())
 *                     ->where('created_at', '<', $period->from())->count();
 */
final class Period
{
    private function __construct(private readonly int $days, private readonly int $end) {}

    /** The period that ends now (or at $end), $days long. */
    public static function last(int $days, ?int $end = null): self
    {
        if ($days < 1 || $days > 3660) { throw new \InvalidArgumentException('A period is 1–3660 days.'); }
        return new self($days, $end ?? time());
    }

    /** The period from the address (?period=7) when it is one of the configured ones, else the default. */
    public static function fromRequest(mixed $value = null): self
    {
        $config = Admin::config();
        $allowed = $config->dashboardPeriods();
        $value = is_string($value) && ctype_digit($value) ? (int) $value : (is_int($value) ? $value : null);
        return self::last(in_array($value, $allowed, true) ? $value : $config->dashboardPeriod());
    }

    public function days(): int { return $this->days; }

    /** Start, "Y-m-d H:i:s": midnight $days − 1 days ago, so "7 days" is today and the six days before. */
    public function from(): string { return date('Y-m-d H:i:s', $this->start()); }

    /** End (now), "Y-m-d H:i:s". */
    public function to(): string { return date('Y-m-d H:i:s', $this->end); }

    /** The same number of days just before this period. */
    public function previous(): self { return new self($this->days, $this->start() - 1); }

    /** "Last 30 days", "Last 12 months", "Today". */
    public function label(): string
    {
        if ($this->days === 1) { return AdminLang::get('dates.today'); }
        if ($this->days % 365 === 0) { return AdminLang::get('widgets.period_months', ['count' => intdiv($this->days, 365) * 12]); }
        return AdminLang::get('widgets.period_days', ['count' => $this->days]);
    }

    /** The button text: "7 days", "12 months". */
    public function short(): string
    {
        if ($this->days % 365 === 0) { return AdminLang::get('widgets.period_short_months', ['count' => intdiv($this->days, 365) * 12]); }
        return AdminLang::get('widgets.period_short_days', ['count' => $this->days]);
    }

    /** How a chart groups it: by day up to 31 days, by week up to 180, else by month. */
    public function unit(): string { return $this->days <= 31 ? 'day' : ($this->days <= 180 ? 'week' : 'month'); }

    /**
     * Chart points for counts per day (as WidgetQuery::countByDay() returns them),
     * summed by this period's unit: labels and values in time order.
     *
     * @param array<string, int|float> $perDay "Y-m-d" => count
     * @return array{labels: list<string>, values: list<int|float>}
     */
    public function series(array $perDay): array
    {
        $labels = []; $values = []; $start = $this->start(); $unit = $this->unit();
        $cursor = $start;
        while ($cursor <= $this->end && count($values) < 60) {
            $next = match ($unit) {
                'day' => strtotime('+1 day', $cursor),
                'week' => strtotime('+7 days', $cursor),
                default => strtotime('first day of next month 00:00', $cursor),
            };
            $sum = 0;
            for ($day = $cursor; $day < $next && $day <= $this->end; $day = strtotime('+1 day', $day)) { $sum += $perDay[date('Y-m-d', $day)] ?? 0; }
            $labels[] = $unit === 'month' ? Dates::monthShort($cursor) : Dates::short($cursor, $this->end);
            $values[] = $sum;
            $cursor = $next;
        }
        return ['labels' => $labels, 'values' => $values];
    }

    private function start(): int { return (int) strtotime(date('Y-m-d', $this->end) . ' 00:00:00 -' . ($this->days - 1) . ' days'); }
}
