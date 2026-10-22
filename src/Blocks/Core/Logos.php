<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Repeater;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Support\AdminLang;

/** Client or partner logos in a grid or a row (0.34), greyscale until hovered. */
final class Logos extends Block
{
    protected static string $type = 'logos';
    protected static string $category = 'marketing';
    protected static string $icon = 'layers';
    protected static ?string $label = 'blocks.logos';
    protected static ?string $description = 'blocks.logos_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            Tab::make('content')->label($t('tab_content'))->columns(2)->schema([
                TextInput::make('heading')->label($t('heading'))->help($t('logos_heading_help'))->rules('max:160')->columnSpan('full'),
                Select::make('layout')->label($t('logos_layout'))->options(['grid' => $t('layout_grid'), 'row' => $t('layout_row')])->default('grid')->segmented(),
                Select::make('columns')->label($t('per_row'))->options(['3' => '3', '4' => '4', '5' => '5', '6' => '6'])->default('5')->segmented()->shownWhen('layout', 'grid'),
                Select::make('size')->label($t('logos_height'))->options(['small' => $t('size_small'), 'medium' => $t('size_medium'), 'large' => $t('size_large')])->default('medium')->segmented(),
                Toggle::make('greyscale')->label($t('logos_greyscale'))->default('1'),
                Toggle::make('new_tab')->label($t('new_tab')),
            ]),
            Tab::make('logos')->label($t('logos_list'))->columns(1)->schema([
                Repeater::make('logos')->label($t('logos_list'))->schema([
                    MediaPicker::make('image')->label($t('logos_image'))->required()->columnSpan('full'),
                    TextInput::make('name')->label($t('logos_name'))->help($t('logos_name_help'))->required()->rules('max:120'),
                    TextInput::make('link')->label($t('link'))->rules('max:500'),
                ])->itemLabel('name')->addLabel($t('logos_add'))->collapsed()->minItems(1)->maxItems(40),
            ]),
        ];
    }

    public function title(array $fields): string
    {
        $heading = trim((string) ($fields['heading'] ?? ''));
        return $heading !== '' ? mb_strimwidth($heading, 0, 80, '…') : self::label();
    }
}
