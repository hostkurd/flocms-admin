# Repeaters and key-value lists

Admin 0.24 adds two fields that keep a short list inside one record: `Repeater` (a list of small items, each with its own fields: a flat's rooms, a tour's days, opening hours) and `KeyValue` (labels and values: a property's specs, social links). The list is stored as JSON in **one column** of the record, so there is no extra table, and it is saved, validated and logged with the rest of the form.

```php
use FloCMS\Admin\Form\Fields\{KeyValue, MediaPicker, Repeater, Select, TextInput, Toggle};

Repeater::make('rooms')
    ->schema([
        TextInput::make('name')->translatable()->required()->rules('max:60'),
        TextInput::make('size')->numeric()->label('Size (m²)'),
        Select::make('floor')->options(['0' => 'Ground', '1' => '1st', '2' => '2nd']),
        MediaPicker::make('photo'),
    ])
    ->itemLabel('name')      // the card's title: "Master bedroom" instead of "Item 1"
    ->addLabel('Add room')
    ->columns(3)             // fields per row inside a card (1–4)
    ->maxItems(6)
    ->distinct('name'),      // two rooms can't have the same name

KeyValue::make('specs')
    ->keyLabel('Feature')->valueLabel('Details')
    ->translatableValues(),  // "Parking → 2 covered spaces", the details per language
```

## Repeater or relation manager?

Both show "many things that belong to this record". Choose by how the things live:

| Use a **Repeater** when | Use a [relation manager](relation-managers.md) when |
| --- | --- |
| The items only make sense inside this record (rooms of a flat, steps of a recipe) | The items are records of their own (units you sell, cities with their own pages) |
| There are few (up to 50 by default) and they are small | There can be many, or they need search, filters and pages |
| They are edited and saved together with the record | Each is added, edited and deleted on its own, with its own permissions |
| The website reads them with the record | The website lists, filters or links to them |

If you later need to query items across records ("all flats with a balcony"), move them to a table with a relation manager.

## The editor

- **Cards** (the default) show each item with a number, its title, and buttons: move up, move down, duplicate, remove and fold. Folded cards show a short summary ("16 · Ground").
- **Rows**: `->table()` puts each item on one row with the field labels as column heads, good for two or three short fields (opening hours). `KeyValue` always uses rows.
- **Reordering**: drag the handle, use the up and down buttons, or focus the handle and press the arrow keys. The new place is read out to screen readers.
- **Remove and Undo**: a removed item can be brought back with **Undo** until the form is saved.
- **Without JavaScript** the Add, Up, Down and Remove buttons send the form; the page comes back with the list changed (nothing is saved and nothing is checked yet), so the list works everywhere.
- **Phones**: the fields stack in one column; Duplicate is hidden.
- **Dialogs**: repeaters work in Create and Edit dialogs and relation managers too.

## Options

| Repeater | |
| --- | --- |
| `->schema([...])` | The fields of one item (below) |
| `->itemLabel('name')` | The field whose value titles the card (in the default language); else "Item 3" |
| `->addLabel('Add room')` | The Add button's words |
| `->minItems(1)` / `->maxItems(12)` | How many items must / may be saved. Default maximum 50, at most 200 |
| `->columns(3)` | Fields per row inside a card, 1–4 |
| `->collapsed()` | Cards start folded |
| `->table()` | Rows instead of cards |
| `->distinct('name')` | No two items with the same value (compared without case, in the default language) |
| `->required()` | At least one item |

| KeyValue | |
| --- | --- |
| `->keyLabel('Feature')` / `->valueLabel('Details')` | Column heads (default "Key" / "Value") |
| `->translatableValues()` | One value per language; keys stay the same in every language |
| `->keys(['Parking', 'Floor', 'Heating'])` | Fixed keys, one row each in this order; editors fill in the values only |

Keys are required, distinct and at most 190 characters; values at most 1,000. A free key may be saved without a value (a feature such as "Pool"); with fixed keys, a key without a value is dropped on save. Rows with nothing filled in are always dropped.

### Fields inside an item

`TextInput`, `Textarea`, `Select`, `Toggle`, `Date`, `DateTime`, `ColorPicker`, `Hidden` and `MediaPicker`, with their usual options, rules and `->translatable()`. Not allowed (the field throws when the form is built, so you find out at once): passwords, `->unique()`, `->dependsOn()` and other dependent fields, `Slug`, `RichEditor`, file uploads, relationship fields, and repeaters inside repeaters. Use a relation manager for those.

## Storage

One JSON list per record, in a `TEXT` or `JSON` column (not `TINYTEXT` or `VARCHAR`; `php flo admin:doctor` warns):

```json
[
  {"_key": "k3f9a01c2", "name": {"en": "Master bedroom", "ar": "غرفة النوم الرئيسية"}, "size": "22", "floor": "1", "photo": "41"},
  {"_key": "k77b0d9e1", "name": {"en": "Kitchen"}, "size": "16", "floor": "0"}
]
```

- Translatable fields hold one object per field, as elsewhere; empty languages are left out.
- `_key` is a short id that keeps an item's identity while it moves, so the History can say which room changed. `KeyValue` stores only `key` and `value`.
- A `MediaPicker` stores an id (or a list of ids with `->multiple()`); the media library knows the item is used by the record.
- An empty list is stored as `NULL`. A list may hold about 48 KB (counted as it travels in a dialog, where quotes and slashes take two bytes), so it fits in a dialog with the rest of the form.
- Columns you filled yourself in this shape, with or without `_key`, open fine.

Adding a field to `schema()` later needs no migration: old items show it empty. A field you remove is dropped from an item the next time the record is saved.

## On the website

```php
<?php foreach (repeater_items($flat->rooms) as $room): ?>
    <h3><?= e($room['name'] ?? '') ?></h3>       <!-- in the page's language -->
    <?php if (($room['size'] ?? '') !== ''): ?><p><?= e($room['size']) ?> m²</p><?php endif; ?>
<?php endforeach; ?>

<?php foreach (key_values($flat->specs) as $feature => $details): ?>
    <dt><?= e($feature) ?></dt><dd><?= e($details) ?></dd>
<?php endforeach; ?>
```

`repeater_items($value, ?$lang)` returns the items in order, translatable values in the page's language (falling back to the default language) and without `_key`. Empty fields and unticked toggles are not stored, so read every field with `?? ''` (or `?? '0'`). `key_values($value, ?$lang)` returns `key => value` (a numeric key such as `2` comes back as an integer key, as in any PHP array). Both accept the JSON text or a decoded array, and return `[]` for an empty column.

## Settings pages

Both fields work on [settings pages](settings.md); the list is stored as the setting's value, so `setting('contact.links')` is an array:

```php
KeyValue::make('links')->label('Social links')->keyLabel('Network')->valueLabel('Address'),

// in a view
foreach (key_values(setting('contact.links')) as $network => $address) { … }
```

## Errors, translation and History

- An error inside an item marks the card and the field, and the message names the place: "Please check: Rooms → item 3: Name".
- With [page-level translation](forms.md#page-level-translation-023), the items' translatable fields follow the "Editing" switch and count in it. Adding, removing and moving items happen in the default language: outside it the list is locked and only the texts can be translated.
- The History names the item that changed: a field reads "Rooms · Kitchen · Size" with its old and new value, and added items, removed items and a new order each get their own line.
