<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Resources;

use FloCMS\Admin\Form\Fields\Date;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\Slug;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\BadgeColumn;
use FloCMS\Admin\Table\Columns\BooleanColumn;
use FloCMS\Admin\Table\Columns\DateColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

class PostResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'posts';
    // The browser tests drive these records in the list dialog.
    protected static string $formMode = 'modal';
    protected static bool $timestamps = true;
    protected static string $label = 'Post';
    protected static string $pluralLabel = 'Posts';
    protected static ?string $icon = 'file';
    protected static int $navigationSort = 1;

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->translatable()->rules('required|max:20'),
            Slug::make('slug')->from('title')->unique()->rules('required|max:60'),
            Textarea::make('body')->translatable(),
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->required(),
            TextInput::make('views')->numeric()->rules('integer|min:0|max:1000'),
            Toggle::make('featured'),
            Date::make('published_on'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('slug')->searchable(),
            BadgeColumn::make('status')->colors(['published' => 'green'])->labels(['draft' => 'Draft', 'published' => 'Published']),
            BooleanColumn::make('featured'),
            DateColumn::make('updated_at')->sortable(),
        ])->defaultSort('id', 'desc')->perPage(3);
    }
}
