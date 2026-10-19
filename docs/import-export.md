# CSV import and export

Admin 0.25 lets editors take a list out as a CSV file and bring rows back in from one:

- **Export** (in every list's toolbar): the records the list shows, with its search and filters, or only the ticked rows, as a UTF-8 CSV that opens in Excel with Arabic and Kurdish intact. An empty **template** gives the headings to fill in.
- **Import** (next to New in the page head): upload a file, check how its columns match the fields, see every row checked before anything is saved, then import. Each row goes through the form's own rules, exactly as if it was typed into the form. An import can be **undone** for 7 days.
- **Site → Imports** lists the imports of the last 30 days with who, when, how many rows and their result.

Websites don't change: no column is added to your tables.

## Setting up

```bash
php flo admin:install   # writes the migration of the admin_imports and admin_import_rows tables (nothing is overwritten)
php flo migrate         # creates them
php flo admin:doctor    # checks them, and the folder storage/admin-imports/
```

Export needs no table and works at once. Until the import tables exist, lists show no Import button.

Grant the permissions to the roles that should have them, in `config/config.php` or on **Site → Roles** (two new columns, Export and Import):

| Permission | Allows |
| --- | --- |
| `content.<slug>.export` | The Export menu and **Export selected** |
| `content.<slug>.import` | The Import button; it also needs `create`, and `update` to change existing records |

`content.*` (Editors in the skeleton) and `*` include both. Ticking Export or Import on the Roles page ticks View too.

## Export

The **Export** menu offers:

- **Download CSV · n records**: every record the list shows, all pages, with the list's search, filters and sort.
- **Readable headings**: labels ("Title (English)", "Status") and option names ("Published", "Yes") instead of field names (`title.en`, `status`, `1`). Such a file is for reading; one with field names imports back as it is.
- **Empty template for import**: just the heading row of the importable fields.

Tick rows and the bulk bar shows **Export selected** (lists with bulk actions have the checkboxes).

What the file holds:

| Field | Columns |
| --- | --- |
| Translatable fields | one per language: `title.en`, `title.ar`, `title.ku` |
| `BelongsTo` | the id (`city_id`) and the related title (`city_id.name`) |
| `BelongsToMany` | the ids (`1\|4`) and the titles (`features.names`: `Pool \| Garden`) |
| `Toggle` | `1` / `0` (`Yes` / `No` with readable headings) |
| `Select` | the stored key (its label with readable headings) |
| `MediaPicker` | the files' URLs (`\|` between several), to read; not imported |
| `FileUpload`, `ImageUpload` | the stored path; not imported |
| `RichEditor` | the HTML (one column per language when translatable); not imported |
| `KeyValue` | `Parking: 2 \| Pool: yes` |
| `Repeater` | the list's JSON |
| Passwords | never exported |

Text that starts with `=`, `+`, `-`, `@` or a tab gets an apostrophe in front (`'=SUM(A1)`), so a spreadsheet never runs it as a formula; the import takes it off again. Numbers stay numbers.

A file holds at most 50,000 rows (`'export' => ['limit' => …]`); narrow the list with a search or filter for more. Files are written while they download, 500 rows at a time, so memory stays low. Each export is noted in the activity log (who, which list, how many rows).

## Import

### 1. Upload

Drop a `.csv`, `.tsv` or `.txt` file (up to 10 MB and 10,000 rows by default) or choose one. Large files are sent in parts with a progress bar. The panel finds:

- **the separator**: comma, semicolon, tab or `|`;
- **the encoding**: UTF-8 (with or without BOM), UTF-16 (Excel's "Unicode text"), and Windows-1256 for Arabic files saved by older Excel versions (Windows-1252 on sites without Arabic-script languages). If the letters look wrong on the next screen, **Letters look wrong?** reads the file again in another encoding.

Nothing is saved until the last step.

### 2. Match columns

Each column of the file is matched to a field by its heading: field names first (`title.en`, `title[en]`, `title_en`), then labels in any panel language ("Title (العربية)", "Price"), then aliases. Change any match, or **Skip** a column. A column is used for one field at most.

Then choose what happens to rows whose record **already exists**:

- **Create new ones only**: such rows are reported, not imported.
- **Create new and update existing** (the default when the file has the key column): matched columns of existing records change; columns that are not in the file keep their values.
- **Update existing only**: rows without a match are skipped.

Existing records are found by **id**, or by the resource's import key (see below). Dates such as `05/10/2026` are read day first (5 October) or month first (May 10), as you choose.

### 3. Preview

Every row is checked with the form's rules (required, unique, options, numbers, dates, your own rules) and nothing is saved. You see how many rows are ready (new and updates), how many have errors and which existing records were found. The errors are listed with row, field, value and problem; **Download rows to fix** gives those lines back as a CSV with an Errors column, to correct and upload again.

A warning says how many values of existing records would be emptied by empty cells. Leave such columns out of the file, or skip them, to keep the values.

### 4. Import

Rows are saved in batches of 100 with a progress bar. Each batch is one database transaction. You can leave the page: the import pauses, and **Continue** (on the page, or on Site → Imports) picks it up. **Cancel** stops after the current batch; rows saved so far stay, and can be undone.

The result shows what was created, updated and skipped, with **Download skipped rows**.

### Undo

**Undo import** on the result page (or Site → Imports) puts things back:

- records the import created go to the **trash** (deleted for good when the trash is off);
- records it updated get their values from before the import back, many-to-many links included.

Undo is possible for 7 days (`'import' => ['undo_days' => …]`, `0` for no Undo). It is refused when a record was edited after the import, because Undo would lose that edit; the message names the records.

### Values the import understands

| Field | In the file |
| --- | --- |
| Numbers | `1500`, `1,500`, `1.500,50`, `$ 1 500`. In a semicolon file a comma is the decimal point (`12,5`) |
| `Toggle` | `1`/`0`, `yes`/`no`, `true`/`false`, `✓`, `نعم`/`لا`, `بەڵێ`/`نەخێر` and more |
| `Date`, `DateTime` | `2026-10-19`, `19/10/2026`, `19.10.2026`, `19/10/2026 14:30`, an Excel day number |
| `Select` | the key or the label, any case |
| `BelongsTo` | the related id, or its title in any language (`Erbil` or `أربيل`); an unknown or doubtful title is an error |
| `BelongsToMany` | ids or titles separated by `\|`, `;` or `,` |
| `ColorPicker` | `#0b6e91` or `0b6e91` |
| `KeyValue` | `Parking: 2 \| Pool: yes`, or the JSON of an export |
| `Repeater` | the JSON of an export |

Not imported: uploads, media fields, rich text, passwords, hidden and disabled fields. A translatable field without a column for a language keeps that language's text.

## Shaping it per resource

```php
use FloCMS\Admin\ImportExport\{Export, Import, ImportRun};

final class ListingResource extends Resource
{
    // protected static bool $exportable = false;   // no Export menu for this resource
    // protected static bool $importable = false;   // no Import button

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('reference')->required()->unique(),
            TextInput::make('price')->numeric()->importAliases(['Preis', 'السعر']), // more headings it matches
            TextInput::make('owner_phone')->hiddenFromExport(),                    // never in a file
            Select::make('status')->options([...])->notImportable(),               // never set from a file
            // ...
        ]);
    }

    public function export(Export $export): Export
    {
        return $export
            ->except(['internal_notes'])                                   // fields left out
            ->column('agent', fn (object $row) => agent_name($row->agent_id), 'Agent') // an extra column (export only)
            ->limit(5000);                                                 // lower than the panel's limit
    }

    public function import(Import $import): Import
    {
        return $import
            ->key('reference')                                             // finds existing records (a unique column)
            ->defaults(['status' => 'draft'])                               // new records: fields the file lacks
            ->beforeRow(function (array $row): array {                     // clean each row before it is checked
                $row['reference'] = strtoupper(trim((string) ($row['reference'] ?? '')));
                return $row;
            })
            ->afterImport(function (ImportRun $run): void {                // once, when an import has finished
                cache_forget('listings');
            });
    }
}
```

- `key()` names a field whose values are unique; `admin:doctor` warns when its column has duplicates. Without it, existing records are found by id.
- `beforeRow()` gets the row's values by field name (a translatable field: `['en' => …, 'ar' => …]`) and returns them. It runs before the existing record is looked up, so it may clean the key.
- `defaults()` fill fields of **new** records that have no column in the file.
- `canCreate()`, `canEdit($record)` and the form's rules apply to every row, as in the panel.

## Settings

In `config/admin.php`:

```php
'export' => ['limit' => 50000],           // false: no Export anywhere
'import' => [                             // false: no Import anywhere
    'max_bytes' => 10 * 1024 * 1024,      // file size (at most 64 MB)
    'max_rows' => 10000,                  // rows per file (at most 100,000)
    'batch_size' => 100,                  // rows per request (10–1000)
    'keep_hours' => 24,                   // how long an unfinished upload is kept
    'undo_days' => 7,                     // 0: no Undo
    'table' => 'admin_imports', 'rows_table' => 'admin_import_rows',
],
```

Uploaded files live in `storage/admin-imports/`, outside the website's public folder, readable only by the person who uploaded them. They are removed when an import ends or after `keep_hours`; the rows' Undo data after `undo_days`; imports from the list after 30 days. Panel pages do this at most once an hour, and `php flo admin:import-purge` does it from cron.

## Commands

The same import and export from the command line, for large files and scheduled jobs. Commands run as **System** in the activity log and need no permission.

```bash
php flo admin:export listings --output=listings.csv [--readable] [--search=villa] [--filter=status:published]
php flo admin:import listings listings.csv [--update | --update-only] [--key=reference] [--dates=mdy] [--dry-run]
php flo admin:imports                 # the imports of the last 30 days
php flo admin:import-undo 12          # undo import #12
php flo admin:import-purge            # remove old files and Undo data
```

`admin:import` matches columns by their headings, checks every row (printing the first errors), and imports unless `--dry-run`. Without `--update` or `--update-only` it only creates records. Without `--output`, `admin:export` prints the CSV.

## For developers

- Routes: `/admin/<slug>/export` (GET: `?q=`, `filter=`, `sort=`, `dir=`, `ids=1,2`, `headings=readable`, `template=1`), `/admin/<slug>/import` and `/admin/<slug>/import/<run>/<step>` (`match`, `preview`, `start`, `progress`, `step`, `cancel`, `undo`, `result`, `problems`), `/admin/imports`.
- Activity log entries: `exported`, `imported` (with created, updated and skipped counts) and `import_undone`. The rows an import saves are not logged one by one.
- Tests: the Import page's upload form is `form[data-import-upload]` with `[data-import-file]`; the running import is `[data-import-run]` with `[data-import-progress]`; without JavaScript it shows a **Continue** button that runs one batch per click.
