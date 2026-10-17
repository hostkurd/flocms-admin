<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Media\MediaItem;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Tests\TestCase;

final class MediaItemTest extends TestCase
{
    private static function item(array $row = []): MediaItem
    {
        return MediaItem::fromRow((object) ($row + [
            'id' => 7, 'kind' => 'image', 'path' => 'library/2026/10/web/a b.jpg', 'thumb_path' => 'library/2026/10/thumb/a b.jpg', 'mime' => 'image/jpeg',
            'bytes' => '2048', 'width' => '1600', 'height' => '900', 'file_name' => 'beach.jpg', 'title' => '', 'alt' => '{"en":"A \"quiet\" beach","ar":"شاطئ هادئ"}',
            'uploaded_by' => '3', 'created_at' => '2026-10-17 10:00:00', 'updated_at' => null,
        ]), '/uploads/editor');
    }

    public function testUrlsEncodeEachSegmentAndFallBackToTheWebVersion(): void
    {
        self::assertSame('/uploads/editor/library/2026/10/web/a%20b.jpg', self::item()->url());
        self::assertSame('/uploads/editor/library/2026/10/thumb/a%20b.jpg', self::item()->url('thumb'));
        self::assertSame('/uploads/editor/library/2026/10/web/a%20b.jpg', self::item(['thumb_path' => null])->url('thumb'));
        self::assertSame('https://cdn.test/m/x.png', MediaItem::urlFor('https://cdn.test/m/', '/x.png'));
    }

    public function testAltFollowsTheLanguageWithTheDefaultAsFallback(): void
    {
        AdminLang::setLanguage('ar');
        self::assertSame('شاطئ هادئ', self::item()->alt('ar'));
        self::assertSame('A "quiet" beach', self::item()->alt('ku'));
        self::assertSame('beach.jpg', self::item()->label());
        self::assertSame('Title', self::item(['title' => 'Title'])->label());
        self::assertSame('#7', self::item(['file_name' => ''])->label());
    }

    public function testImgHasSizeLazyLoadingSrcsetAndEscapedAttributes(): void
    {
        $html = self::item()->img(['class' => 'cover "x"', 'onclick' => 'alert(1)', 'loading' => 'eager', 'data-x' => null]);
        self::assertSame('<img src="/uploads/editor/library/2026/10/web/a%20b.jpg" alt="A &quot;quiet&quot; beach" width="1600" height="900" loading="eager" decoding="async"'
            . ' srcset="/uploads/editor/library/2026/10/thumb/a%20b.jpg 400w, /uploads/editor/library/2026/10/web/a%20b.jpg 1600w" sizes="(max-width: 1600px) 100vw, 1600px" class="cover &quot;x&quot;">', $html);
        self::assertSame([400, 225], self::item()->thumbSize());
        self::assertStringNotContainsString('srcset', self::item(['width' => '300', 'height' => '200'])->img(), 'a picture smaller than the thumb needs no srcset');
        self::assertSame('', self::item(['kind' => 'video'])->img());
    }

    public function testVideoAndHtml(): void
    {
        $video = self::item(['kind' => 'video', 'path' => 'library/v.mp4', 'thumb_path' => null, 'width' => null, 'height' => null, 'alt' => null]);
        self::assertSame('<video src="/uploads/editor/library/v.mp4" controls preload="metadata" playsinline></video>', $video->video());
        self::assertSame($video->video(), $video->html());
        self::assertSame('', self::item()->video());
        self::assertStringStartsWith('<img', self::item()->html());
    }

    public function testToArrayIsWhatScriptsNeed(): void
    {
        $data = self::item()->toArray();
        self::assertSame(['id' => 7, 'kind' => 'image', 'thumbWidth' => 400, 'thumbHeight' => 225], array_intersect_key($data, ['id' => 1, 'kind' => 1, 'thumbWidth' => 1, 'thumbHeight' => 1]));
        self::assertSame(['en' => 'A "quiet" beach', 'ar' => 'شاطئ هادئ'], (array) $data['alts']);
    }

    public function testPickerIdsAndValues(): void
    {
        self::assertSame([5], MediaPicker::ids('5'));
        self::assertSame([5], MediaPicker::ids(5));
        self::assertSame([3, 1], MediaPicker::ids('["3","1","3","x","0","-2"]'));
        self::assertSame([], MediaPicker::ids('01'));
        self::assertSame([], MediaPicker::ids(['a' => [1]]));
        self::assertSame([2, 9], MediaPicker::ids(['2', 9]));

        $single = MediaPicker::make('cover_id');
        self::assertSame(7, $single->dehydrate('7'));
        self::assertNull($single->dehydrate(''));
        self::assertSame('4', $single->stateFromInput(['cover_id' => '4'], []));
        self::assertSame('4', $single->stateFromRecord((object) ['cover_id' => 4], 'en'));
        self::assertSame('', $single->stateFromRecord((object) ['cover_id' => null], 'en'));

        $many = MediaPicker::make('gallery')->max(2);
        self::assertTrue($many->isMultiple());
        self::assertSame('["3","1"]', $many->dehydrate('["3","1"]'));
        self::assertSame('["3","1"]', $many->stateFromInput(['gallery' => ['3', '1']], []), 'the select without JavaScript');
        self::assertSame('["3","1"]', $many->stateFromInput(['gallery' => '["3","1"]'], []), 'the script\'s JSON');
        self::assertSame(['validation.media_max', ['max' => '2']], $many->typeError('["1","2","3"]'));
        self::assertSame(['validation.media', []], MediaPicker::make('x')->typeError('["1","2"]'), 'one item only');
        $this->expectException(\InvalidArgumentException::class);
        MediaPicker::make('y')->max(0);
    }

    public function testLibraryConfig(): void
    {
        $config = Admin::useConfig([], true, sys_get_temp_dir())->mediaLibrary();
        self::assertSame(['enabled' => true, 'table' => 'media', 'usage_table' => 'media_usage', 'max_image_bytes' => 10485760, 'max_video_bytes' => 209715200], $config);
        self::assertFalse(Admin::useConfig(['media_library' => false], true, sys_get_temp_dir())->mediaLibrary()['enabled']);
        $named = Admin::useConfig(['media_library' => ['table' => 'site_media', 'usage_table' => 'bad name;', 'max_image_kb' => 2048, 'max_video_mb' => 99999]], true, sys_get_temp_dir())->mediaLibrary();
        self::assertSame(['site_media', 'media_usage', 2097152, 4096 * 1048576], [$named['table'], $named['usage_table'], $named['max_image_bytes'], $named['max_video_bytes']]);
    }
}
