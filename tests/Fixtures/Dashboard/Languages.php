<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Dashboard;
use FloCMS\Admin\Widgets\{BarListWidget, WidgetContext};
/** Bars against 100 (0.42). */
final class Languages extends BarListWidget
{
    protected string $title = 'Visitors by language';
    protected bool $percent = true;
    protected function items(WidgetContext $context): array
    {
        return [['label' => 'English', 'value' => 62, 'color' => 'chart2'], ['label' => 'العربية', 'value' => 24, 'color' => 'chart2'], ['label' => 'Kurdî', 'value' => 14, 'color' => 'chart2']];
    }
    protected function footer(WidgetContext $context): string { return 'Last 30 days'; }
}
