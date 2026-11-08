<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Notifications;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** The posts that are drafts, with their number beside the sidebar item (0.41). */
final class DraftResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'drafts';
    protected static ?string $icon = 'file';
    protected static ?string $permissionPrefix = 'content.posts';

    public function navigationBadge(): int
    {
        return \FloCMS\Core\App::db()->table('posts')->where('status', '=', 'draft')->count();
    }

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('slug')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('slug')]);
    }
}
