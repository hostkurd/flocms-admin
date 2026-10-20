<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\View;

use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\Slug;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\BadgeColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** The posts table with the view page first ($rowOpens = 'view'), a password, a hidden and a formatted field (0.27). */
final class ViewPostResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'notes';
    protected static string $label = 'Note';
    protected static string $pluralLabel = 'Notes';
    protected static string $rowOpens = 'view';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->translatable(),
            Slug::make('slug')->from('title')->hiddenOnView(),
            Textarea::make('body')->translatable(),
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
            TextInput::make('views')->numeric()->formatOnView(static fn ($value): string => $value === '' ? '' : $value . ' views'),
            TextInput::make('secret')->password()->default('never-shown'),
            Toggle::make('featured'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            BadgeColumn::make('status')->colors(['published' => 'green'])->labels(['draft' => 'Draft', 'published' => 'Published']),
        ]);
    }

    public function canView(object $record): bool { return (int) $record->id !== 2; }
}
