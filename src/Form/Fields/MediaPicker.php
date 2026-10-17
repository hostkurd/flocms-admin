<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Media\MediaItem;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Media\MediaStore;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;

/**
 * One or several items of the media library, stored as ids:
 *
 *     MediaPicker::make('cover_id')->label('Cover')->required()     INT column
 *     MediaPicker::make('gallery')->multiple()->max(12)              TEXT column, JSON list in the chosen order
 *     MediaPicker::make('intro_video_id')->videos()                  videos instead of images (->any() for both)
 *
 * The website reads them with media($record->cover_id) and media_list($record->gallery).
 * Without JavaScript the field is a select of the newest 200 items.
 */
class MediaPicker extends Field
{
    public const MAX = 100;
    public const PLAIN = 200;

    private ?string $kind = 'image';
    private bool $multiple = false;
    private ?int $max = null;

    public function images(): static { $this->kind = 'image'; return $this; }
    public function videos(): static { $this->kind = 'video'; return $this; }
    public function any(): static { $this->kind = null; return $this; }

    /** Several items, kept in the chosen order (a JSON list in a TEXT column). */
    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;
        return $this;
    }

    public function max(int $max): static
    {
        if ($max < 1 || $max > self::MAX) { throw new \InvalidArgumentException('MediaPicker max must be 1–' . self::MAX . '.'); }
        $this->max = $max;
        $this->multiple = true;
        return $this;
    }

    public function getKind(): ?string { return $this->kind; }
    public function isMultiple(): bool { return $this->multiple; }
    public function getMax(): ?int { return $this->max; }

    /**
     * Ids from a stored or submitted value: an id, a JSON list, or a list.
     *
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if (is_int($value)) { return MediaStore::ids([$value]); }
        if (is_array($value)) { return MediaStore::ids(array_values(array_filter($value, static fn ($id): bool => is_int($id) || is_string($id)))); }
        if (!is_string($value) || trim($value) === '') { return []; }
        $value = trim($value);
        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? self::ids($decoded) : [];
        }
        return MediaStore::ids([$value]);
    }

    private function encode(array $ids): string
    {
        // Not cut to MAX here: typeError() reports too many items instead of dropping some silently.
        $ids = MediaStore::ids($ids);
        if (!$this->multiple) { return $ids === [] ? '' : (string) $ids[0]; }
        return $ids === [] ? '' : json_encode(array_map('strval', $ids), JSON_THROW_ON_ERROR);
    }

    public function stateFromRecord(?object $record, string $defaultLang): string|array
    {
        if ($record === null || !property_exists($record, $this->name)) {
            return $this->defaultState($defaultLang);
        }
        return $this->encode(self::ids($record->{$this->name}));
    }

    protected function defaultState(string $defaultLang): string|array
    {
        $default = $this->default instanceof \Closure ? ($this->default)() : $this->default;
        return $this->encode(self::ids($default));
    }

    public function stateFromInput(array $input, array $languages): string|array
    {
        $value = $input[$this->name] ?? null;
        return $this->encode(self::ids(is_array($value) ? array_values($value) : (is_scalar($value) ? (string) $value : '')));
    }

    /**
     * Without the library (switched off, or its tables not migrated yet) the field
     * shows no input, so it must keep the stored ids instead of writing NULL.
     */
    public function skipsWrite(string|array $state, bool $isEdit): bool
    {
        return MediaLibrary::storeOrNull() === null;
    }

    public function typeError(string $value): ?array
    {
        $ids = self::ids($value);
        if ($ids === []) { return null; }
        if (!$this->multiple && count($ids) > 1) { return ['validation.media', []]; }
        $max = $this->max ?? self::MAX;
        if (count($ids) > $max) { return ['validation.media_max', ['max' => (string) $max]]; }
        $store = MediaLibrary::storeOrNull();
        if ($store === null) { return ['validation.media', []]; }
        $kinds = $store->kinds($ids);
        foreach ($ids as $id) {
            if (!isset($kinds[$id]) || ($this->kind !== null && $kinds[$id] !== $this->kind)) { return ['validation.media', []]; }
        }
        return null;
    }

    /** Single: the id (INT) or null. Multiple: a JSON list or null. */
    public function dehydrate(string|array $state): mixed
    {
        $ids = self::ids(is_array($state) ? '' : $state);
        if ($ids === []) { return null; }
        return $this->multiple ? json_encode(array_map('strval', $ids), JSON_THROW_ON_ERROR) : $ids[0];
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $ids = self::ids($value);
        $store = MediaLibrary::storeOrNull();
        if ($store === null) {
            return '<p class="fa-help">' . Str::e(AdminLang::get('media.not_ready')) . '</p>';
        }
        $chosen = $store->findMany($ids);
        $strings = ['choose' => AdminLang::get($this->multiple ? 'media.add' : ($this->kind === 'video' ? 'media.choose_video' : 'media.choose_image')),
            'change' => AdminLang::get('media.change'), 'remove' => AdminLang::get('media.remove'), 'title' => AdminLang::get('media.picker_title'),
            'search' => AdminLang::get('media.search'), 'upload' => AdminLang::get('media.upload'), 'select' => AdminLang::get('media.select'),
            'cancel' => AdminLang::get('actions.cancel'), 'more' => AdminLang::get('media.more'), 'empty' => AdminLang::get('media.empty'),
            'loading' => AdminLang::get('reactive.loading'), 'failed' => AdminLang::get('media.failed'), 'uploading' => AdminLang::get('media.uploading'),
            'moveBefore' => AdminLang::get('media.move_before'), 'moveAfter' => AdminLang::get('media.move_after'), 'moved' => AdminLang::get('media.moved'),
            'max' => $this->max !== null ? AdminLang::get('media.max', ['max' => (string) $this->max]) : '', 'missing' => AdminLang::get('media.missing_item')];
        $html = '<div class="fa-media-field" data-admin-media-field data-kind="' . Str::e($this->kind ?? '') . '" data-multiple="' . ($this->multiple ? 'true' : 'false') . '"'
            . ($this->multiple ? ' data-max="' . ($this->max ?? self::MAX) . '"' : '')
            . ' data-items="' . Str::e(json_encode(array_map(static fn (MediaItem $item): array => $item->toArray(), $chosen), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
            . ' data-strings="' . Str::e(json_encode($strings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) . '">';

        // Without JavaScript: a select of the newest items plus the chosen ones (the script replaces it).
        $options = [];
        foreach ($chosen as $item) { $options[$item->id] = $item; }
        foreach ($store->newest(self::PLAIN, $this->kind) as $item) { $options[$item->id] ??= $item; }
        $select = ['id' => $id, 'name' => $name === '' ? null : $name . ($this->multiple ? '[]' : ''), 'class' => 'fa-input fa-select fa-media-select',
            'data-media-select' => true, 'multiple' => $this->multiple, 'size' => $this->multiple ? '6' : null, 'disabled' => $this->isDisabled()];
        foreach ($attributes as $key => $attribute) { $select[$key] = $attribute; }
        $html .= '<select' . self::attributes($select) . '>';
        if (!$this->multiple) { $html .= '<option value="">' . Str::e(AdminLang::get('media.none')) . '</option>'; }
        foreach ($options as $item) {
            $html .= '<option value="' . $item->id . '"' . (in_array($item->id, $ids, true) ? ' selected' : '') . '>' . Str::e($item->label() . ' (#' . $item->id . ')') . '</option>';
        }
        $html .= '</select>';
        if ($name !== '') {
            // The script submits this one (it keeps the chosen order); without JavaScript the select is sent.
            $html .= '<input type="hidden" name="' . Str::e($name) . '" value="' . Str::e($value) . '" data-media-value disabled>';
        }
        return $html . '</div>';
    }
}
