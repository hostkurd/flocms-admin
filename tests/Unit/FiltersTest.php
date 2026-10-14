<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Table\{FilterSet, Table};
use FloCMS\Admin\Table\Filters\{DateRangeFilter, SelectFilter, ToggleFilter};
use FloCMS\Admin\Tests\TestCase;

final class FiltersTest extends TestCase
{
    public function testToggleZeroSurvivesCanonicalState(): void
    {
        $filters = new FilterSet((new Table())->filters([ToggleFilter::make('featured')]), '{"featured":"0"}');
        self::assertSame([], $filters->errors);
        self::assertSame('{"featured":"0"}', $filters->json());
        self::assertStringContainsString('value="0" selected', $filters->render('list'));
    }
    public function testDateBoundariesAreInclusiveAndOptional(): void
    {
        $filter = DateRangeFilter::make('published_on');
        self::assertSame(['from' => '2024-02-29', 'until' => '2024-02-29'], $filter->value(['from' => '2024-02-29', 'until' => '2024-02-29']));
        self::assertSame(['from' => '', 'until' => '2026-10-14'], $filter->value(['until' => '2026-10-14']));
        foreach ([['from' => '2026-02-29'], ['from' => '2026-10-15', 'until' => '2026-10-14'], ['until' => ['unexpected']], ['extra' => '2026-10-14']] as $invalid) {
            try { $filter->value($invalid); self::fail('Invalid dates must be rejected.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testBadSelectValuesHaveAnAccessibleErrorAndNeverEnterCanonicalState(): void
    {
        $table = (new Table())->filters([SelectFilter::make('status')->label('Status')->options(['draft' => 'Draft'])]);
        $filters = new FilterSet($table, '{"status":"injected"}');
        self::assertArrayHasKey('status', $filters->errors);
        self::assertSame('{}', $filters->json());
        self::assertStringContainsString('aria-describedby="list-filter-status-error"', $filters->render('list'));
    }
    public function testQueryColumnIdentifiersCannotContainSql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SelectFilter::make('status')->column('status` OR 1=1');
    }
    public function testDuplicateFilterDefinitionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Table())->filters([SelectFilter::make('status'), ToggleFilter::make('status')]);
    }
}
