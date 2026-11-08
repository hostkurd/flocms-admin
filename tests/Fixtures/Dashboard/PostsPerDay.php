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
    public function headerSubtitle(WidgetContext $context): string { return $context->period()->label(); }
    public function headerLink(WidgetContext $context): ?array { return ['label' => 'Open posts', 'url' => $context->url('widget-posts')]; }
    protected function data(WidgetContext $context): array
    {
        $posts = $context->query(WidgetPostResource::class);
        $period = $context->period(); $previous = $period->previous();
        $now = $period->series($posts->countByDay('created_at', $period->from(), $period->to()));
        $before = $previous->series($posts->countByDay('created_at', $previous->from(), $previous->to()), like: $period);
        return $now + ['previous' => $before['values'], 'label' => 'This period', 'previousLabel' => 'Previous period'];
    }
}
