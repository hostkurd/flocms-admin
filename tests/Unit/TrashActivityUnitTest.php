<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Activity\ActivityEntry;
use FloCMS\Admin\Activity\ActivityView;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Dates;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Trash\Snapshot;
use FloCMS\Admin\Trash\TrashEntry;

final class TrashActivityUnitTest extends TestCase
{
    public function testSnapshotsKeepTypesNullsAndBinaryBytes(): void
    {
        $row = ['id' => 7, 'price' => 1.5, 'title' => '{"en":"Villa","ar":"فيلا"}', 'note' => null, 'blob' => "\xff\x00\xfe", 'zero' => 0.0];
        self::assertSame($row, Snapshot::decode(Snapshot::encode($row)));
    }

    public function testDaysLeftAndLabels(): void
    {
        $entry = new TrashEntry(1, 'posts', '5', '{"en":"Sea view","ar":"إطلالة"}', [], [], [], str_repeat('a', 32), 1, 'Sara', date('Y-m-d H:i:s', time() - 28 * 86400));
        self::assertSame(2, $entry->daysLeft());
        self::assertSame('Sea view', $entry->label());
        AdminLang::setLanguage('ar');
        self::assertSame('إطلالة', $entry->label(), 'in the admin\'s language');
        $untitled = new TrashEntry(2, 'posts', '9', '', [], [], [], str_repeat('a', 32), null, null, date('Y-m-d H:i:s'));
        self::assertSame('#9', $untitled->label());
    }

    public function testTableNamesLeaveRoomForTheirIndexNames(): void
    {
        $config = \FloCMS\Admin\Admin::useConfig(['trash' => ['table' => str_repeat('t', 48)], 'activity' => ['table' => str_repeat('a', 49)]], true, sys_get_temp_dir());
        self::assertSame(str_repeat('t', 48), $config->trash()['table']);
        self::assertSame('admin_activity', $config->activity()['table'], 'MySQL names are 64 characters at most, "_resource_index" included');
        \FloCMS\Admin\Admin::reset();
    }

    public function testRelativeDates(): void
    {
        $now = strtotime('2026-10-17 15:00:00');
        self::assertSame('just now', Dates::relative($now - 20, $now));
        self::assertSame('5 minutes ago', Dates::relative($now - 300, $now));
        self::assertSame('2 hours ago', Dates::relative($now - 7200, $now));
        self::assertSame('yesterday', Dates::relative(strtotime('2026-10-16 09:00:00'), $now));
        self::assertSame('Today', Dates::day($now - 60, $now));
        self::assertSame('Yesterday', Dates::day(strtotime('2026-10-16 23:00:00'), $now));
    }

    public function testEntriesDescribeThemselves(): void
    {
        $entry = new ActivityEntry(1, 'updated', 'posts', '3', '{"en":"Villa"}', 2, 'Ali',
            [['field' => 'status', 'label' => 'Status', 'lang' => null, 'type' => 'value', 'before' => 'Draft', 'after' => 'Published'],
             ['field' => 'body', 'label' => 'Body', 'lang' => 'en', 'type' => 'text', 'before' => '1240', 'after' => '1310']], [], null, '2026-10-17 14:32:00');
        self::assertSame('edited', $entry->group());
        self::assertSame('Edited', $entry->badge());
        self::assertSame('edited 2 fields', $entry->phrase());
        self::assertSame('Status, Body', $entry->fieldNames());
        self::assertSame('Villa', $entry->label());

        $text = ActivityView::row($entry->changes[1]);
        self::assertSame('Changed: 1,240 → 1,310 characters', $text['note']);
        $hidden = ActivityView::row(['field' => 'phone', 'label' => 'Phone', 'lang' => null, 'type' => 'hidden', 'before' => '', 'after' => '']);
        self::assertSame('Changed. The value is not recorded.', $hidden['note']);

        $custom = new ActivityEntry(2, 'published', null, null, '', null, null, [], ['reason' => 'Scheduled'], null, '2026-10-17 14:32:00');
        self::assertSame('Published', $custom->badge(), 'a developer\'s own action reads by its name');
        self::assertSame('System', $custom->who());
        self::assertSame(['Reason' => 'Scheduled'], $custom->details());
        self::assertSame('other', $custom->group());

        $deleted = new ActivityEntry(3, 'deleted', 'posts', '8', 'Studio', 2, 'Ali', [], ['trash' => 1], null, '2026-10-17 14:32:00');
        self::assertSame(['Trash' => 'Moved to the trash'], $deleted->details(), 'internal markers read as sentences');
        $expired = new ActivityEntry(4, 'purged', 'posts', '8', 'Studio', null, null, [], ['expired' => true], null, '2026-10-17 14:32:00');
        self::assertSame(['Trash' => 'Its time in the trash was over'], $expired->details());
    }
}
