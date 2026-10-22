<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\Repeater;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Support\AdminLang;

/**
 * Big numbers with labels (0.34): "250+ projects". The number is always in
 * the HTML; stats.js counts up to it once it is in view (not with "reduce
 * motion") and writes it in the page's digits.
 */
final class Stats extends Block
{
    protected static string $type = 'stats';
    protected static string $category = 'marketing';
    protected static string $icon = 'chart';
    protected static ?string $label = 'blocks.stats';
    protected static ?string $description = 'blocks.stats_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            Tab::make('content')->label($t('tab_content'))->columns(2)->schema([
                TextInput::make('heading')->label($t('heading'))->rules('max:160')->columnSpan('full'),
                Textarea::make('intro')->label($t('intro'))->rows(2)->rules('max:400')->columnSpan('full'),
                Select::make('columns')->label($t('per_row'))->options(['2' => '2', '3' => '3', '4' => '4'])->default('4')->segmented(),
                Select::make('align')->label($t('align'))->options(['start' => $t('align_start'), 'center' => $t('align_center')])->default('start')->segmented(),
                Toggle::make('dividers')->label($t('stats_dividers'))->default('1'),
                Toggle::make('count_up')->label($t('stats_count_up'))->help($t('stats_count_up_help'))->default('1'),
            ]),
            Tab::make('numbers')->label($t('stats_numbers'))->columns(1)->schema([
                Repeater::make('items')->label($t('stats_numbers'))->schema([
                    TextInput::make('prefix')->label($t('stats_prefix'))->placeholder('$')->rules('max:6'),
                    TextInput::make('number')->label($t('stats_number'))->placeholder('250')->required()->rules('max:16'),
                    TextInput::make('suffix')->label($t('stats_suffix'))->placeholder('+')->rules('max:10'),
                    TextInput::make('label')->label($t('stats_label'))->required()->rules('max:120')->columnSpan('full'),
                ])->itemLabel('label')->addLabel($t('stats_add'))->minItems(1)->maxItems(8),
            ]),
        ];
    }

    public function title(array $fields): string
    {
        $heading = trim((string) ($fields['heading'] ?? ''));
        return $heading !== '' ? mb_strimwidth($heading, 0, 80, '…') : self::label();
    }

    /** "250", "98.5", "98,5", "1,200", "1 200.5": digits with a decimal part; null otherwise. */
    public static function number(string $value): ?string
    {
        $value = str_replace(' ', '', trim($value));
        // Thousands with commas ("1,250,000"), or a decimal comma ("98,5")
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d{1,3})?$/', $value) === 1) { $value = str_replace(',', '', $value); }
        elseif (preg_match('/^-?\d+,\d{1,2}$/', $value) === 1) { $value = str_replace(',', '.', $value); }
        return preg_match('/^-?\d{1,12}(\.\d{1,3})?$/', $value) === 1 ? $value : null;
    }

    public function check(array $fields): array
    {
        $errors = [];
        foreach (repeater_items($fields['items'] ?? '') as $index => $item) {
            $number = trim((string) ($item['number'] ?? ''));
            if ($number !== '' && self::number($number) === null) { $errors['items.' . $index . '.number'] = AdminLang::get('blocks.stats_number_invalid'); }
        }
        return $errors;
    }

    public function data(array $fields): array
    {
        $items = [];
        foreach (repeater_items($fields['items'] ?? []) as $item) {
            $number = self::number((string) ($item['number'] ?? ''));
            if ($number === null) { continue; }
            $items[] = ['prefix' => (string) ($item['prefix'] ?? ''), 'number' => $number, 'suffix' => (string) ($item['suffix'] ?? ''),
                'label' => (string) ($item['label'] ?? ''), 'decimals' => str_contains($number, '.') ? strlen($number) - (int) strpos($number, '.') - 1 : 0];
        }
        return ['numbers' => $items];
    }

    public function assets(): array { return ['stats']; }
}
