<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Support\AdminLang;

/**
 * Two to four columns of blocks (0.29), on the page or inside a Section. The
 * layout is chosen from pictures; the blocks of a column that goes away move
 * into the last remaining one.
 */
final class Columns extends Block
{
    protected static string $type = 'columns';
    protected static string $category = 'layout';
    protected static string $icon = 'columns';
    protected static ?string $label = 'blocks.columns';
    protected static ?string $description = 'blocks.columns_help';

    /** layout => the columns' shares of 12 */
    public const LAYOUTS = [
        '50-50' => [6, 6], '67-33' => [8, 4], '33-67' => [4, 8], '25-75' => [3, 9], '75-25' => [9, 3], '40-60' => [5, 7], '60-40' => [7, 5],
        '33-33-33' => [4, 4, 4], '25-50-25' => [3, 6, 3], '25-25-25-25' => [3, 3, 3, 3],
    ];

    public function nameField(): ?string { return 'name'; }

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        $labels = ['50-50' => '50 / 50', '67-33' => '67 / 33', '33-67' => '33 / 67', '25-75' => '25 / 75', '75-25' => '75 / 25', '40-60' => '40 / 60', '60-40' => '60 / 40',
            '33-33-33' => $t('layout_thirds'), '25-50-25' => '25 / 50 / 25', '25-25-25-25' => $t('layout_quarters')];
        return [
            TextInput::make('name')->label($t('section_name'))->help($t('section_name_help'))->rules('max:120')->columnSpan('full'),
            Select::make('layout')->label($t('columns_layout'))->options($labels)->default('50-50')->pictures(array_map([self::class, 'picture'], self::LAYOUTS))->columnSpan('full'),
            Select::make('gap')->label($t('columns_gap'))->options(['none' => $t('space_none'), 'small' => $t('space_small'), 'medium' => $t('space_medium'), 'large' => $t('space_large')])->default('medium')->segmented()->columnSpan('full'),
            Select::make('align')->label($t('columns_align'))->options(['top' => $t('align_top'), 'middle' => $t('align_middle'), 'bottom' => $t('align_bottom'), 'stretch' => $t('align_stretch')])->default('top')->segmented()->columnSpan('full'),
            Toggle::make('stack')->label($t('columns_stack'))->help($t('columns_stack_help'))->default('1')->columnSpan('full'),
            Toggle::make('reverse')->label($t('columns_reverse'))->help($t('columns_reverse_help'))->shownWhen('stack', '1')->columnSpan('full'),
        ];
    }

    /** A small drawing of a layout: one rounded bar per column, as wide as its share. */
    public static function picture(array $shares): string
    {
        $gap = 2; $width = 60; $x = 0.0;
        $usable = $width - $gap * (count($shares) - 1);
        $svg = '<svg viewBox="0 0 60 26" width="60" height="26">';
        foreach ($shares as $share) {
            $w = $usable * $share / 12;
            $svg .= '<rect x="' . round($x, 1) . '" y="0" width="' . round($w, 1) . '" height="26" rx="3"/>';
            $x += $w + $gap;
        }
        return $svg . '</svg>';
    }

    public function title(array $fields): string
    {
        $name = trim((string) ($fields['name'] ?? ''));
        if ($name !== '') { return mb_strimwidth($name, 0, 80, '…'); }
        return AdminLang::get('blocks.columns_count', ['count' => (string) $this->columnCount($fields)]);
    }

    public function holdsBlocks(): bool { return true; }

    public function columnCount(array $fields): int
    {
        return count(self::LAYOUTS[(string) ($fields['layout'] ?? '50-50')] ?? [6, 6]);
    }

    /** @return list<int> the columns' shares of 12 */
    public static function shares(string $layout): array
    {
        return self::LAYOUTS[$layout] ?? [6, 6];
    }

    public function allowedIn(string $parentType): bool { return $parentType === '' || $parentType === 'section'; }
}
