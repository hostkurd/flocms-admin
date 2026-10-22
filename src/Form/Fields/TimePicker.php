<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;
use InvalidArgumentException;

/**
 * A time of day (0.37): the browser's own <input type="time">, stored as
 * HH:MM:SS (it fits a TIME column; MySQL, MariaDB and SQLite compare it as
 * text). Empty is stored as NULL.
 *
 *     TimePicker::make('opens_at')->minTime('08:00')->maxTime('20:00')->step(15)
 *
 * The browser shows 12- or 24-hour time from the visitor's own settings; the
 * panel shows times as config 'time_format' says (24-hour by default). Min, max
 * and step are checked again on the server.
 */
class TimePicker extends Field
{
    private bool $seconds = false;
    private ?string $minTime = null;
    private ?string $maxTime = null;
    /** Minutes, or seconds with seconds(). */
    private ?int $step = null;

    protected function setUp(): void
    {
        $this->nullable = true;
    }

    /** HH:MM:SS instead of HH:MM. */
    public function seconds(bool $seconds = true): static
    {
        $this->seconds = $seconds;
        return $this;
    }

    /** The earliest time ("08:00"). */
    public function minTime(string $time): static
    {
        $this->minTime = self::requireTime($time, 'minTime');
        return $this;
    }

    /** The latest time ("20:00"); a range across midnight is not supported. */
    public function maxTime(string $time): static
    {
        $this->maxTime = self::requireTime($time, 'maxTime');
        return $this;
    }

    /** Allowed times every this many minutes (1–60) from minTime() or midnight; seconds (1–3600) with seconds(). */
    public function step(int $step): static
    {
        if ($step < 1 || $step > 3600) { throw new InvalidArgumentException('TimePicker step must be 1–60 minutes (1–3600 seconds with seconds()).'); }
        $this->step = $step;
        return $this;
    }

    public function hasSeconds(): bool { return $this->seconds; }
    public function getMinTime(): ?string { return $this->minTime; }
    public function getMaxTime(): ?string { return $this->maxTime; }

    /** The step in seconds, or null for the input's default (one minute, or one second with seconds()). */
    public function stepSeconds(): ?int
    {
        if ($this->step === null) { return $this->seconds ? 1 : null; }
        return $this->seconds ? $this->step : $this->step * 60;
    }

    private static function requireTime(string $time, string $method): string
    {
        $parsed = self::parse($time);
        if ($parsed === null) { throw new InvalidArgumentException('TimePicker ' . $method . '() expects a time like "08:00" or "08:00:30".'); }
        return $parsed;
    }

    /**
     * "9:30", "09:30", "09:30:15", "9:30 PM", "21.30" or a stored "09:30:00" as
     * HH:MM:SS, or null when it is not a time.
     */
    public static function parse(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2})[:.](\d{2})(?:[:.](\d{2})(?:\.\d+)?)?\s*([AaPp]\.?\s*[Mm]\.?)?$/D', $value, $m) !== 1) { return null; }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $second = (int) ($m[3] ?? 0);
        if (($m[4] ?? '') !== '') {
            if ($hour < 1 || $hour > 12) { return null; }
            $pm = strtolower($m[4][0]) === 'p';
            $hour = $hour % 12 + ($pm ? 12 : 0);
        }
        if ($hour > 23 || $minute > 59 || $second > 59) { return null; }
        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    private static function secondsOf(string $time): int
    {
        [$h, $i, $s] = array_map('intval', explode(':', $time));
        return $h * 3600 + $i * 60 + $s;
    }

    /** "09:30" or "09:30:15" as the input shows it. */
    private function short(string $time): string
    {
        return $this->seconds ? $time : substr($time, 0, 5);
    }

    protected function formatForInput(mixed $value): string
    {
        $value = parent::formatForInput($value);
        // A DATETIME value read by mistake still shows its time
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T](.+)$/D', $value, $m) === 1) { $value = $m[1]; }
        $parsed = self::parse($value);
        return $parsed === null ? '' : $this->short($parsed);
    }

    protected function normalizeInput(string $value): string
    {
        $value = parent::normalizeInput($value);
        $parsed = self::parse($value);
        return $parsed === null ? $value : $this->short($parsed);
    }

    public function typeError(string $value): ?array
    {
        if ($value === '') { return null; }
        $time = self::parse($value);
        if ($time === null) { return ['validation.time', []]; }
        if ($this->minTime !== null && $time < $this->minTime) { return ['validation.time_min', ['min' => $this->short($this->minTime)]]; }
        if ($this->maxTime !== null && $time > $this->maxTime) { return ['validation.time_max', ['max' => $this->short($this->maxTime)]]; }
        if (!$this->seconds && substr($time, 6) !== '00') { return ['validation.time', []]; }
        if ($this->step !== null) {
            $step = (int) $this->stepSeconds();
            $base = $this->minTime !== null ? self::secondsOf($this->minTime) : 0;
            if ((self::secondsOf($time) - $base) % $step !== 0) {
                return $this->seconds ? ['validation.time_step_seconds', ['step' => (string) $this->step]] : ['validation.time_step', ['step' => (string) $this->step]];
            }
        }
        return null;
    }

    public function dehydrate(string|array $state): mixed
    {
        if (!is_string($state) || $state === '') {
            return parent::dehydrate($state);
        }
        return self::parse($state) ?? $state;
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $attrs = $this->baseAttributes($id, $name, $attributes) + [
            'type' => 'time', 'value' => $value, 'class' => 'fa-input fa-time',
            'min' => $this->minTime !== null ? $this->short($this->minTime) : null,
            'max' => $this->maxTime !== null ? $this->short($this->maxTime) : null,
            'step' => $this->stepSeconds() !== null ? (string) $this->stepSeconds() : null,
        ];
        unset($attrs['placeholder']);
        return '<input' . self::attributes($attrs) . '>';
    }
}
