<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Widgets;
use FloCMS\Admin\Widgets\RecordsWidget;
use FloCMS\Admin\Table\Columns\{TextColumn, BadgeColumn};
final class RecentPosts extends RecordsWidget
{
    protected string $title = 'Recent posts';
    protected string $resource = WidgetPostResource::class;
    protected int $limit = 3;
    protected function columns(): array { return [TextColumn::make('title')->limit(70), BadgeColumn::make('status')->colors(['draft'=>'gray','published'=>'green'])]; }
}
