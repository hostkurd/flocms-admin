<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Widgets;
use FloCMS\Admin\Widgets\{StatsWidget, WidgetContext, Stat};
use FloCMS\Core\Session;
final class DeniedStats extends StatsWidget
{
    protected string $title = 'Hidden financial data';
    protected ?string $permission = 'settings.widgets';
    protected array $resources = [WidgetPostResource::class];
    protected function stats(WidgetContext $context): array { Session::set('denied_widget_executed',true); return [Stat::make('Hidden',99)]; }
}
