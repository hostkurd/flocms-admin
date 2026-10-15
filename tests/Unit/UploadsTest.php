<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;
use FloCMS\Admin\{Admin, Form\Fields\FileUpload, Form\Fields\ImageUpload, Form\Fields\TextInput, Form\Form, Uploads\Uploads, Uploads\UploadBatch, Validation\FormValidator};
use FloCMS\Admin\Tests\Fixtures\Resources\AssetResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\{Session, Csrf};

final class UploadsTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        parent::setUp(); $this->root = sys_get_temp_dir() . '/admin-upload-unit-' . bin2hex(random_bytes(6));
        Admin::useConfig([], true, $this->root); Session::set('user_id', 42); Csrf::token();
        mkdir(Uploads::root() . '/pending', 0700, true);
    }
    protected function tearDown(): void { exec('rm -rf ' . escapeshellarg($this->root)); parent::tearDown(); }
    private function pending(string $scope, string $name = 'note.txt'): string
    {
        $token = bin2hex(random_bytes(32)); $path = 'pending/' . bin2hex(random_bytes(10)) . '.txt'; file_put_contents(Uploads::root() . '/' . $path, 'A document');
        $entries = Session::get('_flo_admin_uploads', []); $entries[$token] = ['path'=>$path,'name'=>$name,'size'=>10,'issued'=>time(),'scope'=>$scope,'actor'=>'42','csrf'=>hash('sha256',Csrf::token())]; Session::set('_flo_admin_uploads', $entries);
        return '@upload:' . $token;
    }
    public function testTypeAndExtensionMustMatchDetectedContentAndSizeIsMeasured(): void
    {
        $path = $this->root . '/note'; file_put_contents($path, str_repeat('document ', 200));
        $file = ['tmp_name'=>$path,'name'=>'note.txt','error'=>0,'size'=>1,'type'=>'image/png'];
        self::assertSame('text/plain', FileUpload::make('file')->validateFile($file)['mime']);
        foreach ([['name'=>'note.pdf'], ['name'=>'script.php'], ['name'=>'image.svg']] as $changes) {
            try { FileUpload::make('file')->validateFile($changes+$file); self::fail('Mismatched extension accepted.'); }
            catch (\FloCMS\Uploader\Exceptions\ValidationException) { self::assertTrue(true); }
        }
        $this->expectException(\FloCMS\Uploader\Exceptions\ValidationException::class); FileUpload::make('file')->maxSize(1)->validateFile($file);
    }
    public function testCsvRequiresExplicitCsvTypeEvenWhenDetectedAsPlainText(): void
    {
        $path=$this->root.'/csv';file_put_contents($path,"a,b\n1,2\n");$file=['tmp_name'=>$path,'name'=>'data.csv','error'=>0];
        self::assertSame('text/csv',FileUpload::make('file')->acceptedFileTypes(['text/csv'])->validateFile($file)['mime']);
        $this->expectException(\FloCMS\Uploader\Exceptions\ValidationException::class);FileUpload::make('file')->validateFile($file);
    }
    public function testImagesHaveDimensionsAndRejectTextAndSvg(): void
    {
        $path = $this->root . '/image'; file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6y1EAAAAASUVORK5CYII='));
        $file=['tmp_name'=>$path,'name'=>'image.png','error'=>0];
        self::assertSame(1, ImageUpload::make('cover')->validateFile($file)['width']);
        $this->expectException(\FloCMS\Uploader\Exceptions\ValidationException::class); ImageUpload::make('cover')->dimensions(10,10,2,2)->validateFile($file);
    }
    public function testOpaqueTokensAreScopedToActorCsrfRecordAndField(): void
    {
        $r = new AssetResource(); $record=(object)['id'=>1,'attachment'=>'existing']; $field=FileUpload::make('attachment');
        $token=$this->pending(Uploads::scope($r,$record,'attachment'));
        self::assertSame($token,Uploads::trusted($field,$token,$r,$record));
        self::assertSame('@invalid',Uploads::trusted($field,$token,$r,(object)['id'=>2]));
        self::assertSame('@invalid',Uploads::trusted(FileUpload::make('extra'),$token,$r,$record));
        self::assertSame('@invalid',Uploads::trusted($field,'../../etc/passwd',$r,$record));
        self::assertSame('existing',Uploads::trusted($field,'',$r,$record));
        Session::set('user_id',43);self::assertSame('@invalid',Uploads::trusted($field,$token,$r,$record));Session::set('user_id',42);
        Csrf::rotate(); self::assertSame('@invalid',Uploads::trusted($field,$token,$r,$record));
    }
    public function testRollbackRemovesNewFileAndKeepsOldFileAndRetryToken(): void
    {
        $r=new AssetResource(); $field=FileUpload::make('attachment'); $form=(new Form())->schema([$field]);
        $old='assets/attachment/'.str_repeat('a',40).'.txt'; Uploads::storage()->put($old, __FILE__);
        $record=(object)['id'=>1,'attachment'=>$old]; $token=$this->pending(Uploads::scope($r,$record,'attachment'));
        $batch=new UploadBatch(); $state=$batch->prepare($form,['attachment'=>$token],$r,$record);
        self::assertTrue(Uploads::storage()->exists($state['attachment'])); self::assertTrue(Uploads::storage()->exists($old));
        $batch->rollback(); self::assertFalse(Uploads::storage()->exists($state['attachment'])); self::assertTrue(Uploads::storage()->exists($old)); self::assertNotEmpty(Uploads::entry($token));
        $batch=new UploadBatch(); $state=$batch->prepare($form,['attachment'=>$token],$r,$record); $batch->commit();
        self::assertTrue(Uploads::storage()->exists($state['attachment'])); self::assertFalse(Uploads::storage()->exists($old));
    }
    public function testRemovalIsValidatedAsEmptyAndOnlyDeletesAfterCommit(): void
    {
        $r=new AssetResource();$field=FileUpload::make('attachment')->required();$form=(new Form())->schema([$field]);
        self::assertArrayHasKey('attachment',(new FormValidator(['en'],'en'))->validate($form,['attachment'=>'@remove']));
        $old='assets/attachment/'.str_repeat('a',40).'.txt';Uploads::storage()->put($old,__FILE__);
        $batch=new UploadBatch();$state=$batch->prepare($form,['attachment'=>'@remove'],$r,(object)['id'=>1,'attachment'=>$old]);
        self::assertSame('',$state['attachment']);self::assertTrue(Uploads::storage()->exists($old));$batch->commit();self::assertFalse(Uploads::storage()->exists($old));
    }
    public function testAbandonedPendingFilesExpireButManagedFilesAreRetained(): void
    {
        $path=Uploads::root().'/pending/stale.txt';file_put_contents($path,'old');touch($path,time()-1900);
        file_put_contents(Uploads::root().'/pending/fresh.txt','new');
        self::assertSame(1,Uploads::prune());self::assertFileDoesNotExist($path);self::assertFileExists(Uploads::root().'/pending/fresh.txt');
    }
    public function testUnsafeConfigurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);FileUpload::make('file')->acceptedFileTypes(['text/html']);
    }
    public function testUploadFieldsCannotBecomeDependenciesOrTranslatable(): void
    {
        $form=(new Form())->schema([FileUpload::make('file'),TextInput::make('title')->visibleWhen('file','x')]);$state=[];
        $this->expectException(\LogicException::class);$form->evaluate($state);
    }
}
