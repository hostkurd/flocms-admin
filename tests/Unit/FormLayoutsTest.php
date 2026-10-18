<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Form\{Form, FormRenderer};
use FloCMS\Admin\Form\Fields\{TextInput, Select};
use FloCMS\Admin\Form\Layout\{Section, Tabs, Tab};
use FloCMS\Admin\Validation\FormValidator;

final class FormLayoutsTest extends TestCase
{
    public function testLayoutsRetainUniqueFlatFieldsAndEscapeLabels(): void
    {
        $form = (new Form())->columns(3)->schema([Section::make('details')->label('<Details>')->schema([TextInput::make('title')]), Tabs::make('pages')->schema([Tab::make('location')->schema([TextInput::make('city')])])]);
        self::assertSame(['title', 'city'], array_keys($form->fields()));
        $html = (new FormRenderer(['title' => 'Villa', 'city' => 'Athens'], [], ['en'], 'en'))->render($form);
        self::assertStringContainsString('&lt;Details&gt;', $html);
        self::assertStringContainsString('name="city"', $html);
        self::assertStringContainsString('fa-grid-3', $html);
        self::assertStringContainsString('data-admin-tab-panel', $html);
        self::assertStringNotContainsString('role="tabpanel"', $html, 'Tab semantics are enabled only with JS; all content is available otherwise.');
    }
    public function testDuplicateNamesAcrossLayoutsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Form())->schema([TextInput::make('title'), Section::make('other')->schema([TextInput::make('title')])]);
    }
    public function testHiddenInputsCannotActivateDownstreamFields(): void
    {
        $form = (new Form())->schema([
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
            TextInput::make('gate')->visibleWhen('status', 'published'),
            TextInput::make('secret')->visibleWhen('gate', 'yes')->required(),
        ]);
        $state = ['status' => 'draft', 'gate' => 'yes', 'secret' => 'forged'];
        $resolved = $form->evaluate($state, (object) ['gate' => 'yes', 'secret' => 'stored']);
        self::assertFalse($resolved->field('secret')->isWritable());
        self::assertSame('stored', $state['secret']);
        self::assertSame([], (new FormValidator(['en'], 'en'))->validate($form, $state));
    }
    public function testDependentOptionsAreReevaluatedAndNeverAcceptAChildFromAnotherParent(): void
    {
        $form = (new Form())->schema([Select::make('country')->options(['iq' => 'Iraq', 'gr' => 'Greece']), Select::make('city')->optionsFor('country', static fn (string $country): array => $country === 'gr' ? ['athens' => 'Athens'] : ['erbil' => 'Erbil'])->required()]);
        $state = ['country' => 'gr', 'city' => 'erbil'];
        self::assertArrayHasKey('city', (new FormValidator(['en'], 'en'))->validate($form, $state));
        $form->refreshState($state); self::assertSame('', $state['city']);
        $state['city'] = 'athens'; self::assertSame([], (new FormValidator(['en'], 'en'))->validate($form, $state));
    }
    public function testCyclesAndUnknownDependenciesAreDeveloperErrors(): void
    {
        $form = (new Form())->schema([TextInput::make('one')->visibleWhen('two', '1'), TextInput::make('two')->visibleWhen('one', '1')]);
        $state = []; $this->expectException(\LogicException::class); $form->evaluate($state);
    }
    public function testRequiredConditionsAreEnforcedAndDefinitionsRemainReusable(): void
    {
        $form = (new Form())->schema([Select::make('kind')->options(['sale' => 'Sale', 'rent' => 'Rent']), TextInput::make('price')->requiredWhen('kind', 'sale')]);
        $validator = new FormValidator(['en'], 'en');
        self::assertArrayHasKey('price', $validator->validate($form, ['kind' => 'sale', 'price' => '']));
        self::assertSame([], $validator->validate($form, ['kind' => 'rent', 'price' => '']));
        self::assertFalse($form->field('price')->isRequired());
    }

    public function testErrorsNameTheirTabAndMarkIt(): void
    {
        $form = (new Form())->schema([
            TextInput::make('title')->translatable(),
            Tabs::make('listing')->schema([
                Tab::make('details')->label('Details')->schema([Section::make('money')->schema([TextInput::make('price')])]),
                Tab::make('media')->label('Media')->schema([TextInput::make('caption')]),
            ]),
        ])->aside([TextInput::make('status')]);
        $errors = ['price' => 'Price is required.', 'title.ar' => 'Too long.', 'status' => 'Pick one.', 'record' => 'This item was deleted meanwhile.'];
        self::assertSame('Price (Details), Title (العربية), Status, This item was deleted meanwhile.', $form->errorSummary($errors));
        // 0.23: a translation's error names its language; with a tab, both.
        self::assertSame('Title, Title (العربية)', $form->errorSummary(['title' => 'Required.', 'title.ar' => 'Too long.']));
        self::assertSame('', $form->errorSummary([]));
        $html = (new FormRenderer([], ['price' => 'Price is required.'], ['en'], 'en'))->render($form);
        self::assertMatchesRegularExpression('~data-admin-tab="[^"]*-0" class="has-error" aria-describedby="[^"]*-0-errors">Details<span class="fa-tab-error"~', $html);
        self::assertMatchesRegularExpression('~data-admin-tab="[^"]*-1">Media</button>~', $html);
    }
}
