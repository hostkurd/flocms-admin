<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Dashboard;
use FloCMS\Admin\Widgets\{ProgressWidget, WidgetContext};
final class Projects extends ProgressWidget
{
    protected string $title = 'Projects';
    protected function items(WidgetContext $context): array
    {
        return [
            ['label' => 'Riverside', 'value' => 62, 'meta' => 'Erbil', 'description' => 'Completion expected June 2027'],
            ['label' => 'Hill Park', 'value' => 38, 'meta' => 'Sulaimani', 'description' => 'Completion expected March 2028'],
            ['label' => 'Old Town', 'value' => 0, 'meta' => 'Duhok'],
        ];
    }
}
