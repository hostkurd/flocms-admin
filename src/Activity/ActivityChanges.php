<?php
declare(strict_types=1);

namespace FloCMS\Admin\Activity;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\BelongsTo;
use FloCMS\Admin\Form\Fields\BelongsToMany;
use FloCMS\Admin\Form\Fields\FileUpload;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/**
 * What changed between two versions of a record (or a settings page), field by
 * field, as text people can read later: related records by name, media items by
 * title, a choice by its label, rich text and long text by their length.
 *
 * Password fields are never compared; fields hidden from the log are shown as
 * changed without their values. Values are written as they read at the time of
 * the change, so the log stays readable when related records are renamed or deleted.
 *
 * @internal
 */
final class ActivityChanges
{
    /** Text longer than this is summarised by its length. */
    public const LONG = 200;
    private const MAX_VALUE = 300;
    private const MAX_CHANGES = 100;

    /**
     * The state of every tracked field for a record (null: the defaults of a new record).
     *
     * @return array<string, string|array<string, string>>
     */
    public static function state(Form $form, ?object $record): array
    {
        $state = [];
        foreach ($form->fields() as $name => $field) {
            if (!self::tracked($field)) { continue; }
            try {
                $state[$name] = $record === null ? ($field->isTranslatable() ? [] : '') : $field->stateFromRecord($record, Languages::default());
            } catch (\Throwable) {
                // A field that can't be read here is left out of the log
            }
        }
        return $state;
    }

    /**
     * The changed fields, in form order.
     *
     * @param array<string, string|array<string, string>> $before
     * @param array<string, string|array<string, string>> $after
     * @param list<string> $hidden more field names whose values stay out of the log
     * @return list<array{field: string, label: string, lang: ?string, type: string, before: string, after: string}>
     */
    public static function diff(Form $form, array $before, array $after, array $hidden = [], ?Database $db = null): array
    {
        $db ??= App::db();
        $changes = [];
        foreach ($form->fields() as $name => $field) {
            if (!self::tracked($field) || !array_key_exists($name, $before) || !array_key_exists($name, $after)) { continue; }
            $old = $before[$name];
            $new = $after[$name];
            $label = mb_substr(trim(strip_tags($field->getLabel())), 0, 120);
            $secret = $field->isHiddenFromActivity() || in_array($name, $hidden, true);

            // A list of items (0.24): added, removed, moved, and changes inside an item.
            if ($field instanceof \FloCMS\Admin\Form\Fields\Repeater) {
                array_push($changes, ...self::itemChanges($field, $name, $label, $secret, is_string($old) ? $old : '', is_string($new) ? $new : '', $db));
                continue;
            }

            // Blocks (0.29): added, removed, moved, and changes inside a block.
            if ($field instanceof \FloCMS\Admin\Form\Fields\Builder) {
                array_push($changes, ...self::blockChanges($field, $name, $label, $secret, is_string($old) ? $old : '', is_string($new) ? $new : '', $db));
                continue;
            }

            if (is_array($old) || is_array($new)) {
                $old = is_array($old) ? $old : [];
                $new = is_array($new) ? $new : [];
                $languages = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
                foreach ($languages as $language) {
                    $a = is_scalar($old[$language] ?? '') ? (string) ($old[$language] ?? '') : '';
                    $b = is_scalar($new[$language] ?? '') ? (string) ($new[$language] ?? '') : '';
                    if ($a === $b) { continue; }
                    $changes[] = self::entry($field, $name, $label, (string) $language, $secret, $a, $b, $before, $after, $form, $db);
                }
                continue;
            }
            $old = (string) $old;
            $new = (string) $new;
            if (self::same($field, $old, $new)) { continue; }
            $changes[] = self::entry($field, $name, $label, null, $secret, $old, $new, $before, $after, $form, $db);
        }
        return array_slice($changes, 0, self::MAX_CHANGES);
    }

    /**
     * Changes of a repeater, in words: "Rooms · Added: Kitchen", "Rooms · Bedroom · Size: 18 → 20",
     * "Rooms · Removed: Bathroom", "Rooms · Order changed". Items are matched by their "_key"
     * (KeyValue pairs by their key), so a moved item reads as moved.
     *
     * @return list<array{field: string, label: string, lang: ?string, type: string, before: string, after: string}>
     */
    private static function itemChanges(\FloCMS\Admin\Form\Fields\Repeater $field, string $name, string $label, bool $secret, string $old, string $new, ?Database $db): array
    {
        $key = static fn (array $item): string => $field instanceof \FloCMS\Admin\Form\Fields\KeyValue && $field->getFixedKeys() === null
            ? 'key:' . mb_strtolower(trim(is_string($item['key'] ?? null) ? $item['key'] : '')) : (string) $item['_key'];
        $index = static function (string $state) use ($field, $key): array {
            $items = [];
            foreach ($field->items($state) as $position => $item) {
                if (!$field->isEmptyItem($item)) { $items[$key($item)] = ['item' => $item, 'position' => $position]; }
            }
            return $items;
        };
        $before = $index($old);
        $after = $index($new);
        if ($before === [] && $after === [] ) { return []; }
        if ($secret) {
            return $field->dehydrate($old) === $field->dehydrate($new) ? [] : [['field' => $name, 'label' => $label, 'lang' => null, 'type' => 'hidden', 'before' => '', 'after' => '']];
        }
        $changes = [];
        $title = static fn (array $entry): string => self::clip($field->itemTitle($entry['item'], $entry['position']));
        foreach ($before as $id => $entry) {
            if (!isset($after[$id])) { $changes[] = ['field' => $name, 'label' => $label, 'lang' => null, 'type' => 'item', 'before' => $title($entry), 'after' => '']; }
        }
        foreach ($after as $id => $entry) {
            if (!isset($before[$id])) { $changes[] = ['field' => $name, 'label' => $label, 'lang' => null, 'type' => 'item', 'before' => '', 'after' => $title($entry)]; continue; }
            $form = (new Form())->schema(array_values($field->fields()));
            $was = $before[$id]['item'];
            $now = $entry['item'];
            foreach ($field->fields() as $subName => $sub) {
                if ($sub instanceof \FloCMS\Admin\Form\Fields\Hidden) { continue; }
                $subLabel = $label . ' · ' . $title($entry) . ' · ' . mb_substr(trim(strip_tags($sub->getLabel())), 0, 120);
                $a = $was[$subName] ?? '';
                $b = $now[$subName] ?? '';
                if (is_array($a) || is_array($b)) {
                    $a = is_array($a) ? $a : [];
                    $b = is_array($b) ? $b : [];
                    foreach (array_values(array_unique(array_merge(array_keys($a), array_keys($b)))) as $language) {
                        $x = is_string($a[$language] ?? null) ? $a[$language] : '';
                        $y = is_string($b[$language] ?? null) ? $b[$language] : '';
                        if ($x !== $y) { $changes[] = ['field' => $name] + array_diff_key(self::entry($sub, $subName, $subLabel, (string) $language, $secret || $sub->isHiddenFromActivity(), $x, $y, $was, $now, $form, $db), ['field' => true]); }
                    }
                    continue;
                }
                $x = (string) $a;
                $y = (string) $b;
                if ($sub instanceof Toggle) { $x = $x === '1' ? '1' : '0'; $y = $y === '1' ? '1' : '0'; }
                if ($x !== $y) { $changes[] = ['field' => $name] + array_diff_key(self::entry($sub, $subName, $subLabel, null, $secret || $sub->isHiddenFromActivity(), $x, $y, $was, $now, $form, $db), ['field' => true]); }
            }
        }
        // The same items in another order
        $common = static fn (array $from, array $to): array => array_values(array_filter(array_keys($from), static fn (string $id): bool => isset($to[$id])));
        if ($common($before, $after) !== $common($after, $before)) {
            $changes[] = ['field' => $name, 'label' => $label, 'lang' => null, 'type' => 'order', 'before' => '', 'after' => ''];
        }
        return $changes;
    }

    /**
     * Changes of a Builder, in words: "Page content · Added: Call to action", "Page content · Hero ·
     * Heading: Welcome → Hello", "Page content · Order changed". Blocks are matched by their id,
     * in each language's list.
     *
     * @return list<array{field: string, label: string, lang: ?string, type: string, before: string, after: string}>
     */
    private static function blockChanges(\FloCMS\Admin\Form\Fields\Builder $field, string $name, string $label, bool $secret, string $old, string $new, ?Database $db): array
    {
        if ($field->dehydrate($old) === $field->dehydrate($new)) { return []; }
        if ($secret) { return [['field' => $name, 'label' => $label, 'lang' => null, 'type' => 'hidden', 'before' => '', 'after' => '']]; }
        $flatten = static function (array $nodes, string $parent = '') use (&$flatten): array {
            $flat = [];
            foreach ($nodes as $node) {
                $flat[(string) $node['id']] = $node + ['parent' => $parent];
                $flat += $flatten($node['children'] ?? [], (string) $node['id']);
            }
            return $flat;
        };
        $was = $field->lists($old);
        $now = $field->lists($new);
        $changes = [];
        foreach ($field->listKeys() as $key) {
            $lang = $key === \FloCMS\Admin\Form\Fields\Builder::SINGLE ? null : $key;
            $before = $flatten($was[$key] ?? []);
            $after = $flatten($now[$key] ?? []);
            $title = static fn (array $node): string => self::clip($field->title($node));
            foreach ($before as $id => $node) {
                if (!isset($after[$id])) { $changes[] = ['field' => $name, 'label' => $label, 'lang' => $lang, 'type' => 'item', 'before' => $title($node), 'after' => '']; }
            }
            foreach ($after as $id => $node) {
                if (!isset($before[$id])) { $changes[] = ['field' => $name, 'label' => $label, 'lang' => $lang, 'type' => 'item', 'before' => '', 'after' => $title($node)]; continue; }
                $block = $field->block((string) $node['type']);
                if ($block === null) { continue; }
                $form = $field->blockForm($block);
                $a = $before[$id]['data'] + $before[$id]['settings'];
                $b = $node['data'] + $node['settings'];
                foreach ($form->fields() as $subName => $sub) {
                    if ($sub instanceof \FloCMS\Admin\Form\Fields\Hidden) { continue; }
                    $x = (string) ($a[$subName] ?? '');
                    $y = (string) ($b[$subName] ?? '');
                    if ($sub instanceof Toggle) { $x = $x === '1' ? '1' : '0'; $y = $y === '1' ? '1' : '0'; }
                    if ($sub instanceof \FloCMS\Admin\Form\Fields\Repeater || $sub instanceof \FloCMS\Admin\Form\Fields\MediaPicker) {
                        $x = (string) json_encode($sub->dehydrate($x));
                        $y = (string) json_encode($sub->dehydrate($y));
                    }
                    if ($x === $y) { continue; }
                    $subLabel = $label . ' · ' . $title($node) . ' · ' . mb_substr(trim(strip_tags($sub->getLabel())), 0, 120);
                    $entry = $sub instanceof \FloCMS\Admin\Form\Fields\Repeater
                        ? ['label' => $subLabel, 'lang' => $lang, 'type' => 'value',
                            'before' => AdminLang::get('blocks.items_count', ['count' => (string) count(json_decode((string) $sub->dehydrate((string) ($a[$subName] ?? '')), true) ?: [])]),
                            'after' => AdminLang::get('blocks.items_count', ['count' => (string) count(json_decode((string) $sub->dehydrate((string) ($b[$subName] ?? '')), true) ?: [])])]
                        : array_diff_key(self::entry($sub, $subName, $subLabel, $lang, $sub->isHiddenFromActivity(), $x, $y, $a, $b, $form, $db), ['field' => true]);
                    $changes[] = ['field' => $name] + $entry;
                }
            }
            // The same blocks in another place or order
            $places = static fn (array $from, array $to): array => array_values(array_map(static fn (array $node): string => $node['id'] . '@' . $node['parent'] . ':' . (int) ($node['column'] ?? 0), array_filter($from, static fn (array $node): bool => isset($to[$node['id']]))));
            if ($places($before, $after) !== $places($after, $before)) {
                $changes[] = ['field' => $name, 'label' => $label, 'lang' => $lang, 'type' => 'order', 'before' => '', 'after' => ''];
            }
        }
        return $changes;
    }

    /** Fields the log can show: editable, never a password. */
    public static function tracked(Field $field): bool
    {
        return $field->isWritable() && !($field instanceof TextInput && $field->isPassword());
    }

    private static function same(Field $field, string $old, string $new): bool
    {
        if ($old === $new) { return true; }
        // Many-to-many lists without an order column: the same records in another order are not a change.
        if ($field instanceof BelongsToMany && $field->getPivot()?->orderColumn === null) {
            $a = BelongsToMany::ids($old);
            $b = BelongsToMany::ids($new);
            sort($a, SORT_STRING);
            sort($b, SORT_STRING);
            return $a === $b;
        }
        return false;
    }

    /** @return array{field: string, label: string, lang: ?string, type: string, before: string, after: string} */
    private static function entry(Field $field, string $name, string $label, ?string $language, bool $secret, string $old, string $new, array $before, array $after, Form $form, ?Database $db): array
    {
        $entry = ['field' => $name, 'label' => $label, 'lang' => $language];
        if ($secret) {
            return $entry + ['type' => 'hidden', 'before' => '', 'after' => ''];
        }
        if ($field instanceof FileUpload) {
            return $entry + ['type' => 'file', 'before' => $old !== '' ? '1' : '', 'after' => $new !== '' ? '1' : ''];
        }
        if ($field instanceof RichEditor || mb_strlen($old) > self::LONG || mb_strlen($new) > self::LONG) {
            return $entry + ['type' => 'text', 'before' => (string) self::length($old), 'after' => (string) self::length($new)];
        }
        return $entry + [
            'type' => 'value',
            'before' => self::clip(self::display($field, $name, $old, $before, $form, $db)),
            'after' => self::clip(self::display($field, $name, $new, $after, $form, $db)),
        ];
    }

    /** A value as people read it. */
    private static function display(Field $field, string $name, string $value, array $state, Form $form, ?Database $db): string
    {
        if ($value === '' && !$field instanceof Toggle) { return ''; }
        try {
            if ($field instanceof Toggle) {
                return AdminLang::get($value === '1' ? 'activity.yes' : 'activity.no');
            }
            if ($field instanceof Select) {
                $resolved = $form->evaluate($state)->field($name);
                $options = $resolved instanceof Select ? $resolved->getOptions() : $field->getOptions();
                return $options[$value] ?? $value;
            }
            if ($field instanceof BelongsTo && $db !== null && $field->hasRelation()) {
                return $field->relation()->titles($db, [$value])[$value] ?? '#' . $value;
            }
            if ($field instanceof BelongsToMany && $db !== null && $field->hasRelation()) {
                $ids = BelongsToMany::ids($value);
                $titles = $field->relation()->titles($db, $ids);
                return implode(', ', array_map(static fn (string $id): string => $titles[$id] ?? '#' . $id, $ids));
            }
            if ($field instanceof MediaPicker) {
                $ids = MediaPicker::ids($value);
                if ($ids === [] || !MediaLibrary::enabled()) { return implode(', ', array_map(static fn ($id): string => '#' . $id, $ids)); }
                $items = [];
                foreach (MediaLibrary::store()->findMany($ids) as $item) { $items[$item->id] = $item; }
                $names = [];
                foreach ($ids as $id) { $names[] = isset($items[$id]) ? $items[$id]->label() : '#' . $id; }
                return implode(', ', $names);
            }
        } catch (\Throwable) {
            // Fall back to the stored value
        }
        return $value;
    }

    /** Characters of text, without its HTML. */
    public static function length(string $value): int
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strlen(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    private static function clip(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return mb_strimwidth($value, 0, self::MAX_VALUE, '…');
    }
}
