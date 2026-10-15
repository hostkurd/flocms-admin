<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Feature;
use FloCMS\Admin\Tests\Fixtures\Widgets\{WidgetPostResource, ContentOverview, RecentPosts, PublicationChart, DeniedStats, BrokenStats};
use FloCMS\Admin\Widgets\{WidgetContext, WidgetQuery};
use FloCMS\Admin\{Admin, Translation\Translations};
use FloCMS\Core\{App, Database, Config, Session};
final class WidgetsRequestTest extends RequestTestCase
{
    protected function setUp():void
    {
        parent::setUp();file_put_contents(self::$root.'/lang/ar.php', '<?php return ["lng.dir"=>"rtl"];');self::pdo()->exec("UPDATE posts SET views=12 WHERE id=1");self::pdo()->exec("UPDATE posts SET title='{\"en\":\"Secret row\"}', views=999 WHERE id=2");
    }
    private function options(array $extra=[]):array
    {
        return ['admin_config'=>$extra+['resources'=>[WidgetPostResource::class], 'widgets'=>[ContentOverview::class,RecentPosts::class,PublicationChart::class], 'reactive'=>true]];
    }
    private function update(array $mount,string $action='refresh',array $state=[],array $options=[]):array
    {
        preg_match('/data-rx-snapshot="([^"]+)"/',$mount['body'],$snapshot);self::assertNotEmpty($snapshot[1]??'',$mount['body']);
        return self::post('/en/flo-reactive/update',['_snapshot'=>html_entity_decode($snapshot[1],ENT_QUOTES),'_action'=>$action,'state'=>$state],$options+$this->options()+['session'=>$mount['session']]);
    }
    public function testConfiguredDashboardUsesScopedCountsRecordsAndChartData():void
    {
        $response=self::request('GET','/en/admin/dashboard',$this->options());self::assertSame(200,$response['status']);
        self::assertStringContainsString('Content overview',$response['body']);self::assertStringContainsString('Sea view villa',$response['body']);self::assertStringNotContainsString('Secret row',$response['body']);self::assertStringNotContainsString('999',$response['body']);self::assertStringContainsString('>12<',$response['body']);self::assertStringContainsString('View chart data',$response['body']);
        $ar=self::request('GET','/ar/admin/dashboard',$this->options());self::assertStringContainsString('فيلا',$ar['body']);self::assertStringContainsString('dir="rtl"',$ar['body']);
    }
    public function testRefreshReadsCurrentDataAndPermissionRevocationRemovesWidgets():void
    {
        $mount=self::request('GET','/en/admin/dashboard',$this->options());self::pdo()->exec("UPDATE posts SET views=24 WHERE id=1");
        $refresh=$this->update($mount);self::assertSame(200,$refresh['status']);self::assertStringContainsString('>24<',$refresh['body']);
        $denied=$this->update($refresh,'refresh',[],['permissions'=>[1=>[]]]);self::assertSame(200,$denied['status']);self::assertStringNotContainsString('Sea view villa',$denied['body']);self::assertStringNotContainsString('Content overview',$denied['body']);self::assertSame($refresh['session']['widget_overview_calls'],$denied['session']['widget_overview_calls']);
    }
    public function testUnauthorizedWidgetsNeverInvokeProvidersAndBrokenWidgetsAreIsolated():void
    {
        $response=self::request('GET','/en/admin/dashboard',$this->options(['widgets'=>[DeniedStats::class,BrokenStats::class,ContentOverview::class]]));
        self::assertSame(200,$response['status']);self::assertArrayNotHasKey('denied_widget_executed',$response['session']);self::assertStringNotContainsString('Hidden financial data',$response['body']);self::assertStringNotContainsString('private-dsn-sensitive',$response['body']);self::assertStringContainsString('currently unavailable',$response['body']);self::assertStringContainsString('Content overview',$response['body']);
    }
    public function testDisabledReactiveHasAnOrdinaryRefreshFormAndEmptyConfigurationKeepsCards():void
    {
        $standard=self::request('GET','/en/admin/dashboard',$this->options(['reactive'=>false]));self::assertSame(200,$standard['status']);self::assertStringContainsString('Refresh dashboard',$standard['body']);self::assertStringNotContainsString('data-rx-component',$standard['body']);
        $default=self::request('GET','/en/admin/dashboard',$this->options(['widgets'=>[]]));self::assertSame(200,$default['status']);self::assertStringContainsString('fa-stat-icon',$default['body']);self::assertStringNotContainsString('data-admin-widget',$default['body']);
    }
    public function testTamperedActionsStateCsrfAndRemovedConfigurationAreRejected():void
    {
        $mount=self::request('GET','/en/admin/dashboard',$this->options());
        self::assertNotSame(200,$this->update($mount,'delete')['status']);
        self::assertNotSame(200,$this->update($mount,'refresh',['instanceKey'=>'forged'])['status']);
        self::assertSame(419,self::request('POST','/en/flo-reactive/update',['post'=>['_snapshot'=>'x','_action'=>'refresh']]+$this->options())['status']);
        self::assertSame(404,$this->update($mount,'refresh',[],$this->options(['widgets'=>[]]))['status']);
    }
    public function testReadHelpersPreserveScopeAcrossReuseAndRejectUndeclaredSourcesAndUnsafeColumns():void
    {
        Admin::useConfig(['resources'=>[WidgetPostResource::class]],true,self::$root);App::$db=new Database(self::pdo());Config::set('permissions',self::PERMISSIONS);$_SESSION=self::EDITOR;
        $context=new WidgetContext([WidgetPostResource::class]);$q=$context->query(WidgetPostResource::class);
        self::assertSame(1,$q->count());self::assertSame(0,$q->where('status','=','draft')->count());self::assertSame(1,$q->count());self::assertSame(12.0,$q->sum('views'));self::assertSame(0,$q->whereIn('id',[])->count());self::assertCount(1,$q->records(['title']));
        $this->expectException(\InvalidArgumentException::class);$q->sum('views); DROP TABLE posts');
    }
    public function testReadBudgetStopsAdditionalDatabaseReads():void
    {
        Admin::useConfig(['resources'=>[WidgetPostResource::class]],true,self::$root);App::$db=new Database(self::pdo());Config::set('permissions',self::PERMISSIONS);$_SESSION=self::EDITOR;
        $q=(new WidgetContext([WidgetPostResource::class]))->query(WidgetPostResource::class);
        for($i=0;$i<64;$i++){self::assertSame(1,$q->count());}
        $this->expectException(\RuntimeException::class);$q->count();
    }
    public function testRecordReadLimitsCannotBeExpanded():void
    {
        $query=new WidgetQuery(static function ():Database {throw new \RuntimeException('Must reject before querying.');});
        $this->expectException(\InvalidArgumentException::class);$query->records(['title'],'id','desc',21);
    }

}
