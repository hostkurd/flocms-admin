<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Repeater;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Support\AdminLang;

/** Cards in a grid: image, title, text and link each (0.29). */
final class Cards extends Block
{
    protected static string $type = 'cards';
    protected static string $category = 'text';
    protected static string $icon = 'cards';
    protected static ?string $label = 'blocks.cards';
    protected static ?string $description = 'blocks.cards_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            Tab::make('content')->label($t('tab_content'))->columns(2)->schema([
                TextInput::make('heading')->label($t('heading'))->rules('max:160')->columnSpan('full'),
                Select::make('columns')->label($t('cards_columns'))->options(['2' => '2', '3' => '3', '4' => '4'])->default('3')->segmented(),
            ]),
            Tab::make('cards')->label($t('tab_cards'))->columns(1)->schema([
                Repeater::make('cards')->label($t('tab_cards'))->schema([
                    MediaPicker::make('media')->label($t('image'))->columnSpan('full'),
                    TextInput::make('title')->label($t('card_title'))->required()->rules('max:160')->columnSpan('full'),
                    Textarea::make('text')->label($t('text'))->rows(3)->rules('max:600')->columnSpan('full'),
                    TextInput::make('link')->label($t('link'))->rules('max:500')->columnSpan('full'),
                ])->itemLabel('title')->addLabel($t('card_add'))->collapsed()->minItems(1)->maxItems(24),
            ]),
        ];
    }
}
