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

    /** How a chart groups it: by day up to 31 days, by week up to 180, by month up to 1,780 (at most 60 months), else by year. */
    public function unit(): string
    {
        return $this->days <= 31 ? 'day' : ($this->days <= 180 ? 'week' : ($this->days <= 1780 ? 'month' : 'year'));
    }

    /**
     * Chart points for counts per day (as WidgetQuery::countByDay() returns them),
     * summed by this period's unit: labels and values in time order, at most 60.
     *
     * For the previous period on the same chart, pass the current one as $like:
     * the points then line up with it one for one (same count, each moved back
     * by the period's length), as ChartWidget's 'previous' needs.
     *
     *     $now = $period->series($perDay);
     *     $before = $period->previous()->series($perDayBefore, like: $period);
     *
     * @param array<string, int|float> $perDay "Y-m-d" => count
     * @return array{labels: list<string>, values: list<int|float>}
     */
    public function series(array $perDay, ?self $like = null): array
    {
        if ($like !== null && $like->days !== $this->days) { throw new \InvalidArgumentException('series(like:) takes a period of the same length.'); }
        $layout = $like ?? $this;
        $bounds = $layout->bounds();
        if ($like !== null) {
            $shift = $layout->start() - $this->start();
            $bounds = array_map(fn (int $time): int => $this->moveBack($time, $shift), $bounds);
        }
        $end = $this->end;
        $unit = $layout->unit();
        $labels = []; $values = [];
        for ($i = 0, $n = count($bounds) - 1; $i < $n; $i++) {
            $sum = 0;
            for ($day = $bounds[$i]; $day < $bounds[$i + 1] && $day <= $end; $day = (int) strtotime('+1 day', $day)) { $sum += $perDay[date('Y-m-d', $day)] ?? 0; }
            $labels[] = match ($unit) { 'month' => Dates::monthShort($bounds[$i]), 'year' => date('Y', $bounds[$i]), default => Dates::short($bounds[$i], $end) };
            $values[] = $sum;
        }
        return ['labels' => $labels, 'values' => $values];
    }

    /** Bucket starts in time order, then the moment after the last one; the newest 60 buckets. */
    private function bounds(): array
    {
        $bounds = []; $cursor = $this->start(); $unit = $this->unit();
        while ($cursor <= $this->end) {
            $bounds[] = $cursor;
            $cursor = (int) match ($unit) {
                'day' => strtotime('+1 day', $cursor),
                'week' => strtotime('+7 days', $cursor),
                'month' => strtotime('first day of next month 00:00', $cursor),
                default => strtotime('first day of january next year 00:00', $cursor),
            };
        }
        $bounds[] = $cursor;
        return count($bounds) > 61 ? array_slice($bounds, -61) : $bounds;
    }

    /** A moment moved back by whole days (keeps midnight across daylight-saving changes). */
    private function moveBack(int $time, int $seconds): int
    {
        $days = (int) round($seconds / 86400);
        return (int) strtotime(date('Y-m-d H:i:s', $time) . ' -' . $days . ' days');
    }

    private function start(): int { return (int) strtotime(date('Y-m-d', $this->end) . ' 00:00:00 -' . ($this->days - 1) . ' days'); }
}
