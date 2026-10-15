<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;
use FloCMS\Admin\{Admin, Widgets\WidgetRegistry, Widgets\WidgetContext, Widgets\Stat, Widgets\ChartWidget};
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Tests\Fixtures\Widgets\{WidgetPostResource, ContentOverview, RecentPosts, PublicationChart};
final class WidgetsTest extends TestCase
{
    public function testRegistryReportsInvalidDuplicateAndUnregisteredDefinitions(): void
    {
        $config=Admin::useConfig(['resources'=>[WidgetPostResource::class], 'widgets'=>[RecentPosts::class,'MissingWidget',ContentOverview::class,ContentOverview::class]]);
        $registry=WidgetRegistry::fromConfig($config);self::assertTrue($registry->configured());self::assertCount(2,$registry->all());self::assertInstanceOf(ContentOverview::class,$registry->all()[0]);self::assertCount(2,$registry->problems());
        self::assertCount(1,WidgetRegistry::fromConfig(Admin::useConfig(['resources'=>[], 'widgets'=>[PublicationChart::class]]))->problems());
        self::assertFalse(WidgetRegistry::fromConfig(Admin::useConfig([]))->configured());
        self::assertNotEmpty(WidgetRegistry::fromConfig(Admin::useConfig(['widgets'=>array_fill(0,17,'Missing')]))->problems());
    }
    public function testBuiltinUserResourceIsAvailableOnlyWhileUsersAreEnabled(): void
    {
        Admin::useConfig(['users'=>true]);self::assertTrue(WidgetContext::registered(\FloCMS\Admin\Users\UsersResource::class));
        Admin::useConfig(['users'=>false]);self::assertFalse(WidgetContext::registered(\FloCMS\Admin\Users\UsersResource::class));
    }
    public function testStatEscapesTextAndRejectsUnsafeColorsAndNonfiniteValues(): void
    {
        $context=new WidgetContext([]);$html=Stat::make('<Title>','<script>')->description('<details>')->render($context);
        self::assertStringContainsString('&lt;script&gt;',$html);self::assertStringContainsString('&lt;Title&gt;',$html);self::assertStringNotContainsString('<details>',$html);
        $this->expectException(\InvalidArgumentException::class);Stat::make('Unsafe',INF);
    }
    public function testStatResourceLinksCannotBypassDeclaredSources(): void
    {
        Admin::useConfig(['resources'=>[WidgetPostResource::class]]);
        $this->expectException(\LogicException::class);Stat::make('Posts',1)->resource(WidgetPostResource::class)->render(new WidgetContext([]));
    }
    public function testChartsHandleNegativeZeroSingleAndEmptySeriesWithEscapedAccessibleTables(): void
    {
        $chart=new class extends ChartWidget {
            protected string $title='Chart'; protected string $type='line';
            protected function data(WidgetContext $context):array{return ['labels'=>['<January>','Feb','Mar'],'values'=>[-5,0,2.5]];}
        };
        $html=$chart->render(new WidgetContext([]),'test');self::assertStringContainsString('role="img"',$html);self::assertStringContainsString('test-chart-description',$html);self::assertStringContainsString('&lt;January&gt;',$html);self::assertStringContainsString('>-5<',$html);self::assertStringContainsString('>2.50<',$html);self::assertStringNotContainsString('NaN',$html);self::assertStringNotContainsString('style=',$html);
        $zero=new class extends ChartWidget {protected function data(WidgetContext $context):array{return ['labels'=>['Zero'],'values'=>[0]];}};
        self::assertStringContainsString('<rect',$zero->render(new WidgetContext([]),'zero'));
        $empty=new class extends ChartWidget {protected function data(WidgetContext $context):array{return ['labels'=>[],'values'=>[]];}};
        self::assertStringNotContainsString('<svg',$empty->render(new WidgetContext([]),'empty'));
    }
    public function testNonfiniteChartDataIsRejected(): void
    {
        $chart=new class extends ChartWidget {protected function data(WidgetContext $context):array{return ['labels'=>['Bad'],'values'=>[NAN]];}};
        $this->expectException(\InvalidArgumentException::class);$chart->render(new WidgetContext([]),'bad');
    }
    public function testMismatchedOrUnboundedChartDataIsRejected(): void
    {
        $chart=new class extends ChartWidget {protected function data(WidgetContext $context):array{return ['labels'=>['One'],'values'=>[1,2]];}};
        $this->expectException(\InvalidArgumentException::class);$chart->render(new WidgetContext([]),'bad');
    }
    public function testWidgetAutoloadingUsesTheConfiguredDirectoryAndNamespace(): void
    {
        $root=sys_get_temp_dir().'/admin-widgets-'.bin2hex(random_bytes(6));mkdir($root.'/admin/Widgets',0700,true);
        $ns='WidgetTest'.bin2hex(random_bytes(5));$class=$ns.'\\Example';
        file_put_contents($root.'/admin/Widgets/Example.php','<?php namespace '.$ns.'; final class Example extends \\FloCMS\\Admin\\Widgets\\StatsWidget { protected function stats(\\FloCMS\\Admin\\Widgets\\WidgetContext $context):array{return [];} }');
        try {$registry=WidgetRegistry::fromConfig(Admin::useConfig(['widgets'=>[$class],'widgets_namespace'=>$ns],true,$root));self::assertCount(1,$registry->all());self::assertSame([],$registry->problems());}
        finally {unlink($root.'/admin/Widgets/Example.php');rmdir($root.'/admin/Widgets');rmdir($root.'/admin');rmdir($root);}
    }
}
