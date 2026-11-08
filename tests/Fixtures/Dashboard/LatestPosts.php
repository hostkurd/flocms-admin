<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Dashboard;
use FloCMS\Admin\Table\Columns\{BadgeColumn, TextColumn};
use FloCMS\Admin\Tests\Fixtures\Widgets\WidgetPostResource;
use FloCMS\Admin\Widgets\RecordsWidget;
final class LatestPosts extends RecordsWidget
{
    protected string $title = 'Latest posts';
    protected string $resource = WidgetPostResource::class;
    protected int|string $columnSpan = 2;
    protected function columns(): array { return [TextColumn::make('title')->limit(40), BadgeColumn::make('status'), TextColumn::make('views')]; }
}
