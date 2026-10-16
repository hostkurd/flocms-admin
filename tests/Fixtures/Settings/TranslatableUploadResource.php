<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Settings;

use FloCMS\Admin\Form\Fields\ImageUpload;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** A translatable upload is only allowed on settings pages. */
final class TranslatableUploadResource extends Resource
{
    protected static string $table = 'covers';
    protected static string $slug = 'covers';

    public function form(Form $form): Form
    {
        return $form->schema([ImageUpload::make('cover')->translatable()]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('id')]);
    }
}
