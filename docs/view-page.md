# The view page

Since 0.27 every record has a page that shows it without editing it: `/admin/<slug>/view/<id>`. It is built from the resource's form, so there is nothing to write for it. Each field is shown as its value (a badge for a status, Yes or No for a toggle, a link for an email address, a thumbnail for an image, a table for a repeater), in the same sections, tabs and sidebar as the edit page.

People who may view a resource but not edit it (`content.<slug>.view` without `update`) open records here. People who may edit open the edit page as before, and find **View** in each row's menu.

## What it shows

- **The header**: the record's title, who changed it last and when ("Updated 19 Oct 2026, 19:40 by Sara", from the activity log, else `updated_at`), and the buttons **History**, **Edit** and **More**. More holds the record's own actions (the row actions of the list), **Print** and **Delete**. Each button follows the person's permissions and `canEdit()` / `canDelete()`.
- **The language switch**, when the form has translatable fields: **Showing: EN · AR · KU**. It starts in the panel's language when the site has it. A field without text in the shown language says **Not translated**; it never shows another language's text instead. A dot on a language means something is not translated there. `?showing=ar` opens a language directly.
- **The fields**, as cards: each section is a card, tabs follow one another with their names as headings (so everything prints), collapsed sections are open, and fields outside a section share a card. Long fields (rich text, text areas, repeaters, galleries) take the whole row; on a phone everything is one column.
- **Related records** (relation managers) below, as on the edit page. Someone who may view the parent but not edit it sees each manager as a plain table of its first rows, with no buttons.
- **Empty values** show a dash.

Never shown: passwords, `Hidden` fields, fields hidden by a condition, and fields marked `->hiddenOnView()`.

## Choosing what it shows

Per field:

```php
TextInput::make('internal_note')->hiddenOnView(),

TextInput::make('price')->numeric()
    ->formatOnView(fn ($value, ?object $record) => number_format((float) $value) . ' €'),
```

`formatOnView()` returns text; it is escaped.

For a different set of fields, override `view()` (by default it returns `form()`):

```php
public function view(Form $form): Form
{
    return $form->schema([
        Section::make('summary')->label('Summary')->schema([
            TextInput::make('title')->translatable(),
            Select::make('status')->options([...]),
        ]),
    ]);
}
```

## Who opens what

```php
protected static bool $viewPage = true;     // false: no view page (404), rows open Edit as before
protected static string $rowOpens = 'edit'; // 'view': the row title opens the view page, for editors too

public function canView(object $record): bool
{
    return $record->status !== 'private';   // per record, like canEdit()
}
```

| Person | The row title opens | Row menu |
| --- | --- | --- |
| may edit the record | Edit | View, then the other actions |
| may edit, `$rowOpens = 'view'` | the view page | the actions (Edit on the view page) |
| may only view it | the view page | |
| neither (or `$viewPage = false` and no edit) | nothing; the title is plain text | |

The same choice is used everywhere a record is linked: Ctrl+K search, recent records, the Activity page, related fields (`BelongsTo`, `BelongsToMany`) and relation managers. `Resource::recordUrl($record)` gives it to your own code (null when the person may open neither page).

Viewers may also download the record's files (`FileUpload`), unless the field is `hiddenOnView()`. **History** is shown to people who may edit the record, and to people with `activity.view`.

## Printing

**More → Print** prints the values without the sidebar, buttons or language switch, with a line at the top: "Printed 20 Oct 2026 by Sara". Pressing Ctrl+P on the page does the same.

## Without JavaScript

The page works without JavaScript: the language switch is links, and History opens as its own page. Print and the copy buttons need JavaScript and are hidden without it.

## Words

The page's words are `view.*` in the panel's `en`, `ar` and `ku` files; override any of them in your site's `lang` files (`'admin.view.not_translated' => '…'`).
