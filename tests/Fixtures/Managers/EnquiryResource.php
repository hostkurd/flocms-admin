<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Managers;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

final class EnquiryResource extends Resource
{
    protected static string $table = 'enquiries';
    protected static string $slug = 'enquiries';
    protected static string $label = 'Enquiry';
    protected static string $pluralLabel = 'Enquiries';
    protected static ?string $icon = 'message';

    public function form(Form $form): Form { return $form->schema([TextInput::make('name'), TextInput::make('email'), TextInput::make('estate_id')]); }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('email')]);
    }
}
