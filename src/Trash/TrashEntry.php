<?php
declare(strict_types=1);

namespace FloCMS\Admin\Trash;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Translation\Translations;

/**
 * One record waiting in the trash: its row, its many-to-many links and its
 * managed upload files, who deleted it and when.
 */
final class TrashEntry
{
    /**
     * @param array<string, mixed> $data the row, column => value
     * @param array<string, list<string>> $links many-to-many field => related keys
     * @param list<string> $files managed upload paths, deleted when the entry is purged
     */
    public function __construct(
        public readonly int $id,
        public readonly string $resource,
        public readonly string $recordId,
        public readonly string $title,
        public readonly array $data,
        public readonly array $links,
        public readonly array $files,
        public readonly string $batch,
        public readonly ?int $deletedBy,
        public readonly ?string $deletedByName,
        public readonly string $deletedAt,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $links = json_decode((string) ($row->links ?? ''), true);
        $files = json_decode((string) ($row->files ?? ''), true);
        return new self(
            (int) $row->id,
            (string) $row->resource,
            (string) $row->record_id,
            (string) ($row->title ?? ''),
            Snapshot::decode((string) $row->data),
            is_array($links) ? array_map(static fn ($ids): array => array_values(array_map('strval', is_array($ids) ? $ids : [])), $links) : [],
            is_array($files) ? array_values(array_filter($files, 'is_string')) : [],
            (string) $row->batch,
            $row->deleted_by !== null ? (int) $row->deleted_by : null,
            $row->deleted_by_name !== null ? (string) $row->deleted_by_name : null,
            (string) $row->deleted_at,
        );
    }

    /** The record's title in the admin's language, or "#id". */
    public function label(): string
    {
        $text = $this->title !== '' ? trim(Translations::get($this->title, \FloCMS\Admin\Support\AdminLang::language())) : '';
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        return $text !== '' ? mb_strimwidth($text, 0, 120, '…') : '#' . $this->recordId;
    }

    /** The row as an object (columns of the resource's table render from it). */
    public function record(): object
    {
        return (object) $this->data;
    }

    /** When it will be deleted for good (a Unix time), or null when the trash keeps it until emptied. */
    public function purgeAt(?int $days = null): ?int
    {
        $days ??= Trash::days();
        if ($days <= 0) { return null; }
        $deleted = strtotime($this->deletedAt);
        return $deleted === false ? null : $deleted + $days * 86400;
    }

    /** Whole days left before it is deleted for good (0 = today), or null when it is kept. */
    public function daysLeft(?int $now = null): ?int
    {
        $at = $this->purgeAt();
        if ($at === null) { return null; }
        return max(0, (int) ceil(($at - ($now ?? time())) / 86400));
    }

    /** The resource class it belongs to, or null when that resource is no longer registered. */
    public function resourceClass(): ?string
    {
        return Admin::registry()->find($this->resource);
    }
}
