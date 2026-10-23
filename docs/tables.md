# Editable cells, totals and grouped lists

Admin 0.38 adds three things to a resource's list:

- **Editable cells**: a switch, a select or a short text or number that people change straight from the list.
- **Totals**: sums, averages, counts, lowest and highest values under a column, for this page and for all the rows the list matches.
- **Grouping**: rows under heading rows (by city, status, month…) with counts and subtotals, chosen from a **Group by** menu.

Nothing to migrate. All three work in the standard list, the Reactive list, relation managers and without JavaScript. Custom pages' tables get totals (they stay read-only and are not grouped).

```php
use FloCMS\Admin\Table\{Table, Group};
use FloCMS\Admin\Table\Columns\{TextColumn, SelectColumn, TextInputColumn, ToggleColumn};
use FloCMS\Admin\Table\Summaries\{Average, Count, Sum};

public function table(Table $table): Table
{
    return $table->columns([
        TextColumn::make('title')->searchable(),
        SelectColumn::make('status')->colors(['published' => 'green', 'draft' => 'yellow']),
        TextInputColumn::make('price')->numeric(2)->prefix('€ ')
            ->summarize([Sum::make()->prefix('€ '), Average::make()->decimals(0)->prefix('€ ')]),
        ToggleColumn::make('featured')->summarize([Count::make()->where(1)->label('Featured')]),
    ])->groups([
        Group::make('city_id')->label('City'),
        Group::make('status'),
        Group::make('created_at')->date('month')->label('Month added'),
    ]);
}
```

## Editable cells

| Column | In the list | Works with the form field |
|---|---|---|
| `ToggleColumn` | a switch that saves at once | `Toggle` (1 / 0), or a `Select` that offers both of `->values()` |
| `SelectColumn` | a select shaped like a badge, saves on change | `Select` (not one whose options depend on another field) |
| `TextInputColumn` | the value as text; a click or Enter opens an input | `TextInput` (not a password) |

The value goes through **the form field of the same name**: its rules (`required`, `max`, `unique`, `min`…), its options and the way it is stored. A column without a form field uses its own rules: `TextInputColumn::make('code')->rules('required|max:12')`, `SelectColumn::make('size')->options([...])`.

Each save follows the rules of the edit page:

- **Who may edit**: the resource's `update` permission and `canEdit($record)`, the same check as the Edit button (`Resource::mayEdit()`). People who may not edit a row, viewers and read-only relation managers see the value only.
- **Only the list's own editable columns** can be saved; the column name the browser sends is checked against the list.
- **Nobody else's change is overwritten**: the save is refused when that column changed since the list was drawn (a change to another column of the row doesn't count). The cell then shows the current value with a message.
- **Hooks**: `beforeSave()` and `afterSave()` run, `updated_at` moves, and the activity log gets an "updated" entry with the one field. `$data` in `beforeSave()` holds only the cell's column (and a relation manager's parent key): check with `array_key_exists()` before reading other fields.
- **Fields that depend on the column** (`requiredWhen()`, `visibleWhen()`) are checked with the record's values: switching Status to Sold is refused when the form requires a Note for sold records and the record has none.
- Translatable columns can't be edited in the list.

### Options

```php
ToggleColumn::make('state')->values('published', 'draft')       // other stored values than 1 / 0
    ->requiresConfirmation('Unpublish this listing?', 'Visitors will no longer see it.', 'Unpublish', danger: true)
    ->beforeUpdate(fn (mixed $value, object $record): array => ['published_at' => $value === 'published' ? date('Y-m-d H:i:s') : null])
    ->afterUpdate(fn (mixed $value, object $saved) => Cache::forget('listings'));

TextInputColumn::make('stock')->numeric(0)->suffix(' pcs')->rules('integer|min:0');
TextInputColumn::make('title')->limit(40);                       // the input keeps the whole text
```

- `requiresConfirmation($heading, $description, $button, $danger)`: a dialog asks first; without JavaScript a confirm page does. Heading and description may be `fn (object $record): string`.
- `beforeUpdate(fn ($value, $record): array)` returns other columns to write with the value; `afterUpdate()` runs after the save, in its transaction.
- `TextInputColumn`: `->numeric(?int $decimals)` (thousands separators, number keyboard, checked as a number), `->prefix()`, `->suffix()`, `->limit()`.

### Using them

- **Switch**: click or Space saves; it moves back with a message if the save fails.
- **Select**: choosing saves.
- **Text and number**: click the value (or Enter on it) to open the input. Enter or leaving the cell saves, Escape cancels, Tab saves and moves to the next editable cell. A refused value stays in the input with the message under it. On phones the open input has Cancel and Save buttons.
- After a save the totals and group headings update.
- **Without JavaScript** each cell is a small form with a Save button; the list comes back with a message.

A title column made editable loses its link to the record's page: the row's ⋯ menu still opens it.

## Totals

`->summarize([...])` on a column adds totals under the list:

| Summary | Shows |
|---|---|
| `Sum::make()` | the sum (0 when there are no values) |
| `Average::make()` | the average of the filled values (2 decimals unless whole) |
| `Count::make()` | how many rows have a value; `->where(1)` how many hold one value |
| `Min::make()`, `Max::make()` | the lowest and highest (numbers, or dates as stored) |

Each takes `->label()`, `->decimals()`, `->prefix()`, `->suffix()` and `->formatUsing(fn ($value): string)`. Numbers use the panel language's separators (`numbers.thousands`, `numbers.decimal`).

- **This page** adds up the rows shown (shown only when the list has more than one page).
- **All rows** is one query over every row the list matches: the resource's `query()`, a relation manager's parent, the tabs, the filters and the search. When a search or filter is active it says so.
- `$table->summaries('all')` or `('page')` shows only one of the two lines.
- No rows: no totals. CSV exports have no total row.
- Totals work on plain database columns and on `CountColumn` (the related records counted through the related resource's `query()`). A translatable or JSON column is refused with a message when the list is built.
- On custom pages, totals add up the rows of the page's own `query()` with its filters and search.

## Grouping

`$table->groups([...])` adds a **Group by** menu. The person's choice is remembered (like the Columns button) and kept in the address (`?group=city_id`, `?group=city_id:desc`, `?group=none`).

```php
$table->groups([
    Group::make('city_id')->label('City'),                       // the related titles (BelongsTo field or RelationColumn)
    Group::make('status')->direction('desc'),                    // the Select's labels and the column's colours
    Group::make('created_at')->date('month'),                    // "October 2026"; also 'day' and 'year'
    Group::make('owner_id')->titleUsing(fn (?string $key, object $firstRow): string => $firstRow->owner_name ?? 'Nobody'),
])->defaultGroup('status')                                       // until the person picks another (or none)
  ->groupingRequired();                                          // no "No grouping" choice
```

- **Titles** come from what the list already knows: a `BelongsTo` field or `RelationColumn` gives the related record's title, a `Select`, `SelectColumn` or `BadgeColumn` its label and colour, a date the month's name in the panel language. Empty values are their own group ("No City"), last.
- **Sorting**: the group comes first, the clicked column sorts inside each group. Relation groups are ordered by the related title. The menu has A → Z / Z → A (Oldest / Newest first for dates).
- **Pages** stay the same size. A group that goes on from the previous page repeats its heading, marked "continued".
- **Headings** show the group's row count and the column totals for **all** its rows, not only those on the page; the grand totals stay at the bottom.
- **Collapse** a group with its arrow, or all of them from the menu (JavaScript; every group is open without it). Collapsed groups stay collapsed while the list redraws after a save.
- **Selecting**: the checkbox in a heading selects the group's rows on this page.
- **Reorder** is off while the list is grouped. CSV export ignores grouping. Translatable columns can't be groups.
- On large tables, a relation group sorts by a subquery per row: an index on the foreign key keeps it quick.
