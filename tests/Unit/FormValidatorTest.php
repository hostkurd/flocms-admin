<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Validation\FormValidator;
use InvalidArgumentException;

final class FormValidatorTest extends TestCase
{
    private function validate(Form $form, array $state, ?\Closure $isTaken = null, array $skip = []): array
    {
        return (new FormValidator(['en', 'ar', 'ku'], 'en', $isTaken))->validate($form, $state, $skip);
    }

    public function testRequiredOnlyAppliesToTheDefaultLanguage(): void
    {
        $form = (new Form())->schema([TextInput::make('title')->translatable()->rules('required|max:5')]);

        self::assertSame(['title' => 'Title is required.'], $this->validate($form, ['title' => ['en' => '', 'ar' => 'فيلا']]));
        self::assertSame([], $this->validate($form, ['title' => ['en' => 'Villa', 'ar' => '', 'ku' => '']]));
    }

    public function testOtherRulesApplyToEveryFilledLanguage(): void
    {
        $form = (new Form())->schema([TextInput::make('title')->translatable()->rules('required|max:5')]);

        self::assertSame(
            ['title.ar' => 'Title must not exceed 5 characters.'],
            $this->validate($form, ['title' => ['en' => 'Villa', 'ar' => 'فيلا كبيرة', 'ku' => '']])
        );
    }

    public function testMinAndMaxCompareNumbersOnNumericFields(): void
    {
        $form = (new Form())->schema([TextInput::make('views')->numeric()->rules('integer|min:1|max:1000')]);

        self::assertSame([], $this->validate($form, ['views' => '999']));
        self::assertSame(['views' => 'Views must not be greater than 1000.'], $this->validate($form, ['views' => '5000']));
        self::assertSame(['views' => 'Views must be at least 1.'], $this->validate($form, ['views' => '0']));
        self::assertSame(['views' => 'Views must be a number.'], $this->validate($form, ['views' => 'abc']));
        self::assertSame(['views' => 'Views must be a whole number.'], $this->validate($form, ['views' => '2.5']));
    }

    public function testOptionalEmptyValuesPass(): void
    {
        $form = (new Form())->schema([TextInput::make('code')->rules('min:3|max:5')]);

        self::assertSame([], $this->validate($form, ['code' => '']));
        self::assertSame(['code' => 'Code must be at least 3 characters.'], $this->validate($form, ['code' => 'ab']));
    }

    public function testInRuleAndSelectOptions(): void
    {
        $form = (new Form())->schema([
            TextInput::make('size')->rules('in:s,m,l'),
            Select::make('type')->options(['sale' => 'Sale']),
        ]);

        self::assertSame(
            ['size' => 'Size must be one of the listed values.', 'type' => 'Type must be one of the listed values.'],
            $this->validate($form, ['size' => 'xl', 'type' => 'hack'])
        );
    }

    public function testUniqueAsksTheCallback(): void
    {
        $form = (new Form())->schema([TextInput::make('slug')->unique()]);
        $taken = static fn (Field $field, string $value): bool => $value === 'taken';

        self::assertSame(['slug' => 'This Slug is already used.'], $this->validate($form, ['slug' => 'taken'], $taken));
        self::assertSame([], $this->validate($form, ['slug' => 'free'], $taken));
    }

    public function testSkippedFieldsAreNotValidated(): void
    {
        $form = (new Form())->schema([TextInput::make('password')->password()->required()]);

        self::assertSame([], $this->validate($form, ['password' => ''], null, ['password']));
    }

    public function testMessagesUseTheAdminLanguage(): void
    {
        AdminLang::setLanguage('ku');
        $form = (new Form())->schema([TextInput::make('title')->label('ناونیشان')->required()]);

        self::assertSame(['title' => 'ناونیشان پێویستە.'], $this->validate($form, ['title' => '']));
    }

    public function testSiteLangFileOverridesPackageMessages(): void
    {
        self::siteLang(['admin.validation.required' => ':attribute cannot be empty!']);
        $form = (new Form())->schema([TextInput::make('title')->required()]);

        self::assertSame(['title' => 'Title cannot be empty!'], $this->validate($form, ['title' => '']));
    }

    public function testUnknownRulesAreADeveloperError(): void
    {
        $form = (new Form())->schema([TextInput::make('email')->rules('required|email')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown rule "email"');
        $this->validate($form, ['email' => 'x']);
    }
}
