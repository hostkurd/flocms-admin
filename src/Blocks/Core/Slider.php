<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Repeater;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Support\AdminLang;

/**
 * A slider of images or videos (0.29): full screen, a fixed height, or the
 * shape of its first image. Its slides come from the media library.
 */
final class Slider extends Block
{
    protected static string $type = 'slider';
    protected static string $category = 'media';
    protected static string $icon = 'slides';
    protected static ?string $label = 'blocks.slider';
    protected static ?string $description = 'blocks.slider_help';

    public const MAX_SLIDES = 20;

    public function nameField(): ?string { return 'name'; }

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            Tab::make('content')->label($t('tab_content'))->columns(2)->schema([
                TextInput::make('name')->label($t('slider_name'))->help($t('slider_name_help'))->rules('max:120')->columnSpan('full'),
                Select::make('height')->label($t('slider_height'))->options(['screen' => $t('height_screen'), 'fixed' => $t('height_fixed'), 'image' => $t('height_image')])->default('screen')->segmented()->columnSpan('full'),
                TextInput::make('height_px')->label($t('height_px'))->numeric()->rules('integer|min:120|max:1600')->default('560')->shownWhen('height', 'fixed'),
                TextInput::make('height_phone')->label($t('height_phone'))->numeric()->rules('integer|min:120|max:1200')->default('420')->shownWhen('height', 'fixed'),
                Select::make('effect')->label($t('slider_effect'))->options(['fade' => $t('effect_fade'), 'slide' => $t('effect_slide')])->default('fade')->segmented(),
                Toggle::make('autoplay')->label($t('slider_autoplay'))->default('1'),
                Select::make('interval')->label($t('slider_interval'))->options(['4' => '4 s', '6' => '6 s', '8' => '8 s', '10' => '10 s'])->default('6')->shownWhen('autoplay', '1'),
                Toggle::make('arrows')->label($t('slider_arrows'))->default('1'),
                Toggle::make('dots')->label($t('slider_dots'))->default('1'),
                Select::make('fit')->label($t('slider_fit'))->options(['cover' => $t('fit_cover'), 'contain' => $t('fit_contain')])->default('cover')->segmented(),
                Select::make('overlay')->label($t('slider_overlay'))->help($t('overlay_help'))->options(['0' => '0%', '20' => '20%', '35' => '35%', '50' => '50%', '65' => '65%', '80' => '80%'])->default('35'),
            ]),
            Tab::make('slides')->label($t('tab_slides'))->columns(1)->schema([
                self::slides(Repeater::make('slides')->label($t('tab_slides'))),
            ]),
        ];
    }

    /**
     * The slides list (also the Slider field's own): media from the library,
     * an optional heading, text and button, where the text sits and its colour.
     */
    public static function slides(Repeater $repeater): Repeater
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return $repeater->schema([
            MediaPicker::make('media')->label($t('slide_media'))->any()->required()->columnSpan('full'),
            TextInput::make('heading')->label($t('slide_heading'))->rules('max:160')->columnSpan('full'),
            Textarea::make('text')->label($t('slide_text'))->rows(3)->rules('max:600')->columnSpan('full'),
            TextInput::make('button')->label($t('button_text'))->rules('max:60'),
            TextInput::make('link')->label($t('button_link'))->rules('max:500'),
            Toggle::make('new_tab')->label($t('new_tab'))->columnSpan('full'),
            Select::make('position')->label($t('text_position'))->positions()->default('bottom-start'),
            Select::make('tone')->label($t('text_colour'))->options(['light' => $t('tone_light'), 'dark' => $t('tone_dark')])->default('light')->segmented(),
            Toggle::make('shown')->label($t('slide_shown'))->help($t('slide_shown_help'))->default('1')->columnSpan('full'),
        ])->itemLabel('heading')->addLabel($t('slide_add'))->collapsed()->minItems(1)->maxItems(self::MAX_SLIDES)->columns(2);
    }

    public function title(array $fields): string
    {
        $name = trim((string) ($fields['name'] ?? ''));
        return $name !== '' ? mb_strimwidth($name, 0, 80, '…') : AdminLang::get('blocks.slider');
    }

    public function assets(): array { return ['slider']; }

    /** Each slide's button and link need each other (0.32). */
    public function check(array $fields): array
    {
        $errors = [];
        foreach (self::slides(Repeater::make('slides'))->items($fields['slides'] ?? '') as $index => $slide) {
            $errors += self::buttonPair($slide, 'button', 'link', 'slides.' . $index . '.');
        }
        return $errors;
    }

    public function defaultWidth(): string { return 'full'; }

    /** The template gets the slides that are shown, each with its media item. */
    public function data(array $fields): array
    {
        return ['slides' => self::shownSlides($fields['slides'] ?? [])];
    }

    /**
     * Slides as the template uses them: shown ones only, with "item" (the
     * media item, or null) next to the stored "media" id.
     *
     * @return list<array<string, mixed>>
     */
    public static function shownSlides(mixed $value): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        $slides = [];
        foreach (is_array($value) ? $value : [] as $slide) {
            if (!is_array($slide) || (string) ($slide['shown'] ?? '1') === '0') { continue; }
            unset($slide['_key']);
            $slide['item'] = function_exists('media') ? media($slide['media'] ?? null) : null;
            if ($slide['item'] === null) { continue; }
            $slides[] = $slide + ['heading' => '', 'text' => '', 'button' => '', 'link' => '', 'position' => 'bottom-start', 'tone' => 'light'];
        }
        return $slides;
    }
}
