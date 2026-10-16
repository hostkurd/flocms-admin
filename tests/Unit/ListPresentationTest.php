<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Http\PageView;
use FloCMS\Admin\Table\FilterSet;
use FloCMS\Admin\Table\Filters\{DateRangeFilter, SelectFilter, ToggleFilter};
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Tests\TestCase;

/** Filter chips and page numbers shared by the Reactive and standard lists. */
final class ListPresentationTest extends TestCase
{
    private function table(): Table
    {
        return (new Table())->filters([
            SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
            ToggleFilter::make('featured'),
            DateRangeFilter::make('published_on'),
        ]);
    }

    public function testChipsDescribeEachActiveFilterAndTheStateWithoutIt(): void
    {
        $set = new FilterSet($this->table(), '{"status":"draft","featured":"1","published_on":{"from":"2026-10-01","until":""}}');
        $chips = $set->chips();
        self::assertSame(['status', 'featured', 'published_on'], array_column($chips, 'name'));
        self::assertSame(['Status', 'Featured', 'Published on'], array_column($chips, 'label'));
        self::assertSame(['Draft', 'Yes', 'From 2026-10-01'], array_column($chips, 'value'));
        self::assertSame(['featured' => '1', 'published_on' => ['from' => '2026-10-01', 'until' => '']], json_decode($chips[0]['without'], true));
        self::assertSame($set->active(), ['status' => 'draft'] + $chips[0]['query']);
        // Removing the last remaining filter leaves an empty object, which the list accepts.
        self::assertSame('{}', (new FilterSet($this->table(), '{"status":"published"}'))->chips()[0]['without']);
    }

    public function testDateRangeChipsAndNoChipsWithoutActiveOrValidFilters(): void
    {
        self::assertSame('2026-10-01 – 2026-10-31', (new FilterSet($this->table(), '{"published_on":{"from":"2026-10-01","until":"2026-10-31"}}'))->chips()[0]['value']);
        self::assertSame('Until 2026-10-31', (new FilterSet($this->table(), '{"published_on":{"from":"","until":"2026-10-31"}}'))->chips()[0]['value']);
        self::assertSame([], (new FilterSet($this->table()))->chips());
        self::assertSame([], (new FilterSet($this->table(), '{"status":"archived"}'))->chips());
    }

    public function testPageNumbersKeepFirstLastAndNeighboursWithGaps(): void
    {
        self::assertSame([], PageView::pageNumbers(1, 1));
        self::assertSame([1, 2, 3], PageView::pageNumbers(1, 3));
        self::assertSame([1, 2, 3, null, 20], PageView::pageNumbers(1, 20));
        self::assertSame([1, null, 4, 5, 6, 7, 8, null, 20], PageView::pageNumbers(6, 20));
        self::assertSame([1, null, 18, 19, 20], PageView::pageNumbers(20, 20));
    }
}
