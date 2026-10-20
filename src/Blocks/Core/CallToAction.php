<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Support\AdminLang;

/** A heading, a line of text and a button on a coloured band (0.29). */
final class CallToAction extends Block
{
    protected static string $type = 'call_to_action';
    protected static string $category = 'marketing';
    protected static string $icon = 'arrow-right';
    protected static ?string $label = 'blocks.call_to_action';
    protected static ?string $description = 'blocks.call_to_action_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            TextInput::make('heading')->label($t('heading'))->required()->rules('max:160')->columnSpan('full'),
            Textarea::make('text')->label($t('text'))->rows(2)->rules('max:400')->columnSpan('full'),
            TextInput::make('button')->label($t('button_text'))->required()->rules('max:60'),
            TextInput::make('link')->label($t('button_link'))->required()->rules('max:500'),
            Select::make('style')->label($t('cta_style'))->options(['brand' => $t('style_brand'), 'light' => $t('style_light'), 'dark' => $t('style_dark')])->default('brand')->segmented()->columnSpan('full'),
        ];
    }
}
