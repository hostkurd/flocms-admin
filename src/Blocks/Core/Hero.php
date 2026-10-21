<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Support\AdminLang;

/** A large image or video with a heading, text and up to two buttons (0.29). */
final class Hero extends Block
{
    protected static string $type = 'hero';
    protected static string $category = 'marketing';
    protected static string $icon = 'hero';
    protected static ?string $label = 'blocks.hero';
    protected static ?string $description = 'blocks.hero_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            MediaPicker::make('media')->label($t('hero_media'))->any()->columnSpan('full'),
            TextInput::make('heading')->label($t('heading'))->required()->rules('max:160')->columnSpan('full'),
            Select::make('level')->label($t('heading_level'))->help($t('hero_level_help'))->options(['h1' => 'H1', 'h2' => 'H2'])->default('h1')->segmented()->columnSpan('full'),
            Textarea::make('text')->label($t('text'))->rows(3)->rules('max:600')->columnSpan('full'),
            TextInput::make('button')->label($t('button_text'))->rules('max:60'),
            TextInput::make('link')->label($t('button_link'))->rules('max:500'),
            TextInput::make('button2')->label($t('button2_text'))->rules('max:60'),
            TextInput::make('link2')->label($t('button2_link'))->rules('max:500'),
            Toggle::make('new_tab')->label($t('new_tab'))->columnSpan('full'),
            Select::make('height')->label($t('hero_height'))->options(['screen' => $t('height_screen'), 'large' => $t('size_large'), 'medium' => $t('size_medium')])->default('large')->segmented()->columnSpan('full'),
            Select::make('height_phone')->label($t('height_on_phones'))->options(['same' => $t('same_as_desktop'), 'medium' => $t('size_medium'), 'small' => $t('size_small')])->default('same')->segmented()->columnSpan('full'),
            Select::make('align')->label($t('align'))->options(['start' => $t('align_start'), 'center' => $t('align_center')])->default('start')->segmented(),
            Select::make('overlay')->label($t('slider_overlay'))->help($t('overlay_help'))->options(['0' => '0%', '20' => '20%', '35' => '35%', '50' => '50%', '65' => '65%', '80' => '80%'])->default('35'),
        ];
    }

    public function defaultWidth(): string { return 'full'; }

    /** A background video stops for visitors who ask for less motion (0.32). */
    public function assets(): array { return ['motion']; }

    /** Each button and its link need each other (0.32). */
    public function check(array $fields): array
    {
        return self::buttonPair($fields, 'button', 'link') + self::buttonPair($fields, 'button2', 'link2');
    }
}
