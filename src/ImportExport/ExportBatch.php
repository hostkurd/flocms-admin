<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields;
use FloCMS\Core\Database;

/**
 * One page of exported rows (0.25) with what their cells need, read once for the
 * page: related titles, many-to-many keys and media URLs.
 */
final class ExportBatch
{
    /** @var array<string, array<string, string>> field => key => title */
    private array $titles = [];
    /** @var array<string, array<string, list<string>>> field => owner => related keys */
    private array $related = [];
    /** @var array<string, string> media id => URL */
    private array $media = [];

    /**
     * @param array<string, Field> $fields
     * @param list<object> $rows
     */
    public function __construct(array $fields, array $rows, Database $db, private readonly bool $readable)
    {
        $mediaIds = [];
        foreach ($fields as $name => $field) {
            if ($field instanceof Fields\BelongsTo && $field->hasRelation()) {
                $ids = array_values(array_unique(array_filter(array_map(static fn (object $r): string => (string) ($r->{$name} ?? ''), $rows), static fn (string $id): bool => $id !== '')));
                $this->titles[$name] = $ids === [] ? [] : $field->relation()->titles($db, $ids);
            }
            if ($field instanceof Fields\BelongsToMany && ($pivot = $field->getPivot()) !== null) {
                $owners = array_values(array_unique(array_filter(array_map(static fn (object $r): string => (string) ($r->{$pivot->parentKey} ?? ''), $rows), static fn (string $id): bool => $id !== '')));
                $this->related[$name] = $owners === [] ? [] : array_map(static fn (array $ids): array => array_map('strval', $ids), $pivot->related($db, $owners));
                $all = array_values(array_unique(array_merge(...array_values($this->related[$name] ?: [[]]))));
                $this->titles[$name] = $all === [] || !$field->hasRelation() ? [] : $field->relation()->titles($db, $all);
            }
            if ($field instanceof Fields\MediaPicker) {
                foreach ($rows as $row) {
                    foreach (Fields\MediaPicker::ids(is_scalar($row->{$name} ?? null) ? (string) $row->{$name} : null) as $id) { $mediaIds[] = (string) $id; }
                }
            }
        }
        $store = $mediaIds === [] ? null : \FloCMS\Admin\Media\MediaLibrary::storeOrNull();
        if ($store !== null) {
            foreach ($store->findMany(array_values(array_unique($mediaIds))) as $item) { $this->media[(string) $item->id] = $item->url(); }
        }
        $this->owners = [];
        foreach ($fields as $name => $field) {
            if ($field instanceof Fields\BelongsToMany && ($pivot = $field->getPivot()) !== null) { $this->owners[$name] = $pivot->parentKey; }
        }
    }

    /** @var array<string, string> field => owner column */
    private array $owners;

    public function readable(): bool { return $this->readable; }

    public function title(string $field, string $id): string
    {
        return $id === '' ? '' : ($this->titles[$field][$id] ?? '');
    }

    /** @return list<string> */
    public function related(string $field, object $row): array
    {
        $owner = (string) ($row->{$this->owners[$field] ?? 'id'} ?? '');
        return $this->related[$field][$owner] ?? [];
    }

    /** @return list<string> */
    public function mediaUrls(string $field, string $value): array
    {
        $urls = [];
        foreach (Fields\MediaPicker::ids($value) as $id) {
            if (isset($this->media[(string) $id])) { $urls[] = $this->media[(string) $id]; }
        }
        return $urls;
    }
}
