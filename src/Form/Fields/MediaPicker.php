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
 *     MediaPicker::make('cover_id')->folder('Listings')->aspectRatio('16:9')   (0.18)
 *
 * ->folder(): where files uploaded through the field go, and where its picker opens
 * (a path like "Listings/Villas", made when the first file arrives). ->aspectRatio():
 * the shape of a single item's preview (default 4:3, videos 16:9).
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
    private ?string $folder = null;
    private ?string $aspect = null;

    /** Uploads through this field go to this media folder ("Listings/Villas"); its picker opens there. */
    public function folder(string $path): static
    {
        $path = trim(preg_replace('~\s*/\s*~', '/', trim($path)) ?? '', '/');
        $parts = $path === '' ? [] : explode('/', $path);
        if ($parts === [] || mb_strlen($path) > 255 || count($parts) > \FloCMS\Admin\Media\MediaFolders::MAX_DEPTH
            || array_filter($parts, static fn (string $p): bool => $p === '' || mb_strlen($p) > \FloCMS\Admin\Media\MediaFolders::NAME_MAX) !== []) {
            throw new \InvalidArgumentException('MediaPicker folder must be a path such as "Listings/Villas", at most ' . \FloCMS\Admin\Media\MediaFolders::MAX_DEPTH . ' levels.');
        }
        $this->folder = $path;
        return $this;
    }

    /** The shape of a single item's preview: "16:9", "4:3", "1:1", "3:2"… */
    public function aspectRatio(string $ratio): static
    {
        if (preg_match('/^([1-9][0-9]{0,2})\s*[:\/]\s*([1-9][0-9]{0,2})$/D', trim($ratio), $parts) !== 1) {
            throw new \InvalidArgumentException('MediaPicker aspectRatio must look like "16:9".');
        }
        $this->aspect = $parts[1] . ' / ' . $parts[2];
        return $this;
    }

    public function getFolder(): ?string { return $this->folder; }
    public function getAspectRatio(): ?string { return $this->aspect; }

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
            // The chosen ids stay in the form, so a list item keeps its media (0.24).
            return '<p class="fa-help">' . Str::e(AdminLang::get('media.not_ready')) . '</p>'
                . ($value !== '' && !$this->isDisabled() ? '<input type="hidden" name="' . Str::e($name) . '" value="' . Str::e($value) . '">' : '');
        }
        $chosen = $store->findMany($ids);
        $folder = $this->folder !== null ? MediaLibrary::folders()->resolve($this->folder) : null;
        $html = '<div class="fa-media-field" data-admin-media-field data-kind="' . Str::e($this->kind ?? '') . '" data-multiple="' . ($this->multiple ? 'true' : 'false') . '"'
            . ($this->multiple ? ' data-max="' . ($this->max ?? self::MAX) . '"' : '')
            . ($folder !== null ? ' data-folder="' . $folder . '"' : '')
            . ($this->folder !== null ? ' data-folder-path="' . Str::e($this->folder) . '"' : '')
            . ($this->aspect !== null ? ' data-aspect="' . Str::e($this->aspect) . '"' : '')
            . ' data-label="' . Str::e($this->getLabel()) . '"' . ($this->isDisabled() ? ' data-disabled="true"' : '')
            . ' data-items="' . Str::e(json_encode(array_map(static fn (MediaItem $item): array => $item->toArray(), $chosen), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">';

        // Without JavaScript: a select of the newest items plus the chosen ones (the script replaces it).
        $options = [];
        foreach ($chosen as $item) { $options[$item->id] = $item; }
        foreach ($store->newest(self::PLAIN, $this->kind) as $item) { $options[$item->id] ??= $item; }
        $select = ['id' => $id, 'name' => $name === '' ? null : $name . ($this->multiple ? '[]' : ''), 'class' => 'fa-input fa-select fa-media-select',
            'data-media-select' => true, 'multiple' => $this->multiple, 'size' => $this->multiple ? '6' : null, 'disabled' => $this->isDisabled()];
        foreach ($attributes as $key => $attribute) { $select[$key] = $attribute; }
        $html .= '<div class="fa-media-nojs" data-media-nojs><select' . self::attributes($select) . '>';
        if (!$this->multiple) { $html .= '<option value="">' . Str::e(AdminLang::get('media.none')) . '</option>'; }
        foreach ($options as $item) {
            $html .= '<option value="' . $item->id . '"' . (in_array($item->id, $ids, true) ? ' selected' : '') . '>' . Str::e($item->label() . ' (#' . $item->id . ')') . '</option>';
        }
        $html .= '</select></div>';
        if ($name !== '') {
            // The script submits this one (it keeps the chosen order); without JavaScript the select is sent.
            $html .= '<input type="hidden" name="' . Str::e($name) . '" value="' . Str::e($value) . '" data-media-value disabled>';
        }
        return $html . '</div>';
    }
}
