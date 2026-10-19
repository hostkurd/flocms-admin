<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

/**
 * Reads an uploaded CSV file (0.25).
 *
 * The upload is first turned into a UTF-8 copy (normalize()): a byte-order mark
 * is dropped, UTF-16 and single-byte files (Windows-1256 Arabic, Windows-1252)
 * are converted. Reading then only deals with UTF-8, in records (a quoted cell
 * may hold line breaks), batch by batch from a byte position, so a large file
 * is never read whole.
 */
final class CsvReader
{
    public const ENCODINGS = ['utf-8', 'windows-1256', 'windows-1252', 'utf-16'];
    public const SEPARATORS = [',', ';', "\t", '|'];

    /** @var resource */
    private $handle;

    public function __construct(private readonly string $path, private readonly string $separator = ',')
    {
        if (!in_array($separator, self::SEPARATORS, true)) { throw new \InvalidArgumentException('Unknown CSV separator.'); }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) { throw new \RuntimeException('Cannot read the import file.'); }
        $this->handle = $handle;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) { fclose($this->handle); }
    }

    /**
     * The encoding of a file, from its first bytes: a byte-order mark, valid
     * UTF-8, else a single-byte code page ($fallback, chosen by the site's languages).
     */
    public static function detectEncoding(string $path, string $fallback = 'windows-1252'): string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 65536);
        if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")) { return 'utf-16'; }
        if (str_starts_with($head, "\xEF\xBB\xBF")) { return 'utf-8'; }
        // A multi-byte character cut at the end of the sample is not an error.
        $sample = strlen($head) === 65536 ? substr($head, 0, (int) strrpos($head, "\n") ?: 65536) : $head;
        return mb_check_encoding($sample, 'UTF-8') ? 'utf-8' : $fallback;
    }

    /**
     * Writes a UTF-8 copy of $source to $target, without byte-order mark.
     * Returns false when the file is not text in that encoding.
     */
    public static function normalize(string $source, string $target, string $encoding): bool
    {
        $bytes = @file_get_contents($source);
        if (!is_string($bytes)) { return false; }
        if (str_contains($bytes, "\0") && $encoding !== 'utf-16') { return false; }
        switch ($encoding) {
            case 'utf-8':
                if (str_starts_with($bytes, "\xEF\xBB\xBF")) { $bytes = substr($bytes, 3); }
                if (!mb_check_encoding($bytes, 'UTF-8')) { return false; }
                break;
            case 'utf-16':
                $from = str_starts_with($bytes, "\xFE\xFF") ? 'UTF-16BE' : 'UTF-16LE';
                if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) { $bytes = substr($bytes, 2); }
                $bytes = self::convert($bytes, $from);
                break;
            case 'windows-1256':
            case 'windows-1252':
                $bytes = self::convert($bytes, $encoding);
                break;
            default:
                return false;
        }
        if ($bytes === null || !mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) { return false; }
        return @file_put_contents($target, $bytes) !== false;
    }

    private static function convert(string $bytes, string $from): ?string
    {
        if (function_exists('iconv')) {
            $converted = @iconv($from, 'UTF-8//IGNORE', $bytes);
            if (is_string($converted)) { return $converted; }
        }
        $names = ['windows-1256' => 'Windows-1256', 'windows-1252' => 'Windows-1252'];
        try {
            $converted = mb_convert_encoding($bytes, 'UTF-8', $names[$from] ?? $from);
            return is_string($converted) ? $converted : null;
        } catch (\ValueError) {
            return null;
        }
    }

    /**
     * The separator used by most of the first lines: the one that splits them
     * into the same number of cells, most often.
     */
    public static function detectSeparator(string $path): string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 32768);
        $lines = array_slice(array_values(array_filter(preg_split('/\r\n|\n|\r/', $head) ?: [], static fn (string $l): bool => trim($l) !== '')), 0, 10);
        if (count($lines) > 1 && strlen($head) === 32768) { array_pop($lines); }
        $best = ',';
        $bestScore = -1;
        foreach (self::SEPARATORS as $separator) {
            $counts = array_map(static fn (string $line): int => count(str_getcsv($line, $separator, '"', '')), $lines);
            if ($counts === [] || max($counts) < 2) { continue; }
            $common = array_count_values($counts);
            arsort($common);
            $score = (int) reset($common) * 100 + (int) key($common);
            if ($score > $bestScore) { $bestScore = $score; $best = $separator; }
        }
        return $best;
    }

    /** The first record: the column headings, trimmed. @return list<string> */
    public function header(): array
    {
        rewind($this->handle);
        while (($cells = $this->next()) !== null) {
            if (self::blank($cells)) { continue; }
            return array_map(static fn (string $cell): string => trim(preg_replace('/^\x{FEFF}/u', '', $cell) ?? $cell), $cells);
        }
        return [];
    }

    /** Byte position just after the header, where the data starts. */
    public function dataStart(): int
    {
        $this->header();
        return (int) ftell($this->handle);
    }

    /**
     * Up to $limit records from byte $from, numbered from $number (the
     * spreadsheet row: the header is row 1). Blank lines are skipped and do not
     * take a number.
     *
     * @return array{rows: array<int, list<string>>, next: int, end: bool}
     */
    public function rows(int $from, int $number, int $limit): array
    {
        fseek($this->handle, $from);
        $rows = [];
        while (count($rows) < $limit && ($cells = $this->next()) !== null) {
            if (self::blank($cells)) { continue; }
            $rows[$number++] = array_map([self::class, 'clean'], $cells);
        }
        $next = (int) ftell($this->handle);
        $end = $this->next() === null;
        return ['rows' => $rows, 'next' => $next, 'end' => $end];
    }

    /** Number of data records (blank lines left out). */
    public function count(): int
    {
        fseek($this->handle, $this->dataStart());
        $count = 0;
        while (($cells = $this->next()) !== null) {
            if (!self::blank($cells)) { $count++; }
        }
        return $count;
    }

    /** @return list<string>|null */
    private function next(): ?array
    {
        $cells = fgetcsv($this->handle, 0, $this->separator, '"', '');
        if ($cells === false) { return null; }
        return array_map(static fn ($cell): string => (string) $cell, $cells);
    }

    /** @param list<string> $cells */
    private static function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') { return false; }
        }
        return true;
    }

    /** A cell as typed: trimmed, and the apostrophe of the formula guard taken off again. */
    public static function clean(string $cell): string
    {
        $cell = trim($cell);
        return strlen($cell) > 1 && $cell[0] === "'" && strpbrk($cell[1], "=+-@\t\r") !== false ? substr($cell, 1) : $cell;
    }

    public function path(): string { return $this->path; }
}
