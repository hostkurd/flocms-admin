<?php
declare(strict_types=1);

namespace FloCMS\Admin\Relations;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;

/**
 * The markup of a relation control, shared by fields and filters. Without
 * JavaScript it is a native <select> (multiple for many-to-many); the script
 * turns it into a searchable combobox (or chips) that asks the lookup URL as
 * you type, or filters the options in the page when they are complete.
 *
 * @internal
 */
final class RelationWidget
{
    /**
     * @param array{
     *   id: string, name: string, multiple: bool, selected: list<string>,
     *   options: list<array{id: string, label: string}>, complete: bool,
     *   lookup?: string, depends?: ?string, dependsLabel?: string, max?: ?int,
     *   disabled?: bool, placeholder?: ?string, attributes?: array<string, string>,
     *   jsonName?: ?string, filterKey?: ?string, empty?: string
     * } $c
     */
    public static function render(array $c): string
    {
        $multiple = $c['multiple'];
        $selected = $c['selected'];
        $attributes = $c['attributes'] ?? [];
        $html = '<div class="fa-relation" data-admin-relation data-mode="' . ($multiple ? 'multi' : 'single') . '"'
            . ' data-lookup="' . Str::e($c['lookup'] ?? '') . '" data-complete="' . ($c['complete'] ? 'true' : 'false') . '"'
            . (($c['depends'] ?? null) !== null ? ' data-depends="' . Str::e((string) $c['depends']) . '" data-depends-label="' . Str::e($c['dependsLabel'] ?? '') . '"' : '')
            . (($c['max'] ?? null) !== null ? ' data-max="' . (int) $c['max'] . '"' : '')
            . (($c['filterKey'] ?? null) !== null ? ' data-filter-key="' . Str::e((string) $c['filterKey']) . '"' : '')
            . ' data-strings="' . Str::e(json_encode(self::strings($c), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) . '">';

        $select = ['id' => $c['id'], 'name' => $c['name'] === '' ? null : $c['name'] . ($multiple ? '[]' : ''), 'class' => 'fa-input fa-select fa-relation-select',
            'data-relation-select' => true, 'multiple' => $multiple, 'size' => $multiple ? '6' : null, 'disabled' => $c['disabled'] ?? false];
        foreach ($attributes as $key => $value) { $select[$key] = $value; }
        $html .= '<select' . self::attributes($select) . '>';
        if (!$multiple) {
            $html .= '<option value="">' . Str::e($c['empty'] ?? (($c['placeholder'] ?? null) ?: AdminLang::get('form.choose'))) . '</option>';
        }
        $seen = [];
        foreach ($c['options'] as $option) {
            if (isset($seen[$option['id']])) { continue; }
            $seen[$option['id']] = true;
            $html .= '<option value="' . Str::e($option['id']) . '" dir="auto"' . (in_array($option['id'], $selected, true) ? ' selected' : '') . '>' . Str::e($option['label']) . '</option>';
        }
        $html .= '</select>';
        if ($multiple && ($c['jsonName'] ?? null) !== null) {
            // Used once the script runs (it keeps the chosen order); without JavaScript the select is sent.
            $html .= '<input type="hidden" name="' . Str::e((string) $c['jsonName']) . '" value="' . Str::e(json_encode($selected, JSON_THROW_ON_ERROR)) . '" data-relation-json disabled>';
        }
        if (!$c['complete'] && ($c['lookup'] ?? '') === '') {
            $html .= '<p class="fa-help fa-relation-more">' . Str::e(AdminLang::get('relations.first_only', ['count' => (string) RelationSource::PLAIN])) . '</p>';
        }
        return $html . '</div>';
    }

    /**
     * Options to send: every record when there are few, else the first ones by
     * title, plus the chosen ones (missing records shown as "#7 (missing)").
     *
     * @param list<string> $selected
     * @param array<string, string> $where
     * @return array{options: list<array{id: string, label: string}>, complete: bool}
     */
    public static function options(RelationSource $source, array $selected, array $where = [], bool $blocked = false, ?string $except = null): array
    {
        $db = \FloCMS\Core\App::db();
        if ($db === null) {
            return ['options' => array_map(static fn (string $id): array => ['id' => $id, 'label' => '#' . $id], $selected), 'complete' => false];
        }
        $options = [];
        $complete = false;
        if (!$blocked) {
            $first = $source->options($db, RelationSource::PLAIN + 1, $where, $except);
            $complete = count($first) <= RelationSource::PRELOAD;
            $options = array_slice($first, 0, RelationSource::PLAIN);
        }
        $listed = array_column($options, 'id');
        $missing = array_values(array_diff($selected, $listed));
        if ($missing !== []) {
            $titles = $source->titles($db, $missing);
            $extra = [];
            foreach ($missing as $id) {
                $extra[] = ['id' => $id, 'label' => $titles[$id] ?? AdminLang::get('relations.missing', ['id' => $id])];
            }
            // Chosen records first, in their order
            $options = array_merge($extra, $options);
        }
        return ['options' => $options, 'complete' => $complete];
    }

    /** @return array<string, string> */
    private static function strings(array $c): array
    {
        $t = static fn (string $key, array $replace = []): string => AdminLang::get('relations.' . $key, $replace);
        return [
            'search' => ($c['placeholder'] ?? null) ?: $t('search'), 'none' => $t('none'), 'loading' => $t('loading'),
            'failed' => $t('failed'), 'parent' => $t('parent_first', ['field' => $c['dependsLabel'] ?? '']),
            'remove' => $t('remove', ['label' => ':label']), 'clear' => $t('clear'), 'max' => $t('max', ['max' => (string) ($c['max'] ?? '')]),
            'more' => $t('more'), 'chosen' => $t('chosen', ['label' => ':label']), 'results' => $t('results', ['count' => ':count']),
        ];
    }

    /** @param array<string, string|bool|null> $attributes */
    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $key => $value) {
            if ($value === null || $value === false) { continue; }
            $html .= ' ' . $key . ($value === true ? '' : '="' . Str::e((string) $value) . '"');
        }
        return $html;
    }
}
