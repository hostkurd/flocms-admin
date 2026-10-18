<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Activity\ActivityChanges;
use FloCMS\Admin\Form\Fields\{KeyValue, MediaPicker, Repeater, RichEditor, Select, Textarea, TextInput, Toggle};
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Translation\EditingLanguage;
use FloCMS\Admin\Validation\FormValidator;

/** Repeater and key-value fields (0.24): state, storage, rules, the no-JavaScript buttons, the activity log and the helpers. */
final class RepeaterUnitTest extends TestCase
{
    private static function rooms(): Repeater
    {
        return Repeater::make('rooms')->schema([
            TextInput::make('name')->translatable()->required()->rules('max:20'),
            TextInput::make('size')->rules('numeric'),
            Select::make('floor')->options(['0' => 'Ground', '1' => '1st']),
            Toggle::make('balcony'),
        ])->itemLabel('name')->maxItems(3)->distinct('name');
    }

    public function testStateFromInputKeepsTheOrderAndEachFieldsOwnHandling(): void
    {
        $field = self::rooms();
        $state = $field->stateFromInput(['rooms' => [
            '_' => '',
            'kb' => ['_key' => 'kb', 'name' => ['en' => ' Kitchen ', 'ar' => 'مطبخ', 'xx' => 'no'], 'size' => '16', 'floor' => '0'],
            'ka' => ['_key' => 'ka', 'name' => ['en' => 'Bedroom'], 'size' => '', 'balcony' => '1'],
        ]], ['en', 'ar', 'ku']);
        self::assertIsString($state);
        $items = $field->items($state);
        self::assertSame(['kb', 'ka'], array_column($items, '_key'), 'the order on the page');
        self::assertSame(['en' => 'Kitchen', 'ar' => 'مطبخ', 'ku' => ''], $items[0]['name'], 'trimmed, languages of the site only');
        self::assertSame('0', $items[0]['balcony'], 'an unticked toggle');
        self::assertSame('1', $items[1]['balcony']);
        self::assertTrue($field->submitted(['rooms' => ['_' => '']]), 'an emptied list is still sent');
        self::assertFalse($field->submitted([]));
        // A dialog's draft holds the JSON state itself
        self::assertSame($state, $field->stateFromInput(['rooms' => $state], ['en', 'ar', 'ku']));
        // Forged keys and duplicates get new keys
        $forged = $field->items($field->stateFromInput(['rooms' => [['_key' => '<b>', 'size' => '1'], ['_key' => 'kz', 'size' => '2'], ['_key' => 'kz', 'size' => '3']]], ['en']));
        self::assertSame('k0', $forged[0]['_key']);
        self::assertSame('kz', $forged[1]['_key']);
        self::assertMatchesRegularExpression('/^k[0-9a-f]{8}$/', $forged[2]['_key']);
    }

    public function testDehydrateDropsEmptyItemsAndStoresTranslationsAndIds(): void
    {
        $field = Repeater::make('rooms')->schema([TextInput::make('name')->translatable(), TextInput::make('size'), Toggle::make('balcony'), MediaPicker::make('photo'), MediaPicker::make('gallery')->multiple()]);
        $state = $field->encodeState([
            ['_key' => 'k1', 'name' => ['en' => 'Kitchen', 'ar' => ''], 'size' => '16', 'balcony' => '0', 'photo' => '42', 'gallery' => '["3","4"]'],
            ['_key' => 'k2', 'name' => ['en' => '', 'ar' => ''], 'size' => '', 'balcony' => '0', 'photo' => '', 'gallery' => ''],
        ]);
        self::assertSame('[{"_key":"k1","name":{"en":"Kitchen"},"size":"16","photo":"42","gallery":["3","4"]}]', $field->dehydrate($state));
        self::assertNull($field->dehydrate('[]'), 'no items: NULL');
        // Stored values read back the same
        $record = (object) ['rooms' => $field->dehydrate($state)];
        self::assertSame('Kitchen', $field->items($field->stateFromRecord($record, 'en'))[0]['name']['en']);
        self::assertSame('["3","4"]', $field->items($field->stateFromRecord($record, 'en'))[0]['gallery']);
        // Legacy JSON without keys gets stable keys
        self::assertSame(['k0', 'k1'], array_column($field->items('[{"size":"1"},{"size":"2"}]'), '_key'));
        self::assertSame([], $field->items('not json'));
    }

    public function testRulesRunPerItemWithItsPlaceAndLanguage(): void
    {
        $form = (new Form())->schema([self::rooms()]);
        $validator = new FormValidator(['en', 'ar', 'ku'], 'en');
        $state = ['rooms' => self::rooms()->encodeState([
            ['_key' => 'k1', 'name' => ['en' => 'Kitchen', 'ar' => str_repeat('ف', 25)], 'size' => 'big', 'floor' => '9', 'balcony' => '0'],
            ['_key' => 'k2', 'name' => ['en' => '', 'ar' => ''], 'size' => '', 'floor' => '', 'balcony' => '0'],
            ['_key' => 'k3', 'name' => ['en' => ''], 'size' => '20', 'floor' => '', 'balcony' => '0'],
            ['_key' => 'k4', 'name' => ['en' => 'kitchen'], 'size' => '', 'floor' => '', 'balcony' => '0'],
        ])];
        $errors = $validator->validate($form, $state);
        self::assertSame(['rooms.0.name.ar', 'rooms.0.size', 'rooms.0.floor', 'rooms.2.name', 'rooms.3.name'], array_keys($errors), 'the empty item 2 is left out');
        self::assertSame('Size must be a number.', $errors['rooms.0.size']);
        self::assertSame('Another item already has this Name.', $errors['rooms.3.name'], 'distinct, ignoring case');
        self::assertSame('Rooms → item 1: Name (العربية), Rooms → item 1: Size, Rooms → item 1: Floor, Rooms → item 3: Name, Rooms → item 4: Name', $form->errorSummary($errors));
        self::assertSame('ar', EditingLanguage::forErrors(['rooms.0.name.ar' => 'x'], 'en'), 'the last part names the language');
        self::assertSame('en', EditingLanguage::forErrors(['rooms.0.size' => 'x'], 'ar'));

        // Too few, too many, required
        $min = (new Form())->schema([Repeater::make('rooms')->schema([TextInput::make('size')])->minItems(2)]);
        self::assertSame(['rooms' => 'Add at least 2 items to Rooms.'], $validator->validate($min, ['rooms' => '[{"_key":"k1","size":"1"},{"_key":"k2","size":""}]']));
        $required = (new Form())->schema([Repeater::make('rooms')->schema([TextInput::make('size')])->required()]);
        self::assertSame(['rooms' => 'Add at least one item to Rooms.'], $validator->validate($required, ['rooms' => '[]']));
        $max = (new Form())->schema([Repeater::make('rooms')->schema([TextInput::make('size')])->maxItems(1)]);
        self::assertSame(['rooms' => 'Rooms can hold at most 1 items.'], $validator->validate($max, ['rooms' => '[{"_key":"k1","size":"1"},{"_key":"k2","size":"2"}]']));
        $large = (new Form())->schema([Repeater::make('rooms')->schema([Textarea::make('note')])]);
        self::assertArrayHasKey('rooms', $validator->validate($large, ['rooms' => json_encode([['_key' => 'k1', 'note' => str_repeat('x', 61000)]])]));
    }

    public function testOnlySimpleFieldsSitInsideItems(): void
    {
        foreach ([RichEditor::make('body'), TextInput::make('secret')->password(), TextInput::make('code')->unique(), TextInput::make('city')->visibleWhen('size', '1'), Repeater::make('inner')->schema([TextInput::make('a')])] as $field) {
            try {
                Repeater::make('rooms')->schema([$field]);
                self::fail('Accepted ' . $field->getName());
            } catch (\LogicException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Repeater::make('rooms')->schema([TextInput::make('_key')]);
    }

    public function testTheNoJavaScriptButtons(): void
    {
        $field = self::rooms();
        $state = $field->encodeState([['_key' => 'k1', 'name' => ['en' => 'A'], 'size' => '', 'floor' => '', 'balcony' => '0'], ['_key' => 'k2', 'name' => ['en' => 'B'], 'size' => '', 'floor' => '', 'balcony' => '0']]);
        self::assertNull($field->applyOperation($state, 'hours:add'), 'another field');
        self::assertSame(['k2', 'k1'], array_column($field->items($field->applyOperation($state, 'rooms:up:k2')), '_key'));
        self::assertSame(['k2', 'k1'], array_column($field->items($field->applyOperation($state, 'rooms:down:k1')), '_key'));
        self::assertSame(['k1', 'k2'], array_column($field->items($field->applyOperation($state, 'rooms:up:k1')), '_key'), 'the first stays first');
        self::assertSame(['k2'], array_column($field->items($field->applyOperation($state, 'rooms:remove:k1')), '_key'));
        $added = $field->items($field->applyOperation($state, 'rooms:add'));
        self::assertCount(3, $added);
        self::assertSame(['en' => '', 'ar' => '', 'ku' => ''], $added[2]['name'], 'an empty new item');
        self::assertCount(3, $field->items($field->applyOperation($field->applyOperation($state, 'rooms:add'), 'rooms:add')), 'never past maxItems');
        $form = (new Form())->schema([$field]);
        $values = ['rooms' => $state];
        self::assertTrue($form->applyRepeaterOperation($values, 'rooms:remove:k2'));
        self::assertCount(1, $field->items($values['rooms']));
        self::assertFalse($form->applyRepeaterOperation($values, ['rooms:add']));
    }

    public function testKeyValueWithFreeAndFixedKeys(): void
    {
        $free = KeyValue::make('specs')->translatableValues();
        $state = $free->stateFromInput(['specs' => ['ka' => ['key' => 'Parking', 'value' => ['en' => '2 spaces', 'ar' => 'مكانان']], 'kb' => ['key' => '', 'value' => ['en' => '']]]], ['en', 'ar']);
        self::assertSame('[{"key":"Parking","value":{"en":"2 spaces","ar":"مكانان"}}]', $free->dehydrate($state), 'no internal keys, empty pairs dropped');
        $errors = (new FormValidator(['en', 'ar'], 'en'))->validate((new Form())->schema([$free]), ['specs' => $free->encodeState([
            ['_key' => 'k1', 'key' => 'Parking', 'value' => ['en' => 'x']], ['_key' => 'k2', 'key' => 'parking', 'value' => ['en' => 'y']], ['_key' => 'k3', 'key' => '', 'value' => ['en' => 'z']]])]);
        self::assertSame(['specs.1.key', 'specs.2.key'], array_keys($errors), 'distinct keys; a value needs its key');

        $fixed = KeyValue::make('specs')->keys(['Parking', 'Floor']);
        $items = $fixed->items('[{"key":"floor","value":"3rd"},{"key":"Pool","value":"yes"}]');
        self::assertSame([['_key' => 'k0', 'key' => 'Parking', 'value' => ''], ['_key' => 'k1', 'key' => 'Floor', 'value' => '3rd']], $items, 'one row per key, unknown keys gone');
        // A forged key is replaced by the fixed one
        $posted = $fixed->stateFromInput(['specs' => ['k0' => ['key' => 'Hacked', 'value' => '1 space'], 'k1' => ['key' => 'Floor', 'value' => '']]], ['en']);
        self::assertSame('[{"key":"Parking","value":"1 space"}]', $fixed->dehydrate($posted));
        self::assertFalse($fixed->hasFreeItems());
        self::assertSame($posted, $fixed->applyOperation($posted, 'specs:add'), 'no adding to fixed keys');
    }

    public function testTheActivityLogDescribesItems(): void
    {
        $form = (new Form())->schema([self::rooms()]);
        $field = self::rooms();
        $before = ['rooms' => $field->encodeState([
            ['_key' => 'k1', 'name' => ['en' => 'Kitchen'], 'size' => '16', 'floor' => '0', 'balcony' => '0'],
            ['_key' => 'k2', 'name' => ['en' => 'Bedroom'], 'size' => '18', 'floor' => '1', 'balcony' => '0'],
            ['_key' => 'k3', 'name' => ['en' => 'Bathroom'], 'size' => '', 'floor' => '', 'balcony' => '0'],
        ])];
        $after = ['rooms' => $field->encodeState([
            ['_key' => 'k2', 'name' => ['en' => 'Bedroom', 'ar' => 'غرفة'], 'size' => '20', 'floor' => '1', 'balcony' => '1'],
            ['_key' => 'k1', 'name' => ['en' => 'Kitchen'], 'size' => '16', 'floor' => '1', 'balcony' => '0'],
            ['_key' => 'k4', 'name' => ['en' => 'Study'], 'size' => '', 'floor' => '', 'balcony' => '0'],
        ])];
        $changes = ActivityChanges::diff($form, $before, $after);
        $read = array_map(static fn (array $c): string => $c['label'] . ($c['lang'] !== null ? ' [' . $c['lang'] . ']' : '') . ' | ' . $c['type'] . ' | ' . $c['before'] . ' → ' . $c['after'], $changes);
        self::assertSame([
            'Rooms | item | Bathroom → ',
            'Rooms · Bedroom · Name [ar] | value |  → غرفة',
            'Rooms · Bedroom · Size | value | 18 → 20',
            'Rooms · Bedroom · Balcony | value | No → Yes',
            'Rooms · Kitchen · Floor | value | Ground → 1st',
            'Rooms | item |  → Study',
            'Rooms | order |  → ',
        ], $read);
        self::assertSame([], ActivityChanges::diff($form, $before, $before));
        $hidden = ActivityChanges::diff((new Form())->schema([self::rooms()->hiddenFromActivity()]), $before, $after);
        self::assertSame('hidden', $hidden[0]['type']);
        self::assertCount(1, $hidden);
    }

    public function testTheRendererDrawsItemsWithTheirOwnNamesAndErrors(): void
    {
        $field = self::rooms();
        $state = ['rooms' => $field->encodeState([['_key' => 'k1', 'name' => ['en' => 'Kitchen', 'ar' => ''], 'size' => 'x', 'floor' => '', 'balcony' => '0']])];
        $html = (new FormRenderer($state, ['rooms.0.size' => 'Size must be a number.'], ['en', 'ar'], 'en'))->render((new Form())->schema([$field]));
        self::assertStringContainsString('name="rooms[_]"', $html);
        self::assertStringContainsString('name="rooms[k1][_key]" value="k1"', $html);
        self::assertStringContainsString('name="rooms[k1][name][en]"', $html);
        self::assertStringContainsString('name="rooms[k1][size]"', $html);
        self::assertStringContainsString('class="fa-repeater-item has-error"', $html);
        self::assertStringContainsString('Size must be a number.', $html);
        self::assertStringContainsString('<span data-repeater-title>Kitchen</span>', $html);
        self::assertStringContainsString('name="_repeater" value="rooms:remove:k1" formnovalidate', $html);
        self::assertStringContainsString('name="_repeater" value="rooms:add"', $html);
        self::assertStringContainsString('<template data-repeater-template>', $html);
        self::assertStringContainsString('name="rooms[__KEY__][name][en]"', $html);
        self::assertStringNotContainsString(' style=', $html, 'the panel\'s CSP allows no inline styles');

        // Page-level translation counts the fields inside items too
        $page = new FormRenderer($state, [], ['en', 'ar'], 'en', languageSwitch: true, editing: 'ar', switchActive: true, sameNote: 'Same.');
        $page->render((new Form())->schema([TextInput::make('title')->translatable(), $field]));
        self::assertSame(['total' => 2, 'filled' => ['en' => 1, 'ar' => 0], 'errors' => []], $page->languageStats());
    }

    public function testTheWebsiteHelpers(): void
    {
        $rooms = '[{"_key":"k1","name":{"en":"Kitchen","ar":"مطبخ"},"size":"16","gallery":["3","4"]},{"_key":"k2","name":{"en":"Hall"}}]';
        self::assertSame([['name' => 'مطبخ', 'size' => '16', 'gallery' => ['3', '4']], ['name' => 'Hall']], repeater_items($rooms, 'ar'));
        self::assertSame([], repeater_items(null));
        self::assertSame([], repeater_items('{"en":"x"}'));
        self::assertSame(['Parking' => '2 spaces', 'Floor' => '3rd'], key_values('[{"key":"Parking","value":{"en":"2 spaces"}},{"key":"Floor","value":"3rd"},{"key":"","value":"x"}]', 'ar'));
    }

    public function testTheSizeIsCountedAsTheListTravelsInADialogDraft(): void
    {
        $field = \FloCMS\Admin\Form\Fields\Repeater::make('links')->schema([\FloCMS\Admin\Form\Fields\TextInput::make('url')])->maxItems(200);
        // About 39 KB of JSON with quotes and slashes: over the limit once encoded in the draft
        $items = [];
        for ($i = 0; $i < 120; $i++) { $items[] = ['_key' => 'k' . $i, 'url' => str_repeat('"/', 100)]; }
        $state = $field->encodeState($items);
        self::assertLessThan(48000, strlen($state));
        self::assertNotNull($field->typeError($state));
        self::assertNull($field->typeError($field->encodeState(array_slice($items, 0, 20))));
    }
}
