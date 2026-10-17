<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;

/**
 * Thumbnails of library items stored by a MediaPicker field:
 *
 *     MediaColumn::make('cover_id')->label('Cover')
 *     MediaColumn::make('gallery')->limit(3)          several, then "+n"
 *
 * Items are loaded once per page, not once per row.
 */
class MediaColumn extends Column
{
    private int $limit = 3;

    public function limit(int $limit): static
    {
        $this->limit = max(1, min(10, $limit));
        return $this;
    }

    public function isSortable(): bool { return false; }
    public function isSearchable(): bool { return false; }

    public function prepareRecords(array $records): void
    {
        $store = MediaLibrary::storeOrNull();
        if ($store === null) { return; }
        $ids = [];
        foreach ($records as $record) { foreach (MediaPicker::ids($record->{$this->name} ?? null) as $id) { $ids[] = $id; } }
        $store->preload($ids);
    }

    public function render(object $record): string
    {
        $ids = MediaPicker::ids($record->{$this->name} ?? null);
        $store = MediaLibrary::storeOrNull();
        if ($ids === [] || $store === null) { return '<span class="fa-muted">&ndash;</span>'; }
        $html = '<span class="fa-media-cell">';
        foreach (array_slice($ids, 0, $this->limit) as $id) {
            $item = $store->find($id);
            if ($item === null) {
                $html .= '<span class="fa-media-thumb is-missing" title="' . Str::e(AdminLang::get('relations.missing', ['id' => (string) $id])) . '">#' . $id . '</span>';
            } elseif ($item->isImage()) {
                [$width, $height] = $item->thumbSize();
                $html .= '<img class="fa-media-thumb" src="' . Str::e($item->url('thumb')) . '" alt="' . Str::e($item->alt()) . '" loading="lazy"'
                    . ($width !== null ? ' width="' . $width . '" height="' . $height . '"' : '') . '>';
            } else {
                $html .= '<span class="fa-media-thumb is-video" title="' . Str::e($item->label()) . '">' . \FloCMS\Admin\Support\Icons::svg('video') . '</span>';
            }
        }
        $more = count($ids) - $this->limit;
        if ($more > 0) { $html .= '<span class="fa-muted">' . Str::e(AdminLang::get('relations.and_more', ['count' => (string) $more])) . '</span>'; }
        return $html . '</span>';
    }

    public function cellClass(): string
    {
        return 'fa-col-media';
    }
}
