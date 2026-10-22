<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\CodeEditor;
use FloCMS\Admin\Form\Fields\DateTime;
use FloCMS\Admin\Form\Fields\Hidden;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Support\AdminLang;

/**
 * The settings every block has (0.29), in the block panel beside the
 * structure (0.31) and in the stored JSON under "settings": a name and a note
 * for editors, whether and when it is shown, and (in the dialog's Display tab,
 * 0.32.1) its style and colours (0.33), on which screens, its width and
 * spacing, an anchor, an extra CSS class and its own CSS (0.33).
 */
final class BlockSettings
{
    /**
     * The settings' names. Those added in 0.33 start with "_" (as the editors'
     * name and note do) so they never clash with a block's own fields, which
     * may not: _style, _bg, _text and _css.
     */
    public const NAMES = ['_label', '_note', 'status', 'starts', 'ends', '_style', '_bg', '_text', 'width', 'space_above', 'space_below', 'space_above_phone', 'space_below_phone', 'devices', 'anchor', 'class', '_css', '_css_signed'];

    /** The spacing sizes (0.33: Extra large), set by the theme with --flo-space-sm … --flo-space-xl. */
    public const SIZES = ['none', 'small', 'medium', 'large', 'xlarge'];

    /** @return list<Field> */
    public static function fields(Block $block): array
    {
        $t = static fn (string $key, array $replace = []): string => AdminLang::get('blocks.' . $key, $replace);
        $space = [];
        foreach (self::SIZES as $size) { $space[$size] = $t('space_' . $size); }
        $default = self::defaultSpace($block);
        $fields = [
            // For editors only (0.31): the block's name in the structure and a note; never printed on the website.
            // Their names start with "_" so they never clash with a block's own fields.
            TextInput::make('_label')->label($t('label'))->help($t('label_help'))->rules('max:80')->columnSpan('full'),
            \FloCMS\Admin\Form\Fields\Textarea::make('_note')->label($t('note'))->help($t('note_help'))->rows(2)->rules('max:500')->columnSpan('full'),
            Select::make('status')->label($t('status'))->options(['published' => $t('status_published'), 'draft' => $t('status_draft')])->default('published')->segmented()->columnSpan('full'),
            DateTime::make('starts')->label($t('starts'))->help($t('starts_help')),
            DateTime::make('ends')->label($t('ends')),
        ];
        // Look (0.33): a style the theme defines, and a colour from its palette.
        $styles = BlockStyles::for($block);
        if ($styles !== []) {
            $style = Select::make('_style')->label($t('style'))->help($t('style_help'))->whenMissing($t('style_gone'))->columnSpan('full');
            // A few short styles as a row of buttons, more as a list.
            $fields[] = count($styles) <= 4 ? $style->options(['' => $t('style_default')] + $styles)->segmented() : $style->options($styles)->placeholder($t('style_default'));
        }
        // A Section has its own background (from the same palette).
        if (!$block instanceof Core\Section) {
            $colours = BlockColours::all();
            $fields[] = Select::make('_bg')->label($t('background'))->help($t('bg_help'))
                ->options(['' => $t('bg_none')] + array_map(static fn (array $colour): string => $colour['label'], $colours))
                ->swatches(['' => ''] + array_map(static fn (array $colour): string => $colour['value'], $colours));
            $fields[] = Select::make('_text')->label($t('text_colour'))->help($t('text_help'))
                ->options(['' => $t('tone_auto'), 'light' => $t('tone_light'), 'dark' => $t('tone_dark')])->segmented()
                ->shownWhen('_bg', array_map('strval', array_keys($colours)));
        }
        // A Section sets the width of its own content; blocks inside a Section or Columns follow it.
        if (!$block instanceof Core\Section) {
            $fields[] = Select::make('width')->label($t('width'))->options(['boxed' => $t('width_boxed'), 'full' => $t('width_full')])->default($block->defaultWidth() === 'full' ? 'full' : 'boxed')->segmented()->help($t('width_help'))->columnSpan('full');
        }
        $fields = array_merge($fields, [
            Select::make('devices')->label($t('devices'))->options(['all' => $t('devices_all'), 'desktop' => $t('devices_desktop'), 'phone' => $t('devices_phone')])->default('all')->segmented()->columnSpan('full'),
            // Spacing (0.33): "Default (Medium)" is saved as nothing, so the block follows its kind's default;
            // phones may have their own (full-width kinds, such as a slider, sit edge to edge by default).
            Select::make('space_above')->label($t('space_above'))->options($space)->placeholder($t('space_default', ['size' => $space[$default]])),
            Select::make('space_above_phone')->label($t('space_above_phone'))->options($space)->placeholder($t('same_as_desktop')),
            Select::make('space_below')->label($t('space_below'))->options($space)->placeholder($t('space_default', ['size' => $space[$default]])),
            Select::make('space_below_phone')->label($t('space_below_phone'))->options($space)->placeholder($t('same_as_desktop')),
            TextInput::make('anchor')->label($t('anchor'))->help($t('anchor_help'))->rules('max:60'),
            TextInput::make('class')->label($t('css_class'))->help($t('css_class_help'))->rules('max:120'),
        ]);
        // The block's own CSS (0.33): only for people with blocks.custom_css. For everyone
        // else it travels with the form as a signed copy, kept as it is (see acceptInput()).
        if (BlockCss::allowed()) {
            $fields[] = CodeEditor::make('_css')->language('css')->rows(8)->label($t('custom_css'))->help($t('custom_css_help'))->rules('max:' . BlockCss::MAX)->columnSpan('full');
        } else {
            $fields[] = Hidden::make('_css');
            $fields[] = Hidden::make('_css_signed');
        }
        return $fields;
    }

    /** The settings on their way into the form: without the permission, the CSS gets its signature. */
    public static function prepareState(array $state, string $id): array
    {
        if (!BlockCss::allowed()) { $state['_css_signed'] = \FloCMS\Admin\Form\Fields\Builder::sign((string) ($state['_css'] ?? ''), 'css|' . $id); }
        return $state;
    }

    /**
     * Submitted settings: without the permission the CSS is the signed copy (a
     * new or duplicated block has none); a style that no longer exists goes.
     */
    public static function acceptInput(Block $block, array $state, string $id): array
    {
        if (!BlockCss::allowed()) {
            $state['_css'] = \FloCMS\Admin\Form\Fields\Builder::unsign((string) ($state['_css_signed'] ?? ''), 'css|' . $id) ?? '';
        }
        $state['_css_signed'] = '';
        if (($state['_style'] ?? '') !== '' && !array_key_exists((string) $state['_style'], BlockStyles::for($block))) { $state['_style'] = ''; }
        return $state;
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
