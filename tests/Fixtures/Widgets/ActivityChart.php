<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Widgets;
use FloCMS\Admin\Widgets\{ChartWidget, WidgetContext};
final class ActivityChart extends ChartWidget
{
    protected string $title = 'Weekly activity';
    protected array $resources = [WidgetPostResource::class];
    protected string $type = 'line';
    protected int|string $columnSpan = 'full';
    protected function data(WidgetContext $context): array { return ['labels'=>['Mon','Tue','Wed','Thu','Fri'], 'values'=>[2,5,3,7,9]]; }
}
