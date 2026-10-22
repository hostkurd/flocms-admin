# Checkbox lists, tags and times

Admin 0.37 adds three small fields used in almost every project:

- **`CheckboxList`**: several choices from a fixed list (a property's features, the days a shop opens).
- **`TagsInput`**: free words typed as chips (keywords, labels).
- **`TimePicker`**: a time of day (opening hours, a viewing slot).

CheckboxList and TagsInput keep their list as JSON in **one column** of the record (`["pool","parking"]`), so there is no extra table. TimePicker stores `HH:MM:SS` and fits a `TIME` column. All three work in forms, dialogs, repeater items, block forms, settings pages and custom page forms; in list columns, filters, the view page, the activity log, and CSV import and export. Without JavaScript they are plain checkboxes, one text box and the browser's own time input.

```php
use FloCMS\Admin\Form\Fields\{CheckboxList, TagsInput, TimePicker};

CheckboxList::make('features')
    ->options(['pool' => 'Pool', 'parking' => 'Parking', 'sea_view' => 'listing.sea_view'])
    ->columns(3)          // side by side on wide screens (1–4); one column on phones
    ->selectAll()         // "Select all" and "Clear"
    ->max(5),

TagsInput::make('keywords')
    ->suggestions(['villa', 'apartment', 'sea view'])
    ->max(10),

TimePicker::make('opens_at')
    ->minTime('08:00')->maxTime('20:00')
    ->step(15),           // every 15 minutes
```

The columns:

```php
// A migration (MySQL or MariaDB); TEXT works too, and SQLite takes any type
$table->json('features')->nullable();
$table->json('keywords')->nullable();
$table->time('opens_at')->nullable();
```

## CheckboxList

A checkbox per option. The chosen option **keys** are stored in the order of the options; the labels are only shown (labels may be lang keys, so they follow the panel's language).

| Method | What it does |
| --- | --- |
| `->options([...])` or `->options(fn () => [...])` | key => label |
| `->columns(3)` | 1–4 columns on wide screens; phones always show one |
| `->selectAll()` | "Select all" and "Clear" links next to the label. Select all is left out when `max()` is smaller than the options |
| `->searchable()` / `->searchable(false)` | A box that filters the options. On by default above 12 options |
| `->min(2)`, `->max(5)` | At least / at most this many choices. `min()` applies once something is chosen; add `->required()` to ask for at least one |
| `->whenMissing('“:value” was removed')` | The notice for a stored key that is no longer an option |

A count ("4 of 9 chosen") sits next to the label. At the maximum, the other boxes wait until one is unticked; the server checks the limits too.

**An option you remove** stays in the records that have it. Their edit page shows it with a notice ("“sauna” is no longer an option. It stays saved until you untick it.") and saves it again until someone unticks it, so taking an option out of `options()` never quietly loses data. A key that the form did not show is refused.

`->translatable()` is not available: the stored keys are the same in every language and the labels are translated.

## TagsInput

Free words as chips. **Enter** or a **comma** adds the typed word; the Arabic comma (،) counts as a comma, as Arabic and Kurdish keyboards type it. Pasting "villa, sea view, garden" makes three tags.

| Method | What it does |
| --- | --- |
| `->suggestions([...])` or `->suggestions(fn () => [...])` | Words offered while typing |
| `->onlySuggestions()` | Refuse words that are not among the suggestions |
| `->max(10)` | At most this many tags (the panel keeps at most 100) |
| `->maxLength(40)` | Characters per tag (default 50) |
| `->separator(';')` | The key that adds a tag besides Enter (default a comma). A tag cannot contain it |

On save, tags are trimmed, runs of spaces become one, empty ones are dropped, and duplicates are removed regardless of capitals: "Sea View" and "sea view" are one tag, and the first spelling stays. With `onlySuggestions()`, a tag takes the suggestion's spelling.

The keyboard: **Backspace** in the empty box selects the last tag, and Backspace again removes it. **←** and **→** move between tags (the other way round on right-to-left pages), **Delete** removes the tag you are on, and **Escape** goes back to typing. **↓** and **↑** move through the suggestions, and Enter picks one.

Tags are not translatable in 0.37: a tag per language would break filtering ("sea view" and "إطلالة بحرية" would be different tags). For a known set with translated labels, use a CheckboxList.

## TimePicker

The browser's own `<input type="time">`. It shows 12- or 24-hour time as the visitor's computer is set; that can't be changed from the page.

| Method | What it does |
| --- | --- |
| `->minTime('08:00')`, `->maxTime('20:00')` | The earliest and latest time. A range across midnight (22:00–02:00) is not supported |
| `->step(15)` | Every this many minutes (1–60), counted from `minTime()` or midnight |
| `->seconds()` | HH:MM:SS; `step()` is then in seconds |

The value is stored as `HH:MM:SS` (empty as `NULL`), and min, max and step are checked again on the server. In lists, view pages and the activity log, times show as `config/admin.php` says:

```php
'time_format' => 'H:i',     // 24-hour (default); 'g:i A' gives 2:30 PM
```

## In the list

```php
use FloCMS\Admin\Table\Columns\{TagsColumn, TimeColumn};
use FloCMS\Admin\Table\Filters\{CheckboxListFilter, TagsFilter, TimeRangeFilter};

$table->columns([
    TextColumn::make('title')->searchable(),
    TagsColumn::make('features')->options(self::FEATURES)->limit(3),
    TagsColumn::make('keywords')->searchable(),
    TimeColumn::make('opens_at')->sortable(),
])->filters([
    CheckboxListFilter::make('features')->options(self::FEATURES),
    TagsFilter::make('keywords')->suggestions(['villa', 'sea view']),
    TimeRangeFilter::make('opens_at')->label('Viewings start'),
]);
```

- **`TagsColumn`** shows the first `limit()` items (default 3) as chips and "+2" for the rest (their names on hover and for screen readers). With `options()` it shows labels instead of keys.
- **`TimeColumn`** shows the time as `time_format` says; `->timeFormat('g:i A')` for one column, `->seconds()` to show seconds.
- **`CheckboxListFilter`** finds records that have an option ("Has: Pool"). With `->multiple()` the options are checkboxes, and a record matches when it has **any** of the ticked ones.
- **`TagsFilter`** is a text box with the suggestions. It matches **whole tags, regardless of capitals** in any script: "Sea View" finds "sea view", but "sea" does not.
- **`TimeRangeFilter`** finds times between two times, both included.

The filters give the same results on MySQL, MariaDB and SQLite, also for Greek and Arabic.

## On the website

```php
@foreach(list_values($listing->keywords) as $tag)
    <span class="tag">{{ $tag }}</span>
@endforeach

{{ implode(', ', option_labels($listing->features, ['pool' => 'Pool', 'parking' => 'Parking'])) }}
```

- **`list_values($value)`** reads a stored list (JSON, or plain "a, b" text) as a list of strings.
- **`option_labels($value, $options)`** turns CheckboxList keys into labels, translated when they are lang keys; a key without a label stays as it is.

In repeater items, blocks, settings pages and custom page forms the value is already a list: `repeater_items()` and a block's data give `['tue', 'wed']`, `setting('site.tags')` an array, and `submit()` and `data()` of a custom page receive arrays.

## CSV import and export

Lists are exported as `pool | parking`; the readable export gives the labels (`Pool | Parking`). An import reads cells split by `|`, `;` or a new line (or by commas when there is no other separator), and accepts keys or labels for a CheckboxList. Times are exported as stored (`09:30:00`), readable as `09:30`, and imported from `9:30`, `09:30:15`, `9:30 PM` or a spreadsheet's time (0.3958…).

## Switching an existing column

A text column that already holds "pool, parking" can become a CheckboxList or TagsInput without a migration: plain text is read as a list (split on commas), and the record is saved as JSON the next time it is saved. The filters match those old rows too. To change the column's type to JSON on MySQL, convert the old rows first.

## Activity log

Changes to a list are logged as items added and removed ("Features · Added: Parking", "Features · Removed: Fireplace"); the same tags in another order are not a change. Times are logged as they are shown (09:00 → 09:30).
