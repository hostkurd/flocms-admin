<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\ColorPicker;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Support\AdminLang;

/**
 * A band across the page that holds other blocks (0.29): a background,
 * spacing, and the width of its content (full, boxed in the site's
 * container, or narrow for reading). Sections sit at the top level only.
 */
final class Section extends Block
{
    protected static string $type = 'section';
    protected static string $category = 'layout';
    protected static string $icon = 'section';
    protected static ?string $label = 'blocks.section';
    protected static ?string $description = 'blocks.section_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            TextInput::make('name')->label($t('section_name'))->help($t('section_name_help'))->rules('max:120')->columnSpan('full'),
            Select::make('width')->label($t('content_width'))->options(['full' => $t('width_full'), 'boxed' => $t('width_boxed'), 'narrow' => $t('width_narrow')])->default('boxed')->segmented()->columnSpan('full'),
            Select::make('background')->label($t('background'))->options(['none' => $t('bg_none'), 'light' => $t('bg_light'), 'dark' => $t('bg_dark'), 'brand' => $t('bg_brand'), 'colour' => $t('bg_colour'), 'image' => $t('bg_image')])->default('none')->columnSpan('full'),
            ColorPicker::make('colour')->label($t('bg_colour'))->help($t('bg_colour_help')),
            MediaPicker::make('image')->label($t('bg_image'))->help($t('bg_image_help')),
            Select::make('padding')->label($t('padding'))->options(['none' => $t('space_none'), 'small' => $t('space_small'), 'medium' => $t('space_medium'), 'large' => $t('space_large')])->default('large')->segmented()->columnSpan('full'),
        ];
    }

    public function title(array $fields): string
    {
        $name = trim((string) ($fields['name'] ?? ''));
        return $name !== '' ? mb_strimwidth($name, 0, 80, '…') : AdminLang::get('blocks.section');
    }

    public function holdsBlocks(): bool { return true; }

    public function allowedIn(string $parentType): bool { return $parentType === ''; }
}
