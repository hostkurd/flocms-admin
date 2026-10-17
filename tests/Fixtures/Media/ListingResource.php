<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Media;

use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\MediaColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** A listing with a cover, a gallery, a video and rich text, all from the media library. */
final class ListingResource extends Resource
{
    protected static string $table = 'listings';
    protected static string $slug = 'listings';
    protected static string $label = 'Listing';
    protected static string $pluralLabel = 'Listings';
    protected static ?string $icon = 'home';
    protected static string $formMode = 'page';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required(),
            MediaPicker::make('cover_id')->label('Cover')->folder('Listings/Covers')->aspectRatio('16:9'),
            MediaPicker::make('gallery')->label('Gallery')->multiple()->max(3),
            MediaPicker::make('video_id')->label('Video')->videos(),
            RichEditor::make('body')->label('Body')->translatable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            MediaColumn::make('cover_id')->label('Cover'),
            MediaColumn::make('gallery')->label('Gallery')->limit(2),
        ])->defaultSort('id', 'asc');
    }
}
