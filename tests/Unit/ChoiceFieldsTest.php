<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Activity\ActivityChanges;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Console\TableInspector;
use FloCMS\Admin\Form\Fields\{CheckboxList, Repeater, TagsInput, TextInput, TimePicker};
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\ImportExport\Values;
use FloCMS\Admin\PanelPages\PageForm;
use FloCMS\Admin\Settings\SettingsEditor;
use FloCMS\Admin\Support\{Dates, ListValues};
use FloCMS\Admin\Table\Columns\{TagsColumn, TimeColumn};
use FloCMS\Admin\Table\Filters\{CheckboxListFilter, TagsFilter, TimeRangeFilter};
use FloCMS\Admin\Table\{FilterSet, Table};
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Validation\FormValidator;

/** CheckboxList, TagsInput and TimePicker (0.37) without a database. */
final class ChoiceFieldsTest extends TestCase
{
    private static function features(): CheckboxList
    {
        return CheckboxList::make('features')->options(['pool' => 'Pool', 'parking' => 'Parking', 'sea_view' => 'Sea view', 'garden' => 'Garden']);
    }

    private static function errors(Form $form, array $state): array
    {
        return (new FormValidator(['en', 'ar', 'ku'], 'en'))->validate($form, $state);
    }

    public function testListValuesReadJsonAndLegacyText(): void
    {
        self::assertSame(['pool', 'parking'], ListValues::decode('["pool","parking"]'));
        self::assertSame(['pool', 'parking'], ListValues::decode('["pool", "parking"]'), 'MySQL JSON columns add a space');
        self::assertSame(['pool', 'sea view'], ListValues::decode('pool, sea view'), 'plain text from before');
        self::assertSame(['فيلا', 'حديقة'], ListValues::decode('فيلا، حديقة'), 'the Arabic comma');
        self::assertSame([], ListValues::decode(null));
        self::assertSame([], ListValues::decode(''));
        self::assertSame(['7', 'a'], ListValues::decode([7, 'a', null, '', ['x']]));
        self::assertSame('', ListValues::encode([]));
        self::assertSame('["إطلالة","a/b"]', ListValues::encode(['إطلالة', 'a/b']));
        self::assertSame('θάλασσα view', ListValues::fold("  Θάλασσα \n VIEW "));
    }

    public function testCheckboxListKeepsKeysInOptionOrderAndStoresJson(): void
    {
        $field = self::features();
        self::assertSame('["pool","garden"]', $field->stateFromInput(['features' => ['_' => '', 'o3' => 'garden', 'o0' => 'pool', 'o9' => 'pool']], ['en']));
        self::assertSame('', $field->stateFromInput(['features' => ['_' => '']], ['en']), 'every box unticked');
        self::assertTrue($field->submitted(['features' => ['_' => '']]));
        self::assertFalse($field->submitted([]));
        self::assertSame('["parking","sea_view"]', $field->stateFromRecord((object) ['features' => 'sea_view, parking'], 'en'), 'legacy text');
        self::assertSame('["parking"]', $field->dehydrate('["parking"]'));
        self::assertNull($field->dehydrate(''));
        self::assertSame('[]', (clone $field)->nullable(false)->dehydrate(''), 'a JSON column cannot hold an empty string');
        self::assertSame(['Pool', 'gone'], $field->readable(['pool', 'gone']));
    }

    public function testCheckboxListValidation(): void
    {
        $form = (new Form())->schema([self::features()->min(2)->max(3)->required()]);
        self::assertSame([], self::errors($form, ['features' => '["pool","parking"]']));
        self::assertSame(['features' => 'Features is required.'], array_map('strval', self::errors($form, ['features' => ''])));
        self::assertStringContainsString('at least 2', self::errors($form, ['features' => '["pool"]'])['features']);
        self::assertStringContainsString('at most 3', self::errors($form, ['features' => '["pool","parking","sea_view","garden"]'])['features']);
        self::assertArrayHasKey('features', self::errors($form, ['features' => '["pool","sauna"]']), 'not an option');
    }

    public function testCheckboxListRendersCheckboxesAndANoticeForAnOldKey(): void
    {
        $field = self::features()->columns(3)->selectAll()->max(2);
        $html = $field->renderControl('f-features', 'features', '["pool","sauna"]', ['aria-describedby' => 'f-features-help']);
        self::assertStringContainsString('<input type="hidden" name="features[_]" value="">', $html);
        self::assertStringContainsString('name="features[o0]" value="pool" checked', $html);
        self::assertStringContainsString('name="features[o1]" value="parking">', $html);
        self::assertStringContainsString('fa-checks-cols-3', $html);
        self::assertStringContainsString('aria-labelledby="f-features-label" aria-describedby="f-features-help"', $html);
        self::assertStringNotContainsString('data-checks-all', $html, 'Select all is left out when it would pass the maximum');
        self::assertStringContainsString('data-checks-none', $html);
        self::assertStringContainsString('“sauna” is no longer an option', $html);
        self::assertMatchesRegularExpression('#name="features\[k0\]" value="~[^"]+" checked#', $html);
        self::assertStringNotContainsString('data-checks-search', $html, 'four options need no filter box');
        $many = CheckboxList::make('x')->options(array_combine(range(1, 13), array_map('strval', range(1, 13))));
        self::assertStringContainsString('data-checks-search', $many->renderControl('f-x', 'x', ''));
        self::assertStringNotContainsString('data-checks-search', (clone $many)->searchable(false)->renderControl('f-x', 'x', ''));
    }

    public function testARemovedOptionStaysValidOnlyOnRecordsThatHaveIt(): void
    {
        $field = self::features()->max(2);
        $form = (new Form())->schema([$field]);
        $field->allowStored(['pool', 'sauna']);
        self::assertSame([], self::errors($form, ['features' => '["pool","garden","sauna"]']), 'sauna is kept and does not count towards max');
        $field->allowStored(['pool']);
        self::assertArrayHasKey('features', self::errors($form, ['features' => '["pool","sauna"]']), 'another record');
        $field->allowStored([]);
        self::assertArrayHasKey('features', self::errors($form, ['features' => '["sauna"]']), 'a new record');
    }

    public function testAKeptOptionInsideAnItemNeedsTheFormsSignature(): void
    {
        $_SESSION = [];
        $field = self::features();
        $html = $field->renderControl('f', 'features', '["sauna"]');
        self::assertSame(1, preg_match('#value="(~[^"]+)"#', $html, $m));
        $signed = html_entity_decode($m[1], ENT_QUOTES);
        $state = $field->stateFromInput(['features' => ['_' => '', 'k0' => $signed, 'o0' => 'pool']], ['en']);
        self::assertSame('["pool","sauna"]', $state);
        self::assertNull($field->typeError($state));
        $plain = $field->stateFromInput(['features' => ['_' => '', 'o9' => 'sauna', 'o1' => 'parking']], ['en']);
        self::assertSame(['validation.in', []], $field->typeError($plain), 'unsigned in another state');
        self::assertSame('["parking"]', $field->stateFromInput(['features' => ['_' => '', 'k0' => '~forged|c2F1bmE=', 'o1' => 'parking']], ['en']), 'a bad signature is dropped');
    }

    public function testADisabledListSendsNoMarker(): void
    {
        self::assertStringContainsString('name="features[_]" value="" disabled', self::features()->disabled()->renderControl('f', 'features', ''));
    }

    public function testCheckboxListCannotBeTranslatable(): void
    {
        $this->expectException(\LogicException::class);
        self::features()->translatable();
    }

    public function testTagsAreCleanedAndDeduplicatedRegardlessOfCapitals(): void
    {
        $field = TagsInput::make('keywords');
        self::assertSame('["villa","Sea View","garden"]', $field->stateFromInput(['keywords' => ' villa ,Sea   View, sea view,,garden, VILLA '], ['en']));
        self::assertSame('["فيلا","حديقة"]', $field->stateFromInput(['keywords' => 'فيلا، حديقة'], ['en']));
        self::assertSame('["a, b"]', TagsInput::make('t')->separator(';')->stateFromInput(['t' => 'a, b'], ['en']), 'another separator keeps commas');
        self::assertSame('["x","y"]', $field->stateFromInput(['keywords' => '["x","y"]'], ['en']), 'the JSON state of a draft');
        self::assertSame('["x","y"]', $field->stateFromInput(['keywords' => ['x', 'y']], ['en']), 'a repeater item');
        self::assertSame('', $field->stateFromInput(['keywords' => ' , '], ['en']));
        self::assertSame(['villa', 'sea view'], TagsInput::make('t')->separator(';')->values('villa; sea view'), 'old text split on the field\'s separator');
        $only = TagsInput::make('kind')->suggestions(['Sea view', 'Villa'])->onlySuggestions();
        self::assertSame('["Sea view","Villa"]', $only->stateFromInput(['kind' => 'sea VIEW, villa'], ['en']), 'the suggestion\'s spelling');
        self::assertSame(['validation.tag_unknown', ['tag' => 'Old tag']], $only->typeError('["Old tag"]'));
        $only->allowStored(['Old tag']);
        self::assertNull($only->typeError('["Old tag","Villa"]'), 'a suggestion removed since stays on the records that have it');
    }

    public function testTagsValidation(): void
    {
        $form = (new Form())->schema([TagsInput::make('keywords')->max(2)->maxLength(5), TagsInput::make('kind')->suggestions(['a', 'b'])->onlySuggestions()]);
        self::assertSame([], self::errors($form, ['keywords' => '["one","two"]', 'kind' => '["a"]']));
        self::assertStringContainsString('at most 2 tags', self::errors($form, ['keywords' => '["a","b","c"]', 'kind' => ''])['keywords']);
        self::assertStringContainsString('at most 5 characters', self::errors($form, ['keywords' => '["toolong"]', 'kind' => ''])['keywords']);
        self::assertStringContainsString('“c” is not one of the tags', self::errors($form, ['keywords' => '', 'kind' => '["c"]'])['kind']);
        self::assertStringContainsString('cannot contain “,”', self::errors($form, ['keywords' => '["a,b"]', 'kind' => ''])['keywords']);
    }

    public function testTagsRenderOneTextBoxWithTheChipsData(): void
    {
        $html = TagsInput::make('keywords')->suggestions(['villa', 'sea "view"'])->max(4)->renderControl('f-keywords', 'keywords', '["villa","sea view"]');
        self::assertStringContainsString('name="keywords" placeholder="Separate tags with “,”" type="text" value="villa, sea view"', $html);
        self::assertStringContainsString('data-tags-separator=","', $html);
        self::assertStringContainsString('data-tags-max="4"', $html);
        self::assertStringContainsString('data-tags-suggestions="[&quot;villa&quot;,&quot;sea \&quot;view\&quot;&quot;]"', $html);
    }

    public function testTagsSeparatorMustBeAPunctuationMark(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TagsInput::make('t')->separator('a');
    }

    public function testTimePickerParsesNormalisesAndChecksTheRange(): void
    {
        self::assertSame('09:30:00', TimePicker::parse('9:30'));
        self::assertSame('21:30:15', TimePicker::parse('21:30:15'));
        self::assertSame('21:05:00', TimePicker::parse('9:05 PM'));
        self::assertSame('00:15:00', TimePicker::parse('12:15 am'));
        self::assertSame('21:30:00', TimePicker::parse('21.30'));
        self::assertNull(TimePicker::parse('24:00'));
        self::assertNull(TimePicker::parse('13:00 PM'));
        self::assertNull(TimePicker::parse('noon'));

        $field = TimePicker::make('opens_at')->minTime('08:00')->maxTime('20:00')->step(15);
        self::assertSame('09:30', $field->stateFromInput(['opens_at' => '9:30'], ['en']));
        self::assertSame('09:30', $field->stateFromRecord((object) ['opens_at' => '09:30:00'], 'en'), 'MySQL\'s TIME value');
        self::assertSame('09:30:00', $field->dehydrate('09:30'));
        self::assertNull($field->dehydrate(''));
        $form = (new Form())->schema([$field]);
        self::assertSame([], self::errors($form, ['opens_at' => '09:45']));
        self::assertStringContainsString('must be 20:00 or earlier', self::errors($form, ['opens_at' => '21:00'])['opens_at']);
        self::assertStringContainsString('must be 08:00 or later', self::errors($form, ['opens_at' => '07:45'])['opens_at']);
        self::assertStringContainsString('steps of 15 minutes', self::errors($form, ['opens_at' => '09:40'])['opens_at']);
        self::assertStringContainsString('a time like 09:30', self::errors($form, ['opens_at' => 'soon'])['opens_at']);
        self::assertStringContainsString('a time like 09:30', self::errors($form, ['opens_at' => $field->stateFromInput(['opens_at' => '09:30:10'], ['en'])])['opens_at'], 'seconds only with seconds(), never dropped silently');

        $html = $field->renderControl('f-o', 'opens_at', '09:30');
        self::assertStringContainsString('type="time" value="09:30" class="fa-input fa-time" min="08:00" max="20:00" step="900"', $html);
        self::assertStringContainsString('step="5"', TimePicker::make('t')->seconds()->step(5)->renderControl('f-t', 't', ''));
    }

    public function testTimePickerStepIsInMinutesUnlessSeconds(): void
    {
        $this->expectException(\LogicException::class);
        TimePicker::make('t')->step(90)->renderControl('f-t', 't', '');
    }

    public function testTimePickerRejectsABadMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimePicker::make('t')->minTime('8 o\'clock');
    }

    public function testTimesShowAsConfigured(): void
    {
        self::assertSame('09:30', Dates::timeOfDay('09:30:00'));
        self::assertSame('09:30:15', Dates::timeOfDay('09:30:15', true));
        self::assertSame('', Dates::timeOfDay('nope'));
        Admin::useConfig(['time_format' => 'g:i A'], true, sys_get_temp_dir());
        self::assertSame('9:30 PM', Dates::timeOfDay('21:30:00'));
        self::assertSame('<time datetime="21:30">9:30 PM</time>', (TimeColumn::make('opens_at'))->render((object) ['opens_at' => '21:30:00']));
        Admin::useConfig(['time_format' => '<script>'], true, sys_get_temp_dir());
        self::assertSame('H:i', Admin::config()->timeFormat(), 'only time letters');
    }

    public function testTagsColumnShowsChipsAndTheRestAsACount(): void
    {
        $column = TagsColumn::make('features')->options(['pool' => 'Pool', 'garden' => 'Garden'])->limit(2);
        $html = $column->render((object) ['features' => '["pool","garden","lift","<b>"]']);
        self::assertStringContainsString('<span class="fa-tag">Pool</span><span class="fa-tag">Garden</span>', $html);
        self::assertStringContainsString('<span aria-hidden="true">+2</span><span class="fa-sr">2 more: lift, &lt;b&gt;</span>', $html);
        self::assertStringContainsString('fa-muted', TagsColumn::make('x')->render((object) ['x' => null]));
    }

    public function testFilterValuesAreChecked(): void
    {
        $table = (new Table())->filters([
            CheckboxListFilter::make('features')->options(['pool' => 'Pool', 'lift' => 'Lift']),
            CheckboxListFilter::make('any')->column('features')->options(['pool' => 'Pool', 'lift' => 'Lift'])->multiple(),
            TagsFilter::make('keywords')->suggestions(['villa']),
            TimeRangeFilter::make('opens_at')->label('Opens'),
        ]);
        $set = new FilterSet($table, '{"features":"pool","any":{"o0":"","o1":"lift"},"keywords":" Sea   view ","opens_at":{"from":"9:00","until":"12:30"}}');
        self::assertSame([], $set->errors);
        self::assertSame(['features' => 'pool', 'any' => ['lift' => 'lift'], 'keywords' => 'Sea view', 'opens_at' => ['from' => '09:00', 'until' => '12:30']], $set->values);
        $chips = array_column($set->chips(), 'value', 'name');
        self::assertSame(['features' => 'Pool', 'any' => 'Lift', 'keywords' => 'Sea view', 'opens_at' => '09:00 – 12:30'], $chips);
        $html = $set->render('list');
        self::assertStringContainsString('data-admin-filter="any.o1" value="lift" checked', $html);
        self::assertStringContainsString('<datalist id="list-filter-keywords-list"><option value="villa"></option></datalist>', $html);
        self::assertStringContainsString('type="time" id="list-filter-opens_at" name="filter[opens_at][from]"', $html);

        $bad = new FilterSet($table, '{"any":{"o0":"sauna"},"opens_at":{"from":"12:00","until":"09:00"}}');
        self::assertArrayHasKey('any', $bad->errors);
        self::assertArrayHasKey('opens_at', $bad->errors);
        self::assertSame(['from' => '', 'until' => ''], $bad->values['opens_at']);
        self::assertSame([], (new FilterSet($table, '{}'))->active());
    }

    public function testCsvCellsBecomeLists(): void
    {
        self::assertSame(['Pool', 'Sea view'], Values::listCells('Pool | Sea view'));
        self::assertSame(['a', 'b'], Values::listCells('a, b'));
        self::assertSame(['a, b', 'c'], Values::listCells('a, b; c'));
        self::assertSame(['x'], Values::listCells('["x"]'));
        self::assertSame(['pool', 'sea_view', 'sauna'], Values::choices(self::features(), 'POOL | sea view | sauna'));
        self::assertSame('09:30:00', Values::time('9:30'));
        self::assertSame('09:30:00', Values::time('0.3958333333'));
        self::assertNull(Values::time('later'));
    }

    public function testRepeaterItemsMayHoldTheNewFieldsAndStoreListsAsLists(): void
    {
        $repeater = Repeater::make('slots')->schema([TimePicker::make('at'), CheckboxList::make('days')->options(['mon' => 'Mon', 'tue' => 'Tue']), TagsInput::make('notes')]);
        $state = $repeater->stateFromInput(['slots' => ['_' => '', 'k1' => ['_key' => 'k1', 'at' => '9:00', 'days' => ['_' => '', 'o1' => 'tue'], 'notes' => 'quiet, early']]], ['en']);
        self::assertSame([['_key' => 'k1', 'at' => '09:00', 'days' => ['tue'], 'notes' => ['quiet', 'early']]], json_decode((string) $repeater->dehydrate($state), true));
        // Read back from the stored list
        $again = $repeater->stateFromRecord((object) ['slots' => $repeater->dehydrate($state)], 'en');
        self::assertSame('["tue"]', $repeater->items($again)[0]['days']);
    }

    public function testBlockFormsMayHoldTheNewFields(): void
    {
        Admin::useConfig(['blocks' => [\FloCMS\Admin\Tests\Fixtures\Choices\HoursBlock::class]], true, sys_get_temp_dir());
        \FloCMS\Admin\Blocks\BlockRegistry::reset();
        try {
            $field = \FloCMS\Admin\Form\Fields\Builder::make('content');
            $state = $field->stateFromInput(['content' => ['_' => [
                'bh1' => ['type' => 'hours_test', 'order' => '0', 'f' => ['days' => ['_' => '', 'o0' => 'mon', 'o2' => 'wed'], 'notes' => 'quiet, early', 'opens' => '9:30']],
            ]]], ['en', 'ar', 'ku']);
            $errors = [];
            $field->validateBlocks($state, function ($item, $value, string $key) use (&$errors): array {
                $found = (new FormValidator(['en', 'ar', 'ku'], 'en'))->validate((new Form())->schema([$item]), [$item->getName() => $value]);
                return $found === [] ? [] : [$key => reset($found)];
            });
            self::assertSame([], $errors);
            $stored = json_decode((string) $field->dehydrate($state), true);
            self::assertSame('hours_test', $stored[0]['type']);
            self::assertSame(['mon', 'wed'], $stored[0]['data']['days'], 'a list stays a list, as in repeaters');
            self::assertSame(['quiet', 'early'], $stored[0]['data']['notes']);
            self::assertSame('09:30:00', $stored[0]['data']['opens']);
            // Read back into the editor
            $again = json_decode((string) $field->dehydrate($field->stateFromRecord((object) ['content' => $field->dehydrate($state)], 'en')), true);
            self::assertSame($stored[0]['data'], $again[0]['data']);
        } finally {
            \FloCMS\Admin\Blocks\BlockRegistry::reset();
        }
    }

    public function testSettingsAndPageFormsGetLists(): void
    {
        self::assertSame(['pool'], SettingsEditor::storable(self::features(), '["pool"]'));
        self::assertNull(SettingsEditor::storable(TagsInput::make('t'), ''));
        self::assertSame(['a', 'b'], PageForm::value(TagsInput::make('t'), '["a","b"]'));
        self::assertSame('08:15:00', PageForm::value(TimePicker::make('t'), '08:15'));
        self::assertSame('["pool"]', self::features()->stateFromRecord((object) ['features' => ['pool']], 'en'), 'a settings value is the list itself');
    }

    public function testActivityListsWhatWasAddedAndRemoved(): void
    {
        $form = (new Form())->schema([self::features(), TagsInput::make('keywords'), TimePicker::make('opens_at'), TextInput::make('title')]);
        $changes = ActivityChanges::diff($form,
            ['features' => '["pool","garden"]', 'keywords' => '["a","b"]', 'opens_at' => '09:00', 'title' => 'x'],
            ['features' => '["pool","parking"]', 'keywords' => '["b","a"]', 'opens_at' => '09:30', 'title' => 'x'], [], null);
        self::assertSame([
            ['field' => 'features', 'label' => 'Features', 'lang' => null, 'type' => 'item', 'before' => 'Garden', 'after' => ''],
            ['field' => 'features', 'label' => 'Features', 'lang' => null, 'type' => 'item', 'before' => '', 'after' => 'Parking'],
            ['field' => 'opens_at', 'label' => 'Opens at', 'lang' => null, 'type' => 'value', 'before' => '09:00', 'after' => '09:30'],
        ], $changes, 'the same tags in another order are not a change');
    }

    public function testTheGeneratorShowsTimeColumnsAsTimes(): void
    {
        self::assertSame('time', TableInspector::kind('time'));
        self::assertSame('time', TableInspector::kind('time', true));
        self::assertSame('date', TableInspector::kind('timestamp'));
        self::assertSame('date', TableInspector::kind('datetime'));
    }
}
