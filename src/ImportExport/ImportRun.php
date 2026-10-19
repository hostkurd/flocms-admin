<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

/**
 * One import (0.25): a row of the imports table.
 *
 * Status: uploaded (columns not matched yet) → checking → checked → importing →
 * finished; cancelled or failed on the way; undoing → undone after an Undo.
 * A run that is checking or importing but has not moved for two minutes shows
 * as paused (the editor closed the page) and can be continued.
 */
final class ImportRun
{
    public const MODES = ['create', 'both', 'update'];
    public const PAUSE_SECONDS = 120;

    /**
     * @param list<string> $headings
     * @param list<list<string>> $samples
     * @param array<int, string> $mapping column index => target key
     * @param array<string, int> $counts
     */
    public function __construct(
        public readonly int $id,
        public readonly string $resource,
        public readonly ?int $userId,
        public readonly string $userName,
        public readonly string $fileName,
        public readonly string $filePath,
        public readonly int $fileSize,
        public readonly string $encoding,
        public readonly string $separator,
        public readonly array $headings,
        public readonly array $samples,
        public readonly int $rowsTotal,
        public readonly int $dataStart,
        public readonly array $mapping,
        public readonly string $mode,
        public readonly ?string $keyColumn,
        public readonly string $dateOrder,
        public readonly string $status,
        public readonly int $position,
        public readonly int $nextByte,
        public readonly array $counts,
        public readonly ?string $message,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $undoneAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $json = static function (mixed $value): array {
            $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
            return is_array($decoded) ? $decoded : [];
        };
        $mapping = [];
        foreach ($json($row['mapping'] ?? null) as $index => $key) {
            if (is_string($key) && is_numeric($index)) { $mapping[(int) $index] = $key; }
        }
        return new self(
            (int) $row['id'], (string) $row['resource'], isset($row['user_id']) ? (int) $row['user_id'] : null, (string) ($row['user_name'] ?? ''),
            (string) $row['file_name'], (string) $row['file_path'], (int) ($row['file_size'] ?? 0), (string) $row['encoding'], (string) $row['csv_separator'],
            array_values(array_map('strval', $json($row['headings'] ?? null))), array_values($json($row['samples'] ?? null)),
            (int) ($row['rows_total'] ?? 0), (int) ($row['data_start'] ?? 0), $mapping,
            in_array($row['mode'] ?? '', self::MODES, true) ? (string) $row['mode'] : 'create', isset($row['key_column']) && $row['key_column'] !== '' ? (string) $row['key_column'] : null,
            ($row['date_order'] ?? '') === 'mdy' ? 'mdy' : 'dmy', (string) $row['status'], (int) ($row['position'] ?? 0), (int) ($row['next_byte'] ?? 0),
            array_map('intval', $json($row['counts'] ?? null)), isset($row['message']) ? (string) $row['message'] : null,
            (string) $row['created_at'], (string) $row['updated_at'],
            isset($row['finished_at']) ? (string) $row['finished_at'] : null, isset($row['undone_at']) ? (string) $row['undone_at'] : null,
        );
    }

    public function count(string $name): int { return $this->counts[$name] ?? 0; }

    /** Checking or importing, and moved within the last two minutes. */
    public function isWorking(): bool
    {
        return in_array($this->status, ['checking', 'importing', 'undoing'], true) && strtotime($this->updatedAt) >= time() - self::PAUSE_SECONDS;
    }

    public function isPaused(): bool
    {
        return in_array($this->status, ['checking', 'importing', 'undoing'], true) && !$this->isWorking();
    }

    /** Whether the run created or updated records that an Undo could take back. */
    public function canUndo(): bool
    {
        $days = \FloCMS\Admin\Admin::config()->import()['undo_days'];
        return $days > 0 && in_array($this->status, ['finished', 'cancelled', 'failed'], true) && $this->undoneAt === null
            && ($this->count('created') + $this->count('updated')) > 0
            && $this->finishedAt !== null && strtotime($this->finishedAt) >= time() - $days * 86400;
    }

    /** The decimal comma of files saved with ";" (European Excel). */
    public function decimalComma(): bool { return $this->separator === ';'; }
}
