<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Form\Fields\{CheckboxList, Select, TextInput, Toggle};
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Support\Numbers;
use FloCMS\Admin\Table\Columns\{EditableColumn, SelectColumn, TextColumn, TextInputColumn, ToggleColumn};
use FloCMS\Admin\Table\Group;
use FloCMS\Admin\Table\Summaries\{Average, Count, Max, Min, Sum};
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Table\Totals;
use PHPUnit\Framework\TestCase;

/** Editable columns, totals and groups (0.38) without a request. */
final class TableExtrasTest extends TestCase
{
    public function testNumbersAreFormattedAndDatabaseValuesNormalised(): void
    {
        self::assertSame('1,250,000', Numbers::format(1250000));
        self::assertSame('1,250,000.00', Numbers::format('1250000.00'));
        self::assertSame('€ 2,409,000.50', Numbers::format(2409000.5, 2, '€ '));
        self::assertSame('0', Numbers::format(-0.0001, 0));
        self::assertSame(7, Numbers::normalize('7'));
        self::assertSame(481800.1, Numbers::normalize('481800.1000'));
        self::assertNull(Numbers::normalize('abc'));
        self::assertNull(Numbers::normalize(null));
    }

    public function testSummariesComputeThePageInPhp(): void
    {
        $values = ['1250000.00', 385000, null, '210000.50'];
        self::assertSame(1845000.5, Sum::make()->compute($values));
        self::assertEqualsWithDelta(615000.16666, Average::make()->compute($values), 0.001);
        self::assertSame(3, Count::make()->compute($values));
        self::assertSame(3, Count::make()->where(1)->compute([1, '1', 0, null, true]));
        self::assertSame(210000.5, Min::make()->compute($values));
        self::assertEquals(1250000, Max::make()->compute($values));
        self::assertSame('2026-09-03', Min::make()->compute(['2026-10-01', '2026-09-03', null]), 'dates compare as text');
        self::assertSame(0, Sum::make()->compute([]));
        self::assertNull(Average::make()->compute([]));
        self::assertSame(0, Sum::make()->fromDatabase(null), 'a sum of nothing is 0');
        self::assertNull(Average::make()->fromDatabase(null));
        self::assertSame(['SUM(CASE WHEN x = ? THEN 1 ELSE 0 END)', ['published']], Count::make()->where('published')->sql('x'));
    }

    public function testSummaryLabelsAndValues(): void
    {
        $html = Average::make()->prefix('€ ')->html(154500.25, 'Price');
        self::assertStringContainsString('<span class="fa-total-column">Price, </span>Average</span>', $html);
        self::assertStringContainsString('<span class="fa-total-value" dir="ltr">€ 154,500.25</span>', $html);
        self::assertStringContainsString('fa-muted">&ndash;</span>', Average::make()->html(null));
        self::assertSame('about 3', Sum::make()->formatUsing(fn ($v): string => 'about ' . $v)->format(3));
    }

    public function testTotalsBuildOneSelectWithBindingsInOrder(): void
    {
        $columns = ['price' => TextColumn::make('price')->summarize([Sum::make(), Count::make()->where(1)]), 'title' => TextColumn::make('title')];
        [$select, $bindings, $map] = (new Totals($columns))->selectList();
        self::assertSame(['SUM(flo_t.`price`) AS flo_s0', 'SUM(CASE WHEN flo_t.`price` = ? THEN 1 ELSE 0 END) AS flo_s1'], $select);
        self::assertSame([1], $bindings);
        self::assertSame(['flo_s0' => ['price', 0], 'flo_s1' => ['price', 1]], $map);
        self::assertSame([], (new Totals(['title' => TextColumn::make('title')]))->lines([], null, null, 3, 1, false));
    }

    public function testTotalsAreRefusedWhereTheDatabaseCannotAddUp(): void
    {
        $table = (new Table())->columns([TextColumn::make('title')->summarize([Count::make()])]);
        $this->expectException(\LogicException::class);
        $table->prepare((new Form())->schema([TextInput::make('title')->translatable()]));
    }

    public function testEditableColumnsUseTheFormField(): void
    {
        $table = (new Table())->columns([
            ToggleColumn::make('featured'),
            SelectColumn::make('status'),
            TextInputColumn::make('price')->numeric(),
            TextInputColumn::make('note'),
        ]);
        $table->prepare((new Form())->schema([
            Toggle::make('featured'),
            Select::make('status')->options(['a' => 'A', 'b' => 'B'])->required(),
            TextInput::make('price'),
        ]));
        self::assertInstanceOf(Toggle::class, $table->column('featured')->field());
        self::assertSame(['a' => 'A', 'b' => 'B'], $table->column('status')->getOptions());
        self::assertContains('numeric', $table->column('price')->field()->getRules(), 'a number column checks numbers');
        self::assertFalse($table->column('note')->hasFormField());
        self::assertSame('v12.5', EditableColumn::token(12.5));
        self::assertSame('n', EditableColumn::token(null));
        self::assertSame('v0', EditableColumn::token(false));
    }

    /** @return iterable<string, array{0: \Closure(): void}> */
    public static function refusedColumns(): iterable
    {
        yield 'translatable field' => [static fn () => (new Table())->columns([TextInputColumn::make('title')])->prepare((new Form())->schema([TextInput::make('title')->translatable()]))];
        yield 'password' => [static fn () => (new Table())->columns([TextInputColumn::make('secret')])->prepare((new Form())->schema([TextInput::make('secret')->password()]))];
        yield 'checkbox list in a select cell' => [static fn () => (new Table())->columns([SelectColumn::make('tags')])->prepare((new Form())->schema([CheckboxList::make('tags')->options(['a' => 'A'])]))];
        yield 'select without options' => [static fn () => (new Table())->columns([SelectColumn::make('size')])->prepare(new Form())];
        yield 'switch values not offered by the select' => [static fn () => (new Table())->columns([ToggleColumn::make('state')->values('on', 'off')])->prepare((new Form())->schema([Select::make('state')->options(['on' => 'On'])]))];
        yield 'custom values on a Toggle field' => [static fn () => (new Table())->columns([ToggleColumn::make('state')->values('yes', 'no')])->prepare((new Form())->schema([Toggle::make('state')]))];
        yield 'grouping by a translatable column' => [static fn () => (new Table())->columns([TextColumn::make('title')])->groups([Group::make('title')])->prepare((new Form())->schema([TextInput::make('title')->translatable()]))];
    }

    /** @dataProvider refusedColumns */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedColumns')]
    public function testColumnsThatCannotBeEditedAreRefusedWhenTheListIsBuilt(\Closure $build): void
    {
        $this->expectException(\LogicException::class);
        $build();
    }

    public function testGroupKeysTitlesAndSql(): void
    {
        $month = Group::make('created_at')->date('month');
        self::assertSame("strftime('%Y-%m', `created_at`)", $month->keySql('`created_at`', 'sqlite'));
        self::assertSame("DATE_FORMAT(`created_at`, '%Y-%m')", $month->keySql('`created_at`', 'mysql'));
        self::assertSame('2026-10', $month->keyOf((object) ['created_at' => '2026-10-05 10:00:00']));
        self::assertNull($month->keyOf((object) ['created_at' => null]));
        $status = Group::make('status');
        $status->useOptions(['draft' => 'Draft'], ['draft' => 'yellow']);
        self::assertSame('Draft', $status->title('draft', (object) []));
        self::assertSame('yellow', $status->color('draft'));
        self::assertSame('No Status', $status->title(null, (object) []));
        self::assertSame('City', Group::make('city_id')->getLabel(), 'the _id is left out');
        self::assertSame('2026 · 3', Group::make('year')->titleUsing(fn (?string $key, object $row): string => $key . ' · ' . $row->n)->title('2026', (object) ['n' => 3]));
    }

    public function testTableGroupsAndModes(): void
    {
        $table = (new Table())->groups([Group::make('status'), Group::make('city_id')])->defaultGroup('city_id');
        self::assertSame(['status', 'city_id'], array_keys($table->getGroups()));
        self::assertSame('city_id', $table->getDefaultGroup());
        self::assertNull((new Table())->groups([Group::make('a')])->defaultGroup('nope')->getDefaultGroup());
        self::assertSame('all', (new Table())->summaries('all')->getSummaryMode());
        $this->expectException(\InvalidArgumentException::class);
        (new Table())->groups([Group::make('a'), Group::make('a')]);
    }

    public function testAPanelPagesTableStaysReadOnly(): void
    {
        $page = new class extends \FloCMS\Admin\AdminPage {
            public function table(Table $table): Table
            {
                return $table->query(static fn (\FloCMS\Core\Database $db): \FloCMS\Core\Database => $db->table('posts'))->columns([ToggleColumn::make('featured')]);
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('read-only');
        \FloCMS\Admin\PanelPages\PageTable::of($page);
    }

    public function testAFieldLeftOutDisabledOrHiddenForTheRecordIsNotEditable(): void
    {
        $omitted = TextInputColumn::make('status');
        $omitted->useFormField(TextInput::make('status')->omit(), new Form());
        self::assertFalse($omitted->isEditable(), 'a relation manager\'s hideFields()');
        $disabled = TextInputColumn::make('code');
        $disabled->useFormField(TextInput::make('code')->disabled(), new Form());
        self::assertFalse($disabled->isEditable());

        $form = (new Form())->schema([Select::make('type')->options(['sale' => 'Sale', 'rent' => 'Rent']), TextInput::make('lease')->visibleWhen('type', 'rent')]);
        $lease = TextInputColumn::make('lease');
        $lease->useFormField($form->field('lease'), $form);
        self::assertTrue($lease->isEditable());
        self::assertTrue($lease->writableFor((object) ['type' => 'rent', 'lease' => '12']));
        self::assertFalse($lease->writableFor((object) ['type' => 'sale', 'lease' => '']), 'the edit page would not write it');
        $context = new \FloCMS\Admin\Table\Cells\CellContext(new class extends \FloCMS\Admin\Resource {
            protected static string $table = 'x';
            public function form(Form $form): Form { return $form; }
            public function table(Table $table): Table { return $table; }
            public function mayEdit(object $record): bool { return true; }
        }, '/back');
        self::assertStringNotContainsString('<form', $lease->renderCell((object) ['id' => 1, 'type' => 'sale', 'lease' => ''], $context));
    }
}
