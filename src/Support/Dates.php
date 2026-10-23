<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/**
 * Dates as the trash and the activity log show them, in the admin's language:
 * "2 minutes ago", "Yesterday", "15 Oct" or "Thursday, 15 Oct 2025".
 * Uses PHP's intl extension for month and day names when it is installed.
 *
 * @internal
 */
final class Dates
{
    /** A moment relative to now: "just now", "5 minutes ago", "3 hours ago", "yesterday", "15 Oct". */
    public static function relative(int $time, ?int $now = null): string
    {
        $now ??= time();
        $seconds = max(0, $now - $time);
        if ($seconds < 60) { return AdminLang::get('dates.just_now'); }
        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            return AdminLang::get($minutes === 1 ? 'dates.minute_ago' : 'dates.minutes_ago', ['count' => $minutes]);
        }
        if (date('Y-m-d', $time) === date('Y-m-d', $now)) {
            $hours = intdiv($seconds, 3600);
            return AdminLang::get($hours === 1 ? 'dates.hour_ago' : 'dates.hours_ago', ['count' => $hours]);
        }
        if (date('Y-m-d', $time) === date('Y-m-d', strtotime('-1 day', $now))) { return AdminLang::get('dates.yesterday'); }
        return self::short($time, $now);
    }

    /** A day heading: "Today", "Yesterday" or "Thursday, 15 Oct". */
    public static function day(int $time, ?int $now = null): string
    {
        $now ??= time();
        if (date('Y-m-d', $time) === date('Y-m-d', $now)) { return AdminLang::get('dates.today'); }
        if (date('Y-m-d', $time) === date('Y-m-d', strtotime('-1 day', $now))) { return AdminLang::get('dates.yesterday_title'); }
        return self::format($time, date('Y', $time) === date('Y', $now) ? 'EEEE, d MMM' : 'EEEE, d MMM y', date('Y', $time) === date('Y', $now) ? 'l, j M' : 'l, j M Y');
    }

    /** "15 Oct", or "15 Oct 2025" in another year. */
    public static function short(int $time, ?int $now = null): string
    {
        $now ??= time();
        $sameYear = date('Y', $time) === date('Y', $now);
        return self::format($time, $sameYear ? 'd MMM' : 'd MMM y', $sameYear ? 'j M' : 'j M Y');
    }

    /** "17 Oct 2026" (0.27, the view page's dates). */
    public static function date(int $time): string
    {
        return self::format($time, 'd MMM y', 'j M Y');
    }

    /**
     * A time of day as config 'time_format' says ("14:30", or "2:30 PM"), with
     * seconds when asked; '' when the value is not a time (0.37).
     */
    public static function timeOfDay(string $value, bool $seconds = false, ?string $format = null): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T](.+)$/D', trim($value), $m) === 1) { $value = $m[1]; }
        $parsed = \FloCMS\Admin\Form\Fields\TimePicker::parse($value);
        if ($parsed === null) { return ''; }
        $format ??= \FloCMS\Admin\Admin::config()->timeFormat();
        if ($seconds && !str_contains($format, 's')) { $format = str_replace('i', 'i:s', $format); }
        [$h, $i, $s] = array_map('intval', explode(':', $parsed));
        return (new \DateTimeImmutable('2000-01-01'))->setTime($h, $i, $s)->format($format);
    }

    /** "October 2026" (0.38, month groups). */
    public static function month(int $time): string
    {
        return self::format($time, 'LLLL y', 'F Y');
    }

    /** "2026" (0.38, year groups). */
    public static function year(int $time): string
    {
        return self::format($time, 'y', 'Y');
    }

    /** "Thursday, 15 Oct 2026" (0.38, day groups). */
    public static function weekday(int $time): string
    {
        return self::format($time, 'EEEE, d MMM y', 'l, j M Y');
    }

    /** "17 Oct 2026, 14:32". */
    public static function full(int $time): string
    {
        return self::format($time, 'd MMM y', 'j M Y') . ', ' . date('H:i', $time);
    }

    public static function time(int $time): string
    {
        return date('H:i', $time);
    }

    private static function format(int $time, string $pattern, string $fallback): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            try {
                $formatter = new \IntlDateFormatter(AdminLang::language() . '@numbers=latn', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, date_default_timezone_get(), \IntlDateFormatter::GREGORIAN, $pattern);
                $text = $formatter->format($time);
                if (is_string($text) && $text !== '') { return $text; }
            } catch (\Throwable) {
                // Fall back to English names
            }
        }
        return date($fallback, $time);
    }
}
