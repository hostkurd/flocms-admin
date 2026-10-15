<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Feature;
use FloCMS\Admin\Tests\Fixtures\Resources\AssetResource;
final class UploadsRequestTest extends RequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();$pdo=self::pdo();$pdo->exec('DROP TABLE IF EXISTS assets');
        $id=$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec('CREATE TABLE assets (id '.$id.', title VARCHAR(190), kind VARCHAR(20), cover TEXT NULL, attachment TEXT NULL, internal TEXT NULL, extra TEXT NULL)');
        $pdo->exec("INSERT INTO assets (title,kind) VALUES ('Villa media','normal')");
    }
    private function options():array {return ['admin_config'=>['resources'=>[AssetResource::class],'reactive'=>true]];}
    private function pending(string $field='attachment',string $id='1'):array
    {
        $path='pending/'.bin2hex(random_bytes(10)).'.txt';$dir=self::$root.'/storage/admin-uploads';if(!is_dir($dir.'/pending'))mkdir($dir.'/pending',0700,true);file_put_contents($dir.'/'.$path,'Property document');
        $token=bin2hex(random_bytes(32));$session=self::EDITOR;$session['_flo_admin_uploads']=[$token=>['path'=>$path,'name'=>'document.txt','size'=>17,'issued'=>time(),'scope'=>json_encode(['assets',$id,$field]),'actor'=>'1','csrf'=>hash('sha256','tok')]];
        return ['@upload:'.$token,$session];
    }
    public function testStandardSaveRejectsPathsAndPreservesFilesOnValidationErrors():void
    {
        [$token,$session]=$this->pending();$o=$this->options()+['session'=>$session];
        $invalid=self::post('/en/admin/assets/edit/1',['title'=>'','kind'=>'normal','attachment'=>$token],$o);
        self::assertSame(200,$invalid['status']);self::assertNull(self::row('assets',1)['attachment']);self::assertStringContainsString($token,$invalid['body']);
        $saved=self::post('/en/admin/assets/edit/1',['title'=>'Saved','kind'=>'normal','attachment'=>$token],$o+['session'=>$invalid['session']]);
        self::assertTrue($saved['redirected']);$path=self::row('assets',1)['attachment'];self::assertMatchesRegularExpression('~^assets/attachment/[a-f0-9]{40}\.txt$~',$path);self::assertFileExists(self::$root.'/storage/admin-uploads/'.$path);
        $forged=self::post('/en/admin/assets/edit/1',['title'=>'Changed','attachment'=>'../../etc/passwd'],$this->options());self::assertFalse($forged['redirected']);self::assertSame($path,self::row('assets',1)['attachment']);
    }
    public function testHiddenDisabledAndCrossRecordReferencesNeverWrite():void
    {
        [$token,$session]=$this->pending('extra');
        $saved=self::post('/en/admin/assets/edit/1',['title'=>'Saved','kind'=>'normal','extra'=>$token,'internal'=>$token],$this->options()+['session'=>$session]);
        self::assertTrue($saved['redirected']);self::assertNull(self::row('assets',1)['extra']);self::assertNull(self::row('assets',1)['internal']);
        $cross=self::post('/en/admin/assets/create',['title'=>'Other','kind'=>'normal','attachment'=>$token],$this->options()+['session'=>$session]);self::assertFalse($cross['redirected']);
    }
    public function testAfterSaveFailureRollsBackDatabaseAndNewFileAndRetainsPending():void
    {
        [$token,$session]=$this->pending();$before=glob(self::$root.'/storage/admin-uploads/assets/attachment/*')?:[];
        $response=self::post('/en/admin/assets/edit/1',['title'=>'After rollback','attachment'=>$token],$this->options()+['session'=>$session,'expect_error'=>true]);
        self::assertSame('Villa media',self::row('assets',1)['title']);self::assertNull(self::row('assets',1)['attachment']);self::assertSame($before,glob(self::$root.'/storage/admin-uploads/assets/attachment/*')?:[]);self::assertStringContainsString('Fixture rollback',$response['error']);
    }
    public function testDownloadsRecheckPermissionsAndRecordPolicy():void
    {
        [$token,$session]=$this->pending();$saved=self::post('/en/admin/assets/edit/1',['title'=>'Saved','attachment'=>$token],$this->options()+['session'=>$session]);
        $ok=self::request('GET','/en/admin/assets/file/1/attachment',$this->options()+['session'=>$saved['session']]);self::assertSame(200,$ok['status']);self::assertSame('Property document',$ok['body']);
        $denied=self::request('GET','/en/admin/assets/file/1/attachment',$this->options()+['permissions'=>[1=>['content.assets.view']]]);self::assertSame(403,$denied['status']);
        self::pdo()->exec("UPDATE assets SET title='Denied' WHERE id=1");self::assertSame(403,self::request('GET','/en/admin/assets/file/1/attachment',$this->options())['status']);
    }
    public function testStandardDeleteCleansOnlyTheManagedFiles():void
    {
        [$token,$session]=$this->pending();$saved=self::post('/en/admin/assets/edit/1',['title'=>'Saved','attachment'=>$token],$this->options()+['session'=>$session]);
        $path=self::row('assets',1)['attachment'];self::assertFileExists(self::$root.'/storage/admin-uploads/'.$path);
        $deleted=self::post('/en/admin/assets/delete/1',[],$this->options()+['session'=>$saved['session']]);self::assertTrue($deleted['redirected']);self::assertNull(self::row('assets',1));self::assertFileDoesNotExist(self::$root.'/storage/admin-uploads/'.$path);
    }
    public function testBulkDeleteCleansFilesAfterSuccessfulTransaction():void
    {
        [$token,$session]=$this->pending();$saved=self::post('/en/admin/assets/edit/1',['title'=>'Saved','attachment'=>$token],$this->options()+['session'=>$session]);$path=self::row('assets',1)['attachment'];
        $open=self::request('GET','/en/admin/assets/bulk/delete?ids[]=1',$this->options()+['session'=>$saved['session']]);preg_match('/name="_operation" value="([^"]+)"/',$open['body'],$match);self::assertNotEmpty($match[1]??'');
        $delete=self::post('/en/admin/assets/bulk/delete',['_operation'=>$match[1],'_ids'=>'["1"]'],$this->options()+['session'=>$open['session']]);self::assertTrue($delete['redirected']);self::assertFileDoesNotExist(self::$root.'/storage/admin-uploads/'.$path);
    }

}
