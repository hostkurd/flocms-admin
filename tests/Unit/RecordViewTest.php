<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Form\Fields\ColorPicker;
use FloCMS\Admin\Form\Fields\Hidden;
use FloCMS\Admin\Form\Fields\KeyValue;
use FloCMS\Admin\Form\Fields\Repeater;
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Form\Layout\Tabs;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\View\RecordView;
use FloCMS\Admin\View\ValueRenderer;

/** The view page's values and layout (0.27), without a database. */
final class RecordViewTest extends TestCase
{
    private static function value(object $field, mixed $state, string $lang = 'en'): string
    {
        return (new ValueRenderer(new PostResource(), null, $lang, 'en'))->render($field, $state);
    }

    public function testValuesAreEscapedAndShownByType(): void
    {
        self::assertSame('&lt;b&gt;Villa&lt;/b&gt;', self::value(TextInput::make('title'), '<b>Villa</b>'));
        self::assertStringContainsString('<a href="mailto:sara@example.test" dir="ltr">sara@example.test</a>', self::value(TextInput::make('email')->email(), 'sara@example.test'));
        self::assertStringContainsString('data-admin-copy="sara@example.test"', self::value(TextInput::make('email')->email(), 'sara@example.test'));
        self::assertStringContainsString('<a href="tel:+9647501234567"', self::value(TextInput::make('phone')->tel(), '+964 750 123 4567'));
        self::assertSame('javascript:alert(1)', self::value(TextInput::make('site')->url(), 'javascript:alert(1)'), 'only http(s) becomes a link');
        self::assertSame('<span dir="ltr">12</span>', self::value(TextInput::make('views')->numeric(), '12'));
        self::assertSame("<span class=\"fa-view-text\">One<br>\nTwo &amp; three</span>", self::value(Textarea::make('body'), "One\nTwo & three"));
        self::assertStringContainsString('<span>Yes</span>', self::value(Toggle::make('featured'), '1'));
        self::assertStringContainsString('<span>No</span>', self::value(Toggle::make('featured'), '0'));
        self::assertSame('Published', self::value(Select::make('status')->options(['published' => 'Published']), 'published'));
        self::assertStringContainsString('<input type="color" class="fa-view-swatch" value="#0b6e91" disabled', self::value(ColorPicker::make('accent'), '#0B6E91'));
        self::assertSame('&lt;img&gt;', self::value(ColorPicker::make('accent'), '<img>'));
        self::assertStringContainsString('class="fa-view-empty"', self::value(TextInput::make('title'), ''));
    }

    public function testFormatOnViewAndTranslations(): void
    {
        self::assertSame('12 rooms &amp; more', self::value(TextInput::make('rooms')->formatOnView(static fn ($value): string => $value . ' rooms & more'), '12'));
        $title = TextInput::make('title')->translatable();
        self::assertSame('فيلا', self::value($title, ['en' => 'Villa', 'ar' => 'فيلا'], 'ar'));
        self::assertStringContainsString('fa-view-missing', self::value($title, ['en' => 'Villa', 'ar' => ''], 'ar'), 'not translated, never the English');
        self::assertStringNotContainsString('Villa', self::value($title, ['en' => 'Villa'], 'ar'));
        self::assertStringContainsString('fa-view-empty', self::value($title, ['en' => '', 'ar' => ''], 'ar'), 'empty everywhere: empty');
        $renderer = new ValueRenderer(new PostResource(), null, 'ar', 'en');
        self::assertTrue($renderer->missing($title, ['en' => 'Villa']));
        self::assertFalse($renderer->missing($title, ['en' => '']));
        self::assertFalse($renderer->missing(TextInput::make('code'), ''));
    }

    public function testItemsPairsAndTables(): void
    {
        $repeater = Repeater::make('rooms')->schema([TextInput::make('name'), TextInput::make('size')])->itemLabel('name');
        $cards = self::value($repeater, [['name' => 'Kitchen', 'size' => '14 m²'], ['name' => '<i>Hall</i>', 'size' => '']]);
        self::assertStringContainsString('<ol class="fa-view-items">', $cards);
        self::assertStringContainsString('<span>Kitchen</span>', $cards);
        self::assertStringContainsString('&lt;i&gt;Hall&lt;/i&gt;', $cards);
        $table = self::value((clone $repeater)->table(), [['name' => 'Kitchen', 'size' => '14 m²']]);
        self::assertStringContainsString('<table class="fa-view-table">', $table);
        self::assertStringContainsString('<th scope="col">Size</th>', $table);

        $pairs = self::value(KeyValue::make('specs'), [['key' => 'Floors', 'value' => '2'], ['key' => '', 'value' => '']]);
        self::assertStringContainsString('<dl class="fa-view-pairs"><div><dt>Floors</dt><dd>2</dd></div></dl>', $pairs);
    }

    public function testLayoutCardsTabsAndHiddenFields(): void
    {
        $form = (new Form())->schema([
            TextInput::make('title'),
            Section::make('Details')->schema([TextInput::make('code'), TextInput::make('secret')->password(), Hidden::make('token'), TextInput::make('note')->hiddenOnView()]),
            Tabs::make('More')->schema([Tab::make('Body')->schema([Textarea::make('body')]), Tab::make('Empty')->schema([TextInput::make('gone')->hiddenOnView()])]),
        ]);
        $record = (object) ['id' => 1, 'title' => 'Villa', 'code' => 'X1', 'secret' => 'hash', 'token' => 't', 'note' => 'n', 'body' => 'Text', 'gone' => 'g'];
        $html = (new RecordView(new PostResource(), $form, $record, 'en', 'en', ['en']))->render();
        self::assertStringContainsString('<section class="fa-view-card"><div class="fa-view-grid fa-grid-2"><dl class="fa-view-field" data-field="title">', $html, 'loose fields share a card');
        self::assertStringContainsString('<h2 class="fa-view-card-title" id="fa-view-main-1-title">Details</h2>', $html);
        self::assertStringContainsString('<h3 class="fa-view-group-title">Body</h3>', $html, 'tabs follow one another');
        self::assertStringNotContainsString('>Empty<', $html, 'a tab with nothing shown is left out');
        self::assertStringContainsString('data-field="body"', $html);
        foreach (['secret', 'token', 'note', 'gone'] as $name) { self::assertStringNotContainsString('data-field="' . $name . '"', $html); }
        self::assertStringNotContainsString('hash', $html);
        self::assertStringContainsString('fa-span-full', $html, 'a textarea takes the whole row');
    }
}
