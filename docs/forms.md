# Forms: sections, tabs, responsive grids and dependent fields

Admin 0.6 keeps flat field names and existing resource/save hooks. Layouts only organize the form. The same definitions render in Reactive dialogs, custom/bulk action forms and standard pages.

## Organize a form

```php
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{TextInput, Textarea, Select};
use FloCMS\Admin\Form\Layout\{Section, Grid, Tabs, Tab};

public function form(Form $form): Form
{
    return $form->schema([
        Tabs::make('property')->label('Property editor')->schema([
            Tab::make('details')->label('Details')->schema([
                Section::make('content')->label('Property details')
                    ->description('Describe this property in your site languages.')
                    ->schema([
                        TextInput::make('title')->translatable()->required()->columnSpan('full'),
                        Textarea::make('description')->translatable()->columnSpan('full'),
                    ]),
            ]),
            Tab::make('location')->label('Location')->schema([
                Grid::make('address')->columns(2)->schema([
                    Select::make('country')->options(['iq' => 'Iraq', 'gr' => 'Greece'])->required(),
                    Select::make('city')->optionsFor('country', static fn (string $country): array => match ($country) {
                        'iq' => ['sulaimani' => 'Sulaymaniyah', 'erbil' => 'Erbil'],
                        'gr' => ['athens' => 'Athens', 'patras' => 'Patras'],
                        default => [],
                    })->required(),
                ]),
            ]),
        ]),
    ]);
}
```

`make()` takes a stable layout identifier, not a database column. Labels/descriptions accept site translation keys and are escaped. Tab names must be unique within a group; fields must be unique across the complete form. Layouts may nest up to eight levels with at most 256 total components. `Tabs::schema()` accepts `Tab` objects; sections and grids accept fields/layouts.

`Form::columns()` and layout `columns()` accept 1–4 desktop columns (default 2). `Field::columnSpan(1..4)` spans grid columns, clamped to the containing grid; `columnSpan('full')` spans its full width. Mobile forms use one column. A layout occupies the available parent width and defines its own inner grid.

Tabs support native buttons, ARIA selection/panel relationships, Home/End and direction-aware Arrow keys. Validation reveals the tab containing an invalid field; existing translation dialogs still open for secondary-language errors. Tab selection survives live form refreshes. Without JavaScript, the tab buttons remain hidden and every tab is a normal visible section, including translation fallbacks.

## Dependent and conditional fields

`Select::optionsFor('country', Closure(string): array)` declares its parent and computes its current allowed options on the server. It receives the canonical parent value, or an empty string when unavailable/invalid. Return an empty array for missing parents. Keep callbacks read-only and scope database queries to the current user/tenant with bound values; they can run multiple times while processing a request. Existing zero-argument `options(Closure)` remains supported.

```php
Select::make('listing_type')->options(['sale' => 'Sale', 'rent' => 'Rent'])->required(),

TextInput::make('price')->numeric()->nullable()
    ->visibleWhen('listing_type', 'sale')
    ->requiredWhen('listing_type', 'sale')
    ->rules('min:0'),

TextInput::make('lease_months')->numeric()->nullable()
    ->visibleWhen('listing_type', 'rent')
    ->requiredWhen('listing_type', 'rent')
    ->rules('integer|min:1|max:120'),
```

Conditions compare one scalar parent against a string or array of string values using strict equality; toggles use `'1'`/`'0'`. Required conditions replace the unconditional required flag for the resolved request. Dependencies can span tabs/sections and are evaluated in dependency order. Cyclic, missing, password or translated parent dependencies are configuration errors. Conditional visibility is a form workflow rule; resource permissions and record scope remain responsible for authorization.

A parent change refreshes the form without saving. Invalid dependent select values are cleared, while unrelated submitted drafts and the current tab remain. Hidden fields are omitted from rendering, validation and dehydration; crafted hidden values cannot control other dependencies or overwrite existing stored values. Hidden create fields are omitted from INSERT, so their database columns need nullable values or defaults. On edit, hidden columns retain their stored values. When showing a field again, enter/review its value before saving; hidden drafts are not kept in client snapshots.

The server also validates current child options and conditional requirements at final save, even when JavaScript is disabled or a request is crafted. Validations and writes retain transaction, permission, scope, stale-row and replay guards. Live refresh never calls the save/action callback or consumes the operation token. Password fields are blank after every server response, including a live refresh; enter passwords after changing dependency controls.

Reactive forms refresh on control **change** rather than each keystroke. Standard forms use the same asynchronous panel navigation. Without JavaScript, change the parent, press **Update fields**, then choose the dependent value and save. Update fields runs behind the normal authentication and CSRF checks and performs no write.

## Larger drafts and upgrades

Admin 0.6 requires Reactive `^0.4.0`; release/install Reactive first. Admin allows **64,000 UTF-8 bytes of complete JSON modal draft**, including all field names, values and translations. This uses Reactive's explicit 65,536-byte scalar allowance with transport/snapshot headroom. The existing full-page recovery path remains for larger records; copy unsaved text before leaving the dialog because the fallback does not transfer it. Nested/object state, repeaters, file uploads and relationship managers are separate features.

No package schema migration is required. Every stored field in your own form still needs its own database column. The property example needs title/description, country/city, listing type and pricing columns; the generator does not add them automatically. Existing flat resources and standard authentication keep working. Core and the skeleton acquire no new dependency or application-file change.

The repository's isolated browser demo includes `/en/admin/properties` (also `/ar` and `/ku`) with Details, Location, Pricing and Publishing tabs, conditional pricing, and row/bulk Relocate action forms. Its schema and resource are test fixtures, excluded from Composer distributions; adapt the definitions and migrations to your application rather than deploying the test router.
