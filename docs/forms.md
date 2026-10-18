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

## Pages, dialogs and slide-overs (0.12)

Create and Edit open as a **full page** by default: room for long forms, a sidebar, and a header with **Cancel · Save · Save and close** (plus **Save and create another** on new records) that stays visible while scrolling. Small records read better in a dialog over the list; choose per resource:

```php
protected static string $formMode = 'page';       // default
protected static string $formMode = 'modal';      // a dialog over the list (categories, tags)
protected static string $formMode = 'slide-over'; // a panel from the side of the window
```

`php flo make:admin-resource Tag --form=modal` writes the mode into a new resource. Set it per resource, or per action: `CreateAction::make()->modal()`, `EditAction::make()->slideOver()`, `EditAction::make()->page()`. Delete and custom actions are short confirmations and stay dialogs (a custom action may use `slideOver()`). An unknown `$formMode` falls back to `'page'` and `admin:doctor` reports it. Without Reactive (`'reactive' => false`), Create and Edit are always pages.

A dialog's body fits its content. To keep room in it (for example for a form whose fields appear as you choose), set `->modalMinHeight(320)` (pixels, or a CSS length such as `'20rem'`) on that action, or `'dialog_min_height' => 240` in `config/admin.php` for every dialog. The height never goes past the window.

On the page:

- **Save** (also Enter in a field and Ctrl/⌘+S) saves and stays on the record. **Save and close** returns to the list exactly as it was (search, filters, sort, page). **Save and create another** opens an empty form. **Cancel** goes back without saving.
- Leaving with unsaved changes — a sidebar link, the Back button, closing the tab — asks first. A form shown again after a validation error counts as unsaved.
- The page opens without a full reload, and works without JavaScript too.
- On phones the buttons sit in a bar at the bottom of the screen.
- The 64 KB limit of dialog drafts does not apply to pages.

### A sidebar for secondary fields

```php
return $form->schema([
    Section::make('project')->label('Project')->schema([/* name, summary, rich text */]),
    Section::make('seo')->label('SEO')->collapsed()->schema([TextInput::make('meta_title')]),
])->aside([
    Section::make('publishing')->label('Publishing')->schema([Select::make('status')->options([...]), Toggle::make('featured')]),
    Section::make('timeline')->label('Timeline')->schema([Date::make('starts_on')]),
]);
```

`aside()` takes the same fields and layouts as `schema()`; names stay unique across both. On the page the aside is a column on the end side (it flips in right-to-left languages) that stays in view while scrolling; below 1100 px it moves under the main fields. In a dialog it follows the main fields. Each top-level section is drawn as its own card.

### Folding sections

`Section::make('seo')->collapsible()` adds a fold control (open by default); `->collapsed()` starts it folded. It is a native `<details>`, so it works without JavaScript, and a section that holds a validation error always opens.

## Page-level translation (0.23)

On a site with more than one language, translatable fields are edited in one of two modes:

| Mode | The form | Best for |
| --- | --- | --- |
| `'page'` | An **Editing: language** switch shows the whole form in one language | Content: posts, properties, pages |
| `'field'` | The default-language input with a language badge; the badge opens every language | Small records with one or two translatable names: categories, cities |

```php
// config/admin.php: the panel default. Missing = 'field', so sites from before 0.23 do not change;
// php flo admin:install writes 'page' into a new config/admin.php.
'translation_mode' => 'page',

// On a resource, when it differs from the panel default ('' follows the panel)
protected static string $translationMode = 'field';
```

Storage is the same in both modes (one JSON object per translatable column), so there is no migration, the website is unchanged, and a resource can move between modes at any time. A site with one language sees neither: inputs are plain.

### The switch

The record's head shows **Editing: English 9/9 · Arabic 7/9 · Kurdish 0/9**: segmented buttons for up to four languages, a select for more. Each language shows how many translatable fields are filled, with a full (green), partial or empty ring; the default language is first. Picking a language turns every translatable field into that language's input, with its own direction (an Arabic pane is right to left inside an English panel, and the other way round). Rich text editors follow the switch instead of showing their own language tabs.

- **Shared fields** (price, city, photo, relations) stay visible but read-only outside the default language, with "Same in every language. Change it in English." A note above the form says which version is being edited.
- **The default language's text** shows in grey under an empty input in another language ("English: Sea view villa"); for rich text, the first 200 characters and **Show all**. It follows what is typed in the default language.
- **Next missing** jumps to the next empty field in the language being edited (opening a form tab or a folded section on the way). The counts and the label's dot (filled, hollow when missing, red with an error) update as you type.
- **Save** saves every language at once and comes back in the language you were editing.

### Which language opens

1. `?editing=ar` in the address opens a language directly; the switch keeps the address in step, so Reload and Back return to it.
2. Otherwise an existing record opens in the language you last saved in (remembered per user in the preferences; saving in the default language forgets it). A new record starts in the default language.
3. After a failed save the form opens on the language of the first error: the language you were editing when it has one, else the default language, else the first language with an error.

### Errors

Errors are never hidden behind the switch: a language with errors gets a red count in the switch, the input is marked, and the message at the top names the language: "Please check: Title (Arabic)" (the language's name comes from the site's `lng.<code>` key, else its own name: العربية). The count goes once the value is corrected. `required` still applies to the default language only; empty translations are allowed and fall back to the default language on the website.

### Dialogs and actions

A form without translatable fields shows no switch. Create and Edit dialogs show the same switch in their head, so a unit in a relation manager (0.22) is translated like its property: a dialog opened from a record page being translated starts in the page's language, otherwise in the language you last saved in. Custom action forms with translatable fields get the switch too, in the dialog and on the action's full page. Bulk action forms keep the badges.

Settings pages use the same switch under their title (since 0.23.1), with the counts, **Next missing** and the reference lines.

### What still needs translating

```php
use FloCMS\Admin\Table\Columns\TranslationsColumn;

$table->columns([
    TextColumn::make('title')->searchable(),
    TranslationsColumn::make('translations'),   // EN 4/4 · AR 3/4 · KU 0/4
]);
```

`TranslationsColumn` counts the resource's translatable form fields (or `->fields(['title', 'summary'])`) from the same JSON columns, with a full, partial or empty mark per language; it is not sortable or searchable and works in either mode. It adds a **Missing in: language** filter to the list (records where at least one counted field is empty in that language; empty rich text counts as missing) unless `->withoutFilter()`. The filter can also be declared on its own: `MissingTranslationFilter::make('todo')->label('To translate')`. The counted fields must be columns of the table (`admin:doctor` checks them).

### Without JavaScript

The switch stays hidden and every language's input is shown with its language code, as on settings pages; shared fields stay editable. Saving works the same.

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
