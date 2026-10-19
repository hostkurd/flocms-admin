<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

/**
 * Writes CSV that Excel opens correctly (0.25): UTF-8 with a byte-order mark
 * (Arabic and Kurdish stay readable on Windows), comma-separated, CRLF line
 * ends, every text cell quoted.
 *
 * A text cell that starts with = + - @, a tab or a carriage return gets a
 * leading apostrophe, so a spreadsheet shows it as text instead of running it
 * as a formula ("CSV injection"). Cells marked as numbers are left as they are.
 */
final class CsvWriter
{
    /** @var resource */
    private $handle;
    private bool $started = false;

    /** @param resource|null $handle where to write (default: php://output) */
    public function __construct($handle = null)
    {
        $handle ??= fopen('php://output', 'wb');
        if (!is_resource($handle)) { throw new \RuntimeException('Cannot open the CSV output.'); }
        $this->handle = $handle;
    }

    /**
     * One line. A cell is a string, or ['number' => '12.5'] for a value written
     * without quotes or the formula guard.
     *
     * @param list<string|int|float|null|array{number: string}> $cells
     */
    public function row(array $cells): void
    {
        if (!$this->started) {
            fwrite($this->handle, "\xEF\xBB\xBF");
            $this->started = true;
        }
        $line = [];
        foreach ($cells as $cell) {
            if (is_array($cell)) {
                $number = (string) ($cell['number'] ?? '');
                $line[] = preg_match('/^-?\d+(\.\d+)?$/D', $number) === 1 ? $number : self::quote(self::guard($number));
                continue;
            }
            $line[] = self::quote(self::guard($cell === null ? '' : (string) $cell));
        }
        fwrite($this->handle, implode(',', $line) . "\r\n");
    }

    /** The guard on its own (also used for the rows-to-fix download). */
    public static function guard(string $value): string
    {
        // Invalid UTF-8 would make the whole file unreadable in some programs.
        if (!mb_check_encoding($value, 'UTF-8')) { $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8'); }
        // A plain negative number (-5, -12.5) cannot run as a formula: it stays as it is.
        if (preg_match('/^-\d+([.,]\d+)?$/D', $value) === 1) { return $value; }
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    public function close(): void
    {
        if (!$this->started) { fwrite($this->handle, "\xEF\xBB\xBF"); $this->started = true; }
        fflush($this->handle);
    }
}
