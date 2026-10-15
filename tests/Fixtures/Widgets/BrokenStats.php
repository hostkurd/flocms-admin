<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Widgets;
use FloCMS\Admin\Widgets\{StatsWidget, WidgetContext};
final class BrokenStats extends StatsWidget
{
    protected string $title = 'Unavailable statistics';
    protected function stats(WidgetContext $context): array { throw new \RuntimeException('private-dsn-sensitive'); }
}
