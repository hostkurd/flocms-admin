<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Support\AdminLang;

/** Paragraphs, lists and links in the panel's rich text editor (0.29). */
final class RichText extends Block
{
    protected static string $type = 'rich_text';
    protected static string $category = 'text';
    protected static string $icon = 'text';
    protected static ?string $label = 'blocks.rich_text';
    protected static ?string $description = 'blocks.rich_text_help';

    public function fields(): array
    {
        return [
            RichEditor::make('body')->label(AdminLang::get('blocks.text'))->required()->columnSpan('full'),
        ];
    }
}
