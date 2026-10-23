# Wizard forms (0.39)

A wizard shows a long form as steps: **Details → Location → Pricing → Review**, one at a time, with **Back** and **Next** and a final **Create** or **Save**. Each step is checked on the server before the next one opens, and the record is saved once, at the end, by the normal save (so `beforeSave()` / `afterSave()`, the activity log, the trash and media usage work as on any form).

```php
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{BelongsTo, ImageUpload, MediaPicker, Select, TextInput, Toggle};
use FloCMS\Admin\Form\Layout\{Step, Wizard};

public function form(Form $form): Form
{
    return $form->schema([
        Wizard::make('listing')->schema([
            Step::make('details')->label('Details')->description('Name and type')->schema([
                TextInput::make('title')->translatable()->required(),
                Select::make('type')->options(['sale' => 'For sale', 'rent' => 'For rent'])->required(),
            ]),
            Step::make('location')->label('Location')->schema([
                BelongsTo::make('city_id')->resource(CityResource::class)->required(),
                TextInput::make('address'),
            ]),
            Step::make('terms')->label('Rental terms')->visibleWhen('type', 'rent')->schema([
                TextInput::make('lease_months')->numeric()->required(),
            ]),
            Step::make('pricing')->label('Pricing')->schema([
                TextInput::make('price')->numeric()->required(),
            ])->afterValidation(fn (array $state, ?object $record): array =>
                ($state['type'] ?? '') === 'rent' && (float) $state['price'] > 50000 ? ['price' => 'That looks like a sale price.'] : []),
            Step::make('photos')->label('Photos')->schema([
                ImageUpload::make('cover'),
                MediaPicker::make('gallery')->multiple(),
            ]),
        ])->summary(),
    ])->aside([
        Toggle::make('featured'),
    ]);
}
```

## The API

| Method | On | What it does |
| --- | --- | --- |
| `Wizard::make($name)->schema([...])` | Wizard | The steps, in order. A wizard takes `Step`s only. |
| `->summary()` | Wizard | Adds a last **Review** step that lists the answers, grouped by step, each group with an Edit button. Off by default. Not available on custom pages (their forms and header actions), which have no resource to show the values with. |
| `->startOn('location')` | Wizard | The step a new record's form opens on (default: the first). |
| `->submitLabel('Publish listing')` | Wizard | The final button's text on the record's create and edit pages (default Create / Save). In an action's form the action's own label is used. |
| `Step::make($name)->schema([...])` | Step | Fields and layouts (`Section`, `Grid`, `Tabs`), as anywhere in a form. |
| `->label()`, `->description()` | Step | Shown in the steps header and above the step's fields. |
| `->icon('map-pin')` | Step | An icon from the panel's set in place of the step's number, until the step is done. |
| `->columns(1–4)` | Step | The grid of the step's fields. |
| `->visibleWhen('type', 'rent')` | Step | The step is shown only while a field of an earlier step has one of these values (the same rule as `Field::visibleWhen()`). A hidden step is skipped: its fields are not shown, not checked and not saved (they keep the record's value). |
| `->afterValidation(fn (array $state, ?object $record): array)` | Step | Extra checks once the step's own rules pass. Return `'field' => 'message'` errors, or `[]`. They run on Next and again on the final submit. |

Rules, checked when the form is built (each breaks with a message that says what to change):

- **A wizard is the whole form.** Put every field of `schema()` in its steps. `aside()` fields may sit beside it: they show next to every step on the full page (after it in a dialog) and are checked on the final submit.
- A `Step` goes directly in a `Wizard`, and a `Wizard` never sits inside another layout: wizards are never nested, and repeater items and blocks can't be wizards.
- Step names are unique in the wizard; field names stay unique across the whole form (they are the columns, as always).
- **Passwords go on the last step** (or in `aside()`). A typed password is never printed back into the page, so on an earlier step it would be lost on Next.
- A step's `visibleWhen()` names a field of an **earlier** step (or an `aside()` field) with one plain value (not an upload, a password or a translatable field). The first step is always shown.
- Settings pages and custom pages' filters can't be wizards.

## How it moves

- **Next** checks the current step only, with the form's own rules (`required`, `max`, `unique`, `requiredWhen()`…, exactly as on save), then its `afterValidation()`. With errors the step stays, its fields show their messages and the steps header marks it ("2 problems"). Later steps are never checked early.
- **Back** and the **step buttons** never check or save. On a new record you can go back to any step you reached, and forward only by Next; on the edit page every step can be opened from the header.
- **Enter** in a text field means Next on a new record (Create on its last step), and Save when editing.
- The **final submit** checks every shown step. When something fails, the form opens on the **first step with an error**, and the message names the step: "Please check: Price (Pricing), City (Location)".
- **Nothing is saved before the final submit.** Every field of every step is in the one form (the steps not shown are hidden), so every value is sent each time and nothing is lost between steps.
- **Leaving** after the first Next asks first, like any form with unsaved changes.
- Live fields (`visibleWhen()`, dependent options) and repeater buttons work inside a step and keep it.

## Where it works

| Place | |
| --- | --- |
| Create page | Steps in order; Create on the last step. |
| Edit page | Every step reachable from the header; Save and Save and close on every step. |
| Create and edit dialogs and slide-overs (`$formMode`) | In the dialog; the dialog's own button waits for the last step on a new record. Relation managers use the same dialogs. |
| Action forms (`Action::form()`), on their own page or in a dialog | Steps in order; the action's button on the last step. |
| Bulk action forms | The same as action forms. |
| Custom page forms (`AdminPage::form()`) and their header actions | Steps in order; the page's Save on the last step. |
| View page | Each step shows as its own card. |

## Without JavaScript

Every move is a normal form submit: the buttons send `_wizard` = `next`, `back` or `go:<step>`, and hidden inputs `_wizard_step` (the step shown) and `_wizard_reached` (the furthest reached) say where the wizard stands. With JavaScript the panel makes the same request without reloading the page (and Reactive does it inside dialogs), then moves focus to the new step's heading, or to the first field with an error.

## Uploads

A file chosen on an early step is uploaded with the next move (or at once, with JavaScript) and kept as a pending upload until the final save, as on any form with an error. Like every pending upload it expires after 30 minutes: a wizard left open longer asks for the file again.

## Translations

Translatable fields work inside steps with both the language badges and the page-level language switch (`$translationMode = 'page'`); the switch stays above the steps.

## Phones

The header becomes "Step 2 of 5 · Location" with a progress bar, and **All steps** opens the list (a step with errors still says so). On the create and edit pages Back and Next sit in a bar fixed at the bottom of the screen; in dialogs and on action pages they stay below the step.

## Strings

New words in `en`, `ar` and `ku`: `wizard.*`. The Arabic and Kurdish ones need a read by a native speaker.
