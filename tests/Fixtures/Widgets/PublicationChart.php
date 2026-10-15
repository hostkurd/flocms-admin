<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Widgets;
use FloCMS\Admin\Widgets\{ChartWidget, WidgetContext};
final class PublicationChart extends ChartWidget
{
    protected string $title = 'Publication status';
    protected array $resources = [WidgetPostResource::class];
    protected function data(WidgetContext $context): array
    {
        $posts=$context->query(WidgetPostResource::class);
        return ['labels'=>['Draft','Published'], 'values'=>[$posts->where('status','=','draft')->count(),$posts->where('status','=','published')->count()]];
    }
}
