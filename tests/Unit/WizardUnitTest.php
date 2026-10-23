<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Form\Fields\{Select, TextInput};
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Form\Layout\{Section, Step, Tab, Tabs, Wizard};
use FloCMS\Admin\Form\WizardFlow;
use FloCMS\Admin\Settings\SettingsPage;
use FloCMS\Admin\Tests\TestCase;

/** Wizard forms (0.39) without a database: the schema rules, the moves and the header. */
final class WizardUnitTest extends TestCase
{
    private static function form(bool $summary = false): Form
    {
        return (new Form())->schema([
            Wizard::make('listing')->summary($summary)->schema([
                Step::make('details')->label('Details')->schema([TextInput::make('title')->required(), Select::make('type')->options(['sale' => 'Sale', 'rent' => 'Rent'])]),
                Step::make('terms')->label('Terms')->visibleWhen('type', 'rent')->schema([TextInput::make('lease')->numeric()->required()]),
                Step::make('pricing')->label('Pricing')->schema([Section::make('money')->schema([TextInput::make('price')->numeric()->required()])])
                    ->afterValidation(static fn (array $state): array => (float) ($state['price'] ?? 0) > 1000 ? ['price' => 'Too high.'] : []),
            ]),
        ])->aside([TextInput::make('note')]);
    }

    public function testAWizardIsTheWholeFormWithStepsOnly(): void
    {
        $form = self::form();
        self::assertNotNull($form->wizard());
        self::assertSame('pricing', $form->stepOf('price'), 'a field in a section of a step');
        self::assertNull($form->stepOf('note'), 'an aside field belongs to no step');
        self::assertSame(['title', 'type'], $form->stepFields('details'));

        $refused = [
            'beside another field' => static fn () => (new Form())->schema([TextInput::make('a'), Wizard::make('w')->schema([Step::make('s')->schema([TextInput::make('b')])])]),
            'inside a section' => static fn () => Section::make('s')->schema([Wizard::make('w')]),
            'inside a tab' => static fn () => Tab::make('t')->schema([Wizard::make('w')]),
            'a step outside a wizard' => static fn () => Tabs::make('t')->schema([Step::make('s')]),
            'a step in a section' => static fn () => Section::make('s')->schema([Step::make('s')]),
            'a field directly in a wizard' => static fn () => Wizard::make('w')->schema([TextInput::make('a')]),
            'in aside' => static fn () => (new Form())->aside([Wizard::make('w')->schema([Step::make('s')->schema([TextInput::make('b')])])]),
            'no steps' => static fn () => (new Form())->schema([Wizard::make('w')]),
            'two steps of one name' => static fn () => (new Form())->schema([Wizard::make('w')->schema([Step::make('s')->schema([TextInput::make('a')]), Step::make('s')->schema([TextInput::make('b')])])]),
            'a password before the last step' => static fn () => (new Form())->schema([Wizard::make('w')->schema([Step::make('a')->schema([TextInput::make('pw')->password()]), Step::make('b')->schema([TextInput::make('b')])])]),
            'a step depending on a later field' => static fn () => (new Form())->schema([Wizard::make('w')->schema([Step::make('a')->visibleWhen('kind', 'x')->schema([TextInput::make('a')]), Step::make('b')->schema([TextInput::make('kind')])])]),
            'a step depending on an unknown field' => static fn () => (new Form())->schema([Wizard::make('w')->schema([Step::make('a')->visibleWhen('nope', 'x')->schema([TextInput::make('a')])])]),
            'an unknown start step' => static fn () => (new Form())->schema([Wizard::make('w')->startOn('zzz')->schema([Step::make('a')->schema([TextInput::make('a')])])]),
            'a settings page' => static fn () => SettingsPage::make('general')->schema([Wizard::make('w')->schema([Step::make('a')->schema([TextInput::make('a')])])]),
        ];
        foreach ($refused as $case => $build) {
            try { $build(); self::fail('Refused: ' . $case); }
            catch (\InvalidArgumentException|\LogicException) { self::assertTrue(true); }
        }
        // A password on the last step (or in aside) is fine.
        (new Form())->schema([Wizard::make('w')->schema([Step::make('a')->schema([TextInput::make('a')]), Step::make('b')->schema([TextInput::make('pw')->password()])])])->aside([TextInput::make('pw2')->password()]);
    }

    public function testAHiddenStepIsSkippedAndItsFieldsAreHidden(): void
    {
        $flow = WizardFlow::for(self::form(), false);
        self::assertSame(['details', 'pricing'], $flow->visibleSteps(['type' => 'sale']));
        self::assertSame(['details', 'terms', 'pricing'], $flow->visibleSteps(['type' => 'rent']));
        $state = ['type' => 'sale', 'lease' => '9'];
        self::assertFalse(self::form()->evaluate($state)->field('lease')->isVisible());
        self::assertSame(['details', 'pricing', '_review'], WizardFlow::for(self::form(true), false)->visibleSteps(['type' => 'sale']));
    }

    public function testMoves(): void
    {
        $flow = WizardFlow::for(self::form(), false);
        $state = ['title' => '', 'type' => 'rent', 'lease' => '', 'price' => '', 'note' => ''];
        $errors = static fn (): array => ['title' => 'Title is required.', 'lease' => 'Lease is required.', 'price' => 'Price is required.'];

        $next = $flow->handle(['_wizard' => 'next', '_wizard_step' => 'details'], $state, null, $errors);
        self::assertSame('details', $next['state']->step);
        self::assertSame(['title' => 'Title is required.'], $next['errors'], 'only the shown step');
        self::assertFalse($next['save']);

        $ok = static fn (): array => ['lease' => 'Lease is required.'];
        $moved = $flow->handle(['_wizard' => 'next', '_wizard_step' => 'details'], $state, null, $ok);
        self::assertSame('terms', $moved['state']->step);
        self::assertTrue($moved['state']->moved);
        self::assertSame(1, $moved['state']->reached);

        // Enter (no move) before the last step of a new record means Next.
        self::assertSame('terms', $flow->handle(['_wizard_step' => 'details'], $state, null, $ok)['state']->step);
        // A step hidden by a changed answer gives way to the next one shown.
        self::assertSame('pricing', $flow->handle(['_wizard' => 'go:pricing', '_wizard_step' => 'terms', '_wizard_reached' => 'pricing'], ['type' => 'sale'] + $state, null, $ok)['state']->step);
        self::assertSame('pricing', $flow->restore(['_wizard_step' => 'terms'], ['type' => 'sale'] + $state)->step);
        // Not past the furthest step reached on a new record; any step when editing.
        self::assertSame('details', $flow->handle(['_wizard' => 'go:pricing', '_wizard_step' => 'details'], $state, null, $ok)['state']->step);
        self::assertSame('pricing', WizardFlow::for(self::form(), true)->handle(['_wizard' => 'go:pricing', '_wizard_step' => 'details'], $state, null, $ok)['state']->step);
        // An unknown move only shows the form again.
        $odd = $flow->handle(['_wizard' => ['x'], '_wizard_step' => 'details'], $state, null, $ok);
        self::assertFalse($odd['save']);

        // The final submit: every error, the first step with one opens; afterValidation() too.
        $final = $flow->handle(['_wizard_step' => 'pricing', '_wizard_reached' => 'pricing'], $state, null, $errors);
        self::assertTrue($final['save']);
        self::assertSame('details', $final['state']->step);
        $high = $flow->handle(['_wizard_step' => 'pricing'], ['title' => 'A', 'type' => 'sale', 'price' => '5000'] + $state, null, static fn (): array => []);
        self::assertSame(['price' => 'Too high.'], $high['errors']);
        self::assertSame('pricing', $high['state']->step);
        self::assertSame([[], null], $flow->finalErrors([], ['title' => 'A', 'type' => 'sale', 'price' => '10'] + $state, null));
    }

    public function testTheHeaderPanelsAndButtons(): void
    {
        $form = self::form();
        $state = ['title' => 'Villa', 'type' => 'rent', 'lease' => '', 'price' => '', 'note' => ''];
        $flow = WizardFlow::for($form, false);
        $wizard = $flow->handle(['_wizard' => 'next', '_wizard_step' => 'details'], $state, null, static fn (): array => [])['state'];
        $html = (new FormRenderer($state, ['lease' => 'Lease is required.'], ['en'], 'en'))->wizard($wizard, '<button data-final>Create</button>')->render($form);
        self::assertStringContainsString('name="_wizard_step" value="terms"', $html);
        self::assertStringContainsString('aria-current="step"', $html);
        self::assertMatchesRegularExpression('#data-wizard-step="details"[^>]*hidden#', $html);
        self::assertStringContainsString('value="go:details"', $html, 'a done step is a button');
        self::assertStringNotContainsString('value="go:pricing"', $html, 'a step not reached is not');
        self::assertStringContainsString('1 problem', $html);
        self::assertStringContainsString('Next: Pricing', $html);
        self::assertStringContainsString('value="back"', $html);
        self::assertStringNotContainsString('data-final', $html, 'the final button waits for the last step');
        self::assertStringContainsString('Step 2 of 3', $html);

        $edit = WizardFlow::for($form, true)->initial($state);
        $html = (new FormRenderer($state, [], ['en'], 'en', true))->wizard($edit, '<button data-final>Save</button>')->render($form);
        self::assertStringContainsString('data-wizard-mode="edit"', $html);
        self::assertStringContainsString('value="go:pricing"', $html);
        self::assertStringContainsString('data-final', $html, 'Save on every step when editing');
    }

    public function testErrorsNameTheirStep(): void
    {
        $form = self::form();
        self::assertSame('Title (Details), Price (Pricing)', $form->errorSummary(['title' => 'x', 'price' => 'y']));
        self::assertSame('Title, Price', $form->errorSummary(['title' => 'x', 'price' => 'y'], [], false));
    }
}
