<?php
declare(strict_types=1);

namespace FloCMS\Admin\View;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Dates;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\App;

/**
 * One field's value on the view page (0.27), as HTML: text, links, badges,
 * thumbnails, files, items. Every value is escaped; rich text is cleaned by
 * the field's own allow-list.
 *
 * @internal
 */
final class ValueRenderer
{
    /**
     * @param array<string, Column> $columns the list's columns, for badges and formatting
     */
    public function __construct(
        private readonly Resource $resource,
        private readonly ?object $record,
        private readonly string $lang,
        private readonly string $defaultLang,
        private readonly array $columns = [],
    ) {
    }

    /** Whether the value is empty or not translated in the shown language. */
    public function missing(Field $field, mixed $state): bool
    {
        // Not translated: the default language has text and this one has none.
        return $field->isTranslatable() && $this->lang !== $this->defaultLang
            && self::blank(self::pick($state, $this->lang)) && !self::blank(self::pick($state, $this->defaultLang));
    }

    public function render(Field $field, mixed $state): string
    {
        if ($field->isTranslatable()) {
            $value = self::pick($state, $this->lang);
            if (self::blank($value)) {
                return $this->missing($field, $state) ? '<span class="fa-view-missing">' . Str::e(AdminLang::get('view.not_translated')) . '</span>' : self::empty();
            }
            $state = $value;
        }
        if (($formatter = $field->getViewFormatter()) !== null) {
            $text = (string) $formatter(is_array($state) ? $state : (string) $state, $this->record);
            return $text === '' ? self::empty() : Str::e($text);
        }
        return match (true) {
            $field instanceof Fields\KeyValue => $this->keyValue($field, $state),
            $field instanceof Fields\Repeater => $this->repeater($field, $state),
            $field instanceof Fields\RichEditor => $this->rich($field, (string) $state),
            $field instanceof Fields\Placeholder => self::blank($state) ? self::empty() : Str::e((string) $state),
            $field instanceof Fields\Toggle => $this->toggle((string) $state),
            $field instanceof Fields\Select => $this->select($field, (string) $state),
            $field instanceof Fields\BelongsToMany => $this->related($field, self::idList($state)),
            $field instanceof Fields\BelongsTo => $this->related($field, self::blank($state) ? [] : [(string) $state]),
            $field instanceof Fields\MediaPicker => $this->media(Fields\MediaPicker::ids($state)),
            $field instanceof Fields\FileUpload => $this->file($field, (string) $state),
            $field instanceof Fields\DateTime => $this->date((string) $state, true),
            $field instanceof Fields\Date => $this->date((string) $state, false),
            $field instanceof Fields\ColorPicker => $this->color((string) $state),
            $field instanceof Fields\Slug => self::blank($state) ? self::empty() : '<span class="fa-view-copyable"><code dir="ltr">' . Str::e((string) $state) . '</code>' . self::copy((string) $state) . '</span>',
            $field instanceof Fields\TextInput => $this->text($field, (string) $state),
            $field instanceof Fields\Textarea => self::blank($state) ? self::empty() : '<span class="fa-view-text">' . nl2br(Str::e((string) $state), false) . '</span>',
            default => self::blank($state) ? self::empty() : Str::e(is_array($state) ? implode(', ', array_map('strval', array_filter($state, 'is_scalar'))) : (string) $state),
        };
    }

    private function text(Fields\TextInput $field, string $value): string
    {
        if (trim($value) === '') { return self::empty(); }
        $e = Str::e($value);
        return match ($field->getType()) {
            'email' => '<span class="fa-view-copyable"><a href="mailto:' . Str::e($value) . '" dir="ltr">' . $e . '</a>' . self::copy($value) . '</span>',
            'tel' => '<span class="fa-view-copyable"><a href="tel:' . Str::e(preg_replace('/[^0-9+]/', '', $value) ?? '') . '" dir="ltr">' . $e . '</a>' . self::copy($value) . '</span>',
            'url' => preg_match('#^https?://#i', $value) === 1
                ? '<span class="fa-view-copyable"><a href="' . $e . '" target="_blank" rel="noopener noreferrer" dir="ltr">' . $e . '</a>' . self::copy($value) . '</span>'
                : $e,
            'number' => '<span dir="ltr">' . $e . '</span>',
            default => $e,
        };
    }

    private function rich(Fields\RichEditor $field, string $html): string
    {
        $clean = $field->clean($html);
        if (trim(strip_tags($clean, '<img><video><iframe>')) === '') { return self::empty(); }
        return '<div class="rt-content fa-view-rich">' . $clean . '</div>';
    }

    private function toggle(string $value): string
    {
        $on = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        return '<span class="fa-view-bool' . ($on ? ' is-on' : '') . '">' . Icons::svg($on ? 'check' : 'x') . '<span>' . Str::e(AdminLang::get($on ? 'view.yes' : 'view.no')) . '</span></span>';
    }

    private function select(Fields\Select $field, string $value): string
    {
        if ($value === '') { return self::empty(); }
        // The list's badge for this field, with its colours
        $column = $this->columns[$field->getName()] ?? null;
        if ($column instanceof \FloCMS\Admin\Table\Columns\BadgeColumn && $this->record !== null) {
            $html = $column->render($this->record);
            if (trim(strip_tags($html)) !== '') { return $html; }
        }
        $options = $field->getOptions();
        return Str::e((string) ($options[$value] ?? $value));
    }

    /** @param list<string> $ids */
    private function related(Field $field, array $ids): string
    {
        if ($ids === []) { return self::empty(); }
        /** @var Fields\BelongsTo|Fields\BelongsToMany $field */
        $db = App::db();
        if ($db === null || !method_exists($field, 'relation')) { return Str::e(implode(', ', $ids)); }
        $relation = $field->relation();
        $ids = array_slice($ids, 0, 100);
        $titles = $relation->titles($db, $ids);
        $class = $relation->resourceClass();
        $rows = [];
        if ($class !== null) {
            foreach ($relation->query($db)->whereIn($relation->key(), $ids)->get() as $row) { $rows[(string) $row->{$relation->key()}] = $row; }
        }
        $chips = [];
        foreach ($ids as $id) {
            if (!isset($titles[$id])) { continue; }
            $url = $class !== null && isset($rows[$id]) ? (new $class())->recordUrl($rows[$id]) : null;
            $text = Str::e($titles[$id]);
            $chips[] = $url !== null ? '<a href="' . Str::e($url) . '">' . $text . '</a>' : '<span>' . $text . '</span>';
        }
        if ($chips === []) { return self::empty(); }
        return $field instanceof Fields\BelongsToMany ? '<span class="fa-view-chips">' . implode('', $chips) . '</span>' : $chips[0];
    }

    /** @param list<int|string> $ids */
    private function media(array $ids): string
    {
        $store = MediaLibrary::storeOrNull();
        if ($ids === [] || $store === null) { return self::empty(); }
        $html = '';
        foreach ($ids as $id) {
            $item = $store->find($id);
            if ($item === null) { continue; }
            $alt = $item->alt($this->lang);
            if ($item->isImage()) {
                $html .= '<figure class="fa-view-media"><a href="' . Str::e($item->url()) . '" target="_blank" rel="noopener"><img src="' . Str::e($item->url('thumb')) . '" alt="' . Str::e($alt) . '" loading="lazy"></a>'
                    . ($alt !== '' ? '<figcaption>' . Str::e($alt) . '</figcaption>' : '') . '</figure>';
            } else {
                $html .= '<figure class="fa-view-media is-file"><a href="' . Str::e($item->url()) . '" target="_blank" rel="noopener">' . Icons::svg('video') . '<span>' . Str::e($item->label()) . '</span></a></figure>';
            }
        }
        return $html === '' ? self::empty() : '<div class="fa-view-gallery">' . $html . '</div>';
    }

    private function file(Fields\FileUpload $field, string $value): string
    {
        if ($value === '' || str_starts_with($value, '@')) { return self::empty(); }
        $url = \FloCMS\Admin\Uploads\Uploads::url($this->resource, $this->record)($field, $value);
        $name = basename($value);
        $extension = strtoupper((string) pathinfo($name, PATHINFO_EXTENSION));
        $path = \FloCMS\Admin\Uploads\Uploads::storageFor($field)->path($value);
        $size = $path !== null && is_file($path) ? self::bytes((int) filesize($path)) : '';
        if ($field instanceof Fields\ImageUpload && $url !== '') {
            return '<figure class="fa-view-media"><a href="' . Str::e($url) . '" target="_blank" rel="noopener"><img src="' . Str::e($url) . '" alt="" loading="lazy"></a></figure>';
        }
        return '<span class="fa-view-file"><span class="fa-view-file-type" aria-hidden="true">' . Str::e($extension !== '' ? $extension : 'FILE') . '</span>'
            . '<span class="fa-view-file-name"><bdi>' . Str::e($name) . '</bdi>' . ($size !== '' ? '<small>' . Str::e($size) . '</small>' : '') . '</span>'
            . ($url !== '' ? '<a href="' . Str::e($url) . '">' . Str::e(AdminLang::get('view.download')) . '</a>' : '') . '</span>';
    }

    private function date(string $value, bool $withTime): string
    {
        if (trim($value) === '') { return self::empty(); }
        $time = strtotime($value);
        if ($time === false) { return Str::e($value); }
        return '<time datetime="' . Str::e($value) . '" title="' . Str::e(Dates::full($time)) . '">' . Str::e($withTime ? Dates::full($time) : Dates::date($time)) . '</time>';
    }

    private function color(string $value): string
    {
        if (preg_match('/^#[0-9a-f]{3,8}$/iD', $value) !== 1) { return self::blank($value) ? self::empty() : Str::e($value); }
        // A disabled colour input draws the swatch without a style attribute (strict CSP).
        $swatch = preg_match('/^#[0-9a-f]{6}$/iD', $value) === 1 ? strtolower($value) : null;
        return '<span class="fa-view-color">' . ($swatch !== null ? '<input type="color" class="fa-view-swatch" value="' . Str::e($swatch) . '" disabled tabindex="-1" aria-hidden="true">' : '')
            . '<code dir="ltr">' . Str::e($value) . '</code></span>';
    }

    private function repeater(Fields\Repeater $field, mixed $state): string
    {
        $items = $field->items($state);
        if ($items === []) { return self::empty(); }
        // The same fields as the page leaves out: hidden ones, hiddenOnView(), passwords
        $schema = array_filter($field->fields(), static fn (Field $f): bool => !$f instanceof Fields\Hidden && !$f->isHiddenOnView()
            && !($f instanceof Fields\TextInput && $f->isPassword()));
        $inner = new self($this->resource, null, $this->lang, $this->defaultLang);
        if ($field->isTable()) {
            $head = '<th scope="col">#</th>';
            foreach ($schema as $item) { $head .= '<th scope="col">' . Str::e($item->getLabel()) . '</th>'; }
            $rows = '';
            foreach ($items as $index => $values) {
                $rows .= '<tr><td class="fa-view-index">' . ($index + 1) . '</td>';
                foreach ($schema as $name => $item) { $rows .= '<td>' . $inner->render($item, $values[$name] ?? '') . '</td>'; }
                $rows .= '</tr>';
            }
            return '<div class="fa-view-table-wrap"><table class="fa-view-table"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
        }
        $cards = '';
        foreach ($items as $index => $values) {
            $title = $field->getItemLabelField() !== null ? $inner->plain($schema[$field->getItemLabelField()] ?? null, $values[$field->getItemLabelField()] ?? '') : '';
            $cards .= '<li class="fa-view-item"><p class="fa-view-item-head"><span class="fa-view-index">' . ($index + 1) . '</span>' . ($title !== '' ? '<span>' . Str::e($title) . '</span>' : '') . '</p><dl class="fa-view-grid fa-grid-' . max(1, min(4, $field->getItemColumns())) . '">';
            foreach ($schema as $name => $item) {
                $cards .= '<div class="fa-view-field"><dt>' . Str::e($item->getLabel()) . '</dt><dd>' . $inner->render($item, $values[$name] ?? '') . '</dd></div>';
            }
            $cards .= '</dl></li>';
        }
        return '<ol class="fa-view-items">' . $cards . '</ol>';
    }

    private function keyValue(Fields\KeyValue $field, mixed $state): string
    {
        $items = array_filter($field->items($state), static fn (array $item): bool => !$field->isEmptyItem($item));
        if ($items === []) { return self::empty(); }
        $schema = $field->fields();
        $inner = new self($this->resource, null, $this->lang, $this->defaultLang);
        $html = '';
        foreach ($items as $item) {
            $html .= '<div><dt>' . $inner->render($schema['key'], $item['key'] ?? '') . '</dt><dd>' . $inner->render($schema['value'], $item['value'] ?? '') . '</dd></div>';
        }
        return '<dl class="fa-view-pairs">' . $html . '</dl>';
    }

    /** A value as plain text (a repeater item's title). */
    private function plain(?Field $field, mixed $state): string
    {
        if ($field === null) { return ''; }
        $value = $field->isTranslatable() ? (self::pick($state, $this->lang) ?: self::pick($state, $this->defaultLang)) : $state;
        if ($field instanceof Fields\Select) { $options = $field->getOptions(); return (string) ($options[(string) $value] ?? $value); }
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function pick(mixed $state, string $lang): mixed
    {
        return is_array($state) ? ($state[$lang] ?? '') : $state;
    }

    private static function blank(mixed $value): bool
    {
        if (is_array($value)) { return $value === [] || array_filter($value, static fn ($v): bool => !self::blank($v)) === []; }
        return trim((string) $value) === '';
    }

    /** @return list<string> */
    private static function idList(mixed $state): array
    {
        if (is_array($state)) { return array_values(array_map('strval', array_filter($state, 'is_scalar'))); }
        $state = trim((string) $state);
        if ($state === '') { return []; }
        if (str_starts_with($state, '[')) { $decoded = json_decode($state, true); return is_array($decoded) ? self::idList($decoded) : []; }
        return array_values(array_filter(array_map('trim', explode(',', $state)), static fn (string $id): bool => $id !== ''));
    }

    private static function copy(string $value): string
    {
        $label = AdminLang::get('view.copy');
        return '<button type="button" class="fa-icon-action fa-view-copy" data-admin-copy="' . Str::e($value) . '" data-copied-text="' . Str::e(AdminLang::get('view.copied')) . '" aria-label="' . Str::e($label) . '" title="' . Str::e($label) . '" hidden>' . Icons::svg('copy') . '</button>';
    }

    public static function empty(): string
    {
        return '<span class="fa-view-empty" aria-label="' . Str::e(AdminLang::get('view.empty')) . '">—</span>';
    }

    private static function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) { return number_format($bytes / 1048576, 1) . ' MB'; }
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
