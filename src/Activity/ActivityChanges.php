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
