<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Dashboard;
use FloCMS\Admin\Tests\Fixtures\Widgets\WidgetPostResource;
use FloCMS\Admin\Widgets\{ChartWidget, WidgetContext};
/** A donut from countBy() (0.42). */
final class PostStatuses extends ChartWidget
{
    protected string $title = 'Posts by status';
    protected array $resources = [WidgetPostResource::class];
    protected string $type = 'donut';
    protected function data(WidgetContext $context): array
    {
        $counts = $context->query(WidgetPostResource::class)->countBy('status');
        return ['labels' => array_map('ucfirst', array_map('strval', array_keys($counts))), 'values' => array_values($counts), 'total' => 'posts'];
    }
}
