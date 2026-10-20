<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Support\AdminLang;

/** A large image or video with a heading, text and up to two buttons (0.29). */
final class Hero extends Block
{
    protected static string $type = 'hero';
    protected static string $category = 'media';
    protected static string $icon = 'hero';
    protected static ?string $label = 'blocks.hero';
    protected static ?string $description = 'blocks.hero_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            MediaPicker::make('media')->label($t('hero_media'))->any()->columnSpan('full'),
            TextInput::make('heading')->label($t('heading'))->required()->rules('max:160')->columnSpan('full'),
            Textarea::make('text')->label($t('text'))->rows(3)->rules('max:600')->columnSpan('full'),
            TextInput::make('button')->label($t('button_text'))->rules('max:60'),
            TextInput::make('link')->label($t('button_link'))->rules('max:500'),
            TextInput::make('button2')->label($t('button2_text'))->rules('max:60'),
            TextInput::make('link2')->label($t('button2_link'))->rules('max:500'),
            Select::make('height')->label($t('hero_height'))->options(['screen' => $t('height_screen'), 'large' => $t('size_large'), 'medium' => $t('size_medium')])->default('large')->segmented()->columnSpan('full'),
            Select::make('align')->label($t('align'))->options(['start' => $t('align_start'), 'center' => $t('align_center')])->default('start')->segmented(),
            Select::make('overlay')->label($t('slider_overlay'))->options(['0' => '0%', '20' => '20%', '35' => '35%', '50' => '50%', '65' => '65%', '80' => '80%'])->default('35'),
        ];
    }
}
