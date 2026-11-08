<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Dashboard;
use FloCMS\Admin\Tests\Fixtures\Widgets\WidgetPostResource;
use FloCMS\Admin\Widgets\{ListWidget, WidgetContext};
/** Needs attention: zero items are left out (0.42). */
final class Attention extends ListWidget
{
    protected string $title = 'Needs attention';
    protected array $resources = [WidgetPostResource::class];
    protected function items(WidgetContext $context): array
    {
        $posts = $context->query(WidgetPostResource::class);
        return [
            ['icon' => 'pencil', 'color' => 'warning', 'title' => 'Draft posts', 'description' => 'Not on the website yet', 'value' => $posts->where('status', '=', 'draft')->count(), 'url' => $context->url('widget-posts')],
            ['icon' => 'user-x', 'color' => 'danger', 'title' => 'Posts without a title', 'description' => 'Hidden: there are none', 'value' => $posts->where('title', '=', '')->count()],
            ['icon' => 'hourglass', 'color' => 'chart2', 'title' => 'Waiting more than a week', 'description' => 'Still a draft after 7 days', 'value' => '1'],
        ];
    }
}
