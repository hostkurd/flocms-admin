<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\DateTime;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Support\AdminLang;

/**
 * The settings every block has (0.29), in the block panel beside the
 * structure (0.31) and in the stored JSON under "settings": a name and a note
 * for editors, whether and when it is shown, on which screens, and (under
 * Advanced) its width and spacing, an anchor and an extra CSS class.
 */
final class BlockSettings
{
    public const NAMES = ['_label', '_note', 'status', 'starts', 'ends', 'width', 'space_above', 'space_below', 'devices', 'anchor', 'class'];

    /** @return list<Field> */
    public static function fields(Block $block): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        $space = ['none' => $t('space_none'), 'small' => $t('space_small'), 'medium' => $t('space_medium'), 'large' => $t('space_large')];
        $fields = [
            // For editors only (0.31): the block's name in the structure and a note; never printed on the website.
            // Their names start with "_" so they never clash with a block's own fields.
            TextInput::make('_label')->label($t('label'))->help($t('label_help'))->rules('max:80')->columnSpan('full'),
            \FloCMS\Admin\Form\Fields\Textarea::make('_note')->label($t('note'))->help($t('note_help'))->rows(2)->rules('max:500')->columnSpan('full'),
            Select::make('status')->label($t('status'))->options(['published' => $t('status_published'), 'draft' => $t('status_draft')])->default('published')->segmented()->columnSpan('full'),
            DateTime::make('starts')->label($t('starts'))->help($t('starts_help')),
            DateTime::make('ends')->label($t('ends')),
        ];
        // A Section sets the width of its own content; blocks inside a Section or Columns follow it.
        if (!$block instanceof Core\Section) {
            $fields[] = Select::make('width')->label($t('width'))->options(['boxed' => $t('width_boxed'), 'full' => $t('width_full')])->default($block->defaultWidth() === 'full' ? 'full' : 'boxed')->segmented()->help($t('width_help'))->columnSpan('full');
        }
        return array_merge($fields, [
            // Full-width kinds (slider, hero) sit edge to edge by default.
            Select::make('space_above')->label($t('space_above'))->options($space)->default(self::defaultSpace($block)),
            Select::make('space_below')->label($t('space_below'))->options($space)->default(self::defaultSpace($block)),
            Select::make('devices')->label($t('devices'))->options(['all' => $t('devices_all'), 'desktop' => $t('devices_desktop'), 'phone' => $t('devices_phone')])->default('all')->segmented()->columnSpan('full'),
            TextInput::make('anchor')->label($t('anchor'))->help($t('anchor_help'))->rules('max:60'),
            TextInput::make('class')->label($t('css_class'))->help($t('css_class_help'))->rules('max:120'),
        ]);
    }

    public static function defaultSpace(Block $block): string
    {
        return $block->defaultWidth() === 'full' ? 'none' : 'medium';
    }

    /** An anchor as it may appear in an id ("projects"), or ''. */
    public static function anchor(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($value)) ?? '';
        $value = trim($value, '-');
        return $value !== '' && preg_match('/^[A-Za-z]/', $value) === 1 ? substr($value, 0, 60) : '';
    }

    /** Extra CSS classes, letters, digits, - and _ only. */
    public static function classes(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $parts = array_filter($parts, static fn (string $part): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,40}$/D', $part) === 1);
        return implode(' ', array_slice(array_values($parts), 0, 6));
    }
}
