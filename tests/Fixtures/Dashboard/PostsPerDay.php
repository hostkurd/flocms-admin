<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Dashboard;
use FloCMS\Admin\Tests\Fixtures\Widgets\WidgetPostResource;
use FloCMS\Admin\Widgets\{ChartWidget, WidgetContext};
/** A line with the previous period, by the dashboard's period (0.42). */
final class PostsPerDay extends ChartWidget
{
    protected string $title = 'Posts per day';
    protected array $resources = [WidgetPostResource::class];
    protected string $type = 'line';
    protected int|string $columnSpan = 2;
    public function subtitle(WidgetContext $context): string { return $context->period()->label(); }
    protected function data(WidgetContext $context): array
    {
        $posts = $context->query(WidgetPostResource::class);
        $period = $context->period(); $previous = $period->previous();
        $now = $period->series($posts->countByDay('created_at', $period->from(), $period->to()));
        $before = $previous->series($posts->countByDay('created_at', $previous->from(), $previous->to()));
        return $now + ['previous' => array_slice(array_pad($before['values'], count($now['values']), 0), 0, count($now['values'])), 'label' => 'This period', 'previousLabel' => 'Previous period'];
    }
}
