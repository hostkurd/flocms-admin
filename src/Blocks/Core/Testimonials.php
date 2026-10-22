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

/** Quotes from customers (0.34): a grid, a carousel (the slider's script) or one large quote. */
final class Testimonials extends Block
{
    protected static string $type = 'testimonials';
    protected static string $category = 'marketing';
    protected static string $icon = 'quote';
    protected static ?string $label = 'blocks.testimonials';
    protected static ?string $description = 'blocks.testimonials_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            Tab::make('content')->label($t('tab_content'))->columns(2)->schema([
                TextInput::make('heading')->label($t('heading'))->rules('max:160')->columnSpan('full'),
                Textarea::make('intro')->label($t('intro'))->rows(2)->rules('max:400')->columnSpan('full'),
                Select::make('layout')->label($t('testimonials_layout'))->options(['grid' => $t('layout_grid'), 'carousel' => $t('layout_carousel'), 'single' => $t('layout_single')])->default('grid')->segmented()->columnSpan('full'),
                Select::make('columns')->label($t('per_row'))->options(['1' => '1', '2' => '2', '3' => '3'])->default('3')->segmented()->shownWhen('layout', 'grid'),
            ]),
            Tab::make('quotes')->label($t('testimonials_quotes'))->columns(1)->schema([
                Repeater::make('items')->label($t('testimonials_quotes'))->schema([
                    Textarea::make('quote')->label($t('testimonials_quote'))->rows(3)->required()->rules('max:600')->columnSpan('full'),
                    TextInput::make('name')->label($t('person_name'))->required()->rules('max:120'),
                    TextInput::make('role')->label($t('person_role'))->rules('max:160'),
                    MediaPicker::make('photo')->label($t('photo')),
                    Select::make('rating')->label($t('testimonials_rating'))->options(['5' => '★★★★★', '4' => '★★★★', '3' => '★★★', '2' => '★★', '1' => '★'])->placeholder($t('testimonials_no_rating')),
                ])->itemLabel('name')->addLabel($t('testimonials_add'))->collapsed()->minItems(1)->maxItems(24),
            ]),
        ];
    }

    public function title(array $fields): string
    {
        $heading = trim((string) ($fields['heading'] ?? ''));
        return $heading !== '' ? mb_strimwidth($heading, 0, 80, '…') : self::label();
    }

    /** "Sara Ahmed" → "SA" (letters of any script). */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice(array_values(array_filter($words)), 0, 2));
        return implode('', $letters);
    }

    public function data(array $fields): array
    {
        $items = [];
        foreach (repeater_items($fields['items'] ?? []) as $item) {
            if (trim((string) ($item['quote'] ?? '')) === '') { continue; }
            $rating = max(0, min(5, (int) ($item['rating'] ?? 0)));
            $items[] = $item + ['initials' => self::initials((string) ($item['name'] ?? '')), 'stars' => $rating,
                'rated' => $rating > 0 ? AdminLang::get('blocks.site_rated', ['stars' => (string) $rating]) : ''];
        }
        return ['quotes' => $items, 'layout' => in_array($fields['layout'] ?? '', ['grid', 'carousel', 'single'], true) ? (string) $fields['layout'] : 'grid'];
    }

    public function assets(): array { return ['slider']; }
}
