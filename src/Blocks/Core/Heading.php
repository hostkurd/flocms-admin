<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Support\AdminLang;

/** A title, H1 to H4 (0.29). */
final class Heading extends Block
{
    protected static string $type = 'heading';
    protected static string $category = 'text';
    protected static string $icon = 'heading';
    protected static ?string $label = 'blocks.heading_block';
    protected static ?string $description = 'blocks.heading_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            TextInput::make('text')->label($t('heading'))->required()->rules('max:200')->columnSpan('full'),
            Select::make('level')->label($t('heading_level'))->options(['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4'])->default('h2')->segmented(),
            Select::make('align')->label($t('align'))->options(['start' => $t('align_start'), 'center' => $t('align_center'), 'end' => $t('align_end')])->default('start')->segmented(),
            TextInput::make('subtitle')->label($t('subtitle'))->help($t('subtitle_help'))->rules('max:300')->columnSpan('full'),
        ];
    }
}
