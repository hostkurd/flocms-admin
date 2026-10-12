<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Form\Fields\Date;
use FloCMS\Admin\Form\Fields\DateTime;
use FloCMS\Admin\Form\Fields\Hidden;
use FloCMS\Admin\Form\Fields\Placeholder;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\Slug;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Tests\TestCase;
use InvalidArgumentException;
use LogicException;

final class FieldsTest extends TestCase
{
    private const LANGS = ['en', 'ar', 'el'];

    public function testTranslatableStateFromRecordAndInput(): void
    {
        $field = TextInput::make('title')->translatable();
        $record = (object) ['title' => '{"en":"Villa","ar":"فيلا"}'];

        self::assertSame(['en' => 'Villa', 'ar' => 'فيلا'], $field->stateFromRecord($record, 'en'));
        self::assertSame(
            ['en' => 'Villa', 'ar' => 'فيلا', 'el' => ''],
            $field->stateFromInput(['title' => ['en' => ' Villa ', 'ar' => 'فيلا', 'xx' => 'ignored']], self::LANGS)
        );
        self::assertSame(['en' => '', 'ar' => '', 'el' => ''], $field->stateFromInput(['title' => 'not an array'], self::LANGS));
    }

    public function testTranslatableDehydratesToJsonOrNull(): void
    {
        $field = TextInput::make('title')->translatable();

        self::assertSame('{"en":"Villa","ar":"فيلا"}', $field->dehydrate(['en' => 'Villa', 'ar' => 'فيلا', 'el' => '']));
        self::assertNull($field->dehydrate(['en' => '', 'ar' => '']));
        self::assertSame('', TextInput::make('t')->translatable()->nullable(false)->dehydrate([]));
    }

    public function testOnlyTextFieldsCanBeTranslatable(): void
    {
        Textarea::make('body')->translatable();
        TextInput::make('link')->url()->translatable();

        $this->expectException(LogicException::class);
        Select::make('type')->translatable();
    }

    public function testNumberInputsCannotBeTranslatable(): void
    {
        $this->expectException(LogicException::class);
        TextInput::make('price')->numeric()->translatable();
    }

    public function testFieldNamesMustBeColumnNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TextInput::make('title; DROP TABLE');
    }

    public function testEmptyValuesAreEmptyStringsUnlessNullable(): void
    {
        self::assertSame('', TextInput::make('a')->dehydrate(''));
        self::assertNull(TextInput::make('a')->nullable()->dehydrate(''));
        self::assertNull(TextInput::make('price')->numeric()->dehydrate(''), 'numbers are nullable by default');
        self::assertSame('12.5', TextInput::make('price')->numeric()->dehydrate('12.5'));
    }

    public function testInputIsTrimmedButPasswordsAreNot(): void
    {
        self::assertSame("a\nb", TextInput::make('a')->stateFromInput(['a' => "  a\r\nb  "], self::LANGS));
        self::assertSame(' secret ', TextInput::make('p')->password()->stateFromInput(['p' => ' secret '], self::LANGS));
    }

    public function testPasswordIsHashedNeverShownAndKeptWhenEmptyOnEdit(): void
    {
        $field = TextInput::make('password')->password();

        self::assertSame('', $field->stateFromRecord((object) ['password' => '$2y$hash'], 'en'));
        self::assertTrue($field->skipsWrite('', true));
        self::assertFalse($field->skipsWrite('', false));
        self::assertTrue(password_verify('secret', (string) $field->dehydrate('secret')));
        self::assertSame('plain', TextInput::make('p')->password(false)->dehydrate('plain'));
    }

    public function testEmailAndUrlChecks(): void
    {
        self::assertNull(TextInput::make('e')->email()->typeError('a@b.co'));
        self::assertSame('validation.email', TextInput::make('e')->email()->typeError('nope')[0]);
        self::assertSame('validation.url', TextInput::make('u')->url()->typeError('javascript:alert(1)')[0]);
    }

    public function testToggle(): void
    {
        $field = Toggle::make('featured');

        self::assertSame('0', $field->stateFromRecord(null, 'en'));
        self::assertSame('1', $field->stateFromRecord((object) ['featured' => 1], 'en'));
        self::assertSame('0', $field->stateFromInput([], self::LANGS));
        self::assertSame('1', $field->stateFromInput(['featured' => '1'], self::LANGS));
        self::assertSame(1, $field->dehydrate('1'));
        self::assertSame(0, $field->dehydrate('0'));
        self::assertStringContainsString('<input type="hidden" name="featured" value="0">', $field->renderControl('f', 'featured', '1'));
        self::assertStringContainsString(' checked', $field->renderControl('f', 'featured', '1'));
    }

    public function testSelectOnlyAcceptsItsOptions(): void
    {
        $field = Select::make('status')->options(fn () => ['draft' => 'Draft', 2 => 'Two']);

        self::assertNull($field->typeError('draft'));
        self::assertNull($field->typeError('2'));
        self::assertSame('validation.in', $field->typeError('other')[0]);
        self::assertStringContainsString('<option value="draft" selected>Draft</option>', $field->renderControl('f', 'status', 'draft'));
    }

    public function testDates(): void
    {
        $date = Date::make('on');
        self::assertSame('2026-10-12', $date->stateFromRecord((object) ['on' => '2026-10-12 00:00:00'], 'en'));
        self::assertSame('', $date->stateFromRecord((object) ['on' => '0000-00-00'], 'en'));
        self::assertNull($date->typeError('2026-02-28'));
        self::assertSame('validation.date', $date->typeError('2026-02-30')[0]);
        self::assertNull($date->dehydrate(''));

        $dateTime = DateTime::make('at');
        self::assertSame('2026-10-12T14:30', $dateTime->stateFromRecord((object) ['at' => '2026-10-12 14:30:59'], 'en'));
        self::assertSame('2026-10-12 14:30:00', $dateTime->dehydrate('2026-10-12T14:30'));
        self::assertSame('validation.date', $dateTime->typeError('yesterday')[0]);
    }

    public function testSlugFillsItselfFromTheDefaultLanguage(): void
    {
        $slug = Slug::make('slug')->from('title');
        $state = ['title' => ['en' => 'Sea View Villa', 'ar' => 'فيلا'], 'slug' => ''];

        self::assertSame('sea-view-villa', $slug->prepare($state, 'en')['slug']);
        self::assertSame('kept', $slug->prepare(['title' => ['en' => 'X'], 'slug' => 'kept'], 'en')['slug']);
        self::assertSame('my-slug', $slug->stateFromInput(['slug' => 'My Slug'], self::LANGS));
    }

    public function testPlaceholderAndDisabledFieldsAreNotWritten(): void
    {
        $form = (new Form())->schema([
            TextInput::make('title'),
            TextInput::make('code')->disabled(),
            Placeholder::make('info')->content(fn (?object $r) => $r?->id ?? '-'),
            Hidden::make('kind')->default('page'),
        ]);

        self::assertSame(['title', 'kind'], array_keys($form->writableFields()));
        self::assertSame('7', Placeholder::make('info')->content(fn (?object $r) => $r?->id)->stateFromRecord((object) ['id' => 7], 'en'));
    }

    public function testDuplicateFieldNamesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Form())->schema([TextInput::make('title'), Textarea::make('title')]);
    }

    public function testRenderedValuesAreEscaped(): void
    {
        $html = TextInput::make('title')->renderControl('f-title', 'title', '"><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testRequiredHelpers(): void
    {
        $field = TextInput::make('a')->rules('max:5')->required();
        self::assertSame(['required', 'max:5'], $field->getRules());
        self::assertTrue($field->isRequired());
        self::assertSame(['max:5'], $field->required(false)->getRules());
    }
}
