<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Resources;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{RichEditor, TextInput};
use FloCMS\Admin\Table\{Table, Columns\TextColumn};

/** Rich text: a translatable body with media, and a short single-language note. */
final class ArticleResource extends Resource
{
    protected static string $table = 'articles';
    protected static string $slug = 'articles';
    // The browser tests drive these records in the list dialog.
    protected static string $formMode = 'modal';
    protected static ?string $icon = 'newspaper';
    protected static string $label = 'Article';
    protected static string $pluralLabel = 'Articles';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->translatable()->required()->rules('max:190')->columnSpan('full'),
            RichEditor::make('body')->translatable()->required()->maxImageSize(2048)->maxVideoSize(8),
            RichEditor::make('note')->toolbar(['bold', 'italic', 'link', 'bulletList'])->nullable()->help('Internal note, English only.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable()->sortable()])->defaultSort('id', 'desc');
    }
}
