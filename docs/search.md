# Ctrl+K search

Admin 0.20 adds one search box for the whole panel. Press **Ctrl+K** (⌘K on a Mac), or `/` when the cursor is not in a field, or click the search field in the top bar. Type a few letters and the results arrive as you type, grouped:

1. **Pages and actions**: Dashboard, Media, Users, Settings, Activity, Trash, My profile, and "New post" for every resource the person may add to.
2. **Records**, one group per resource, in sidebar order.
3. **Settings** pages, found by their name, description and field labels.
4. **Media** items, by title, file name and alt text, with a thumbnail.
5. The site's own groups (below).

Each group shows up to 5 results. When there are more, **See all in Posts** opens the list already searched. Before typing, the box shows the last 8 records the person opened and the panel's pages.

| Key | Does |
| --- | --- |
| ↑ ↓ | Move through the results (and the "See all" links); they wrap round |
| Enter | Open the result |
| Ctrl+Enter (⌘Enter) | Open it in a new tab |
| Esc | Close |

`#42` (or `42`) finds the record with id 42 in every resource. On phones the field is a search icon and the box fills the screen. Without JavaScript the field opens a results page with the same groups.

Nothing is installed or migrated: search reads the tables the panel already knows.

## What is found

A record is found by the columns its table marks `->searchable()`, the same columns the list's own search box uses, in every language of a translated column. So the list and Ctrl+K always agree.

```php
public function table(Table $table): Table
{
    return $table->columns([
        TextColumn::make('title')->searchable(),   // the result's title, and what is matched
        TextColumn::make('slug')->searchable(),    // matched; shown as the detail line
        TextColumn::make('price'),                 // not searched
    ]);
}
```

- The **title** of a result is the record's title as the list shows it, in the panel's language.
- The **detail line** is the next searchable column.
- A title that starts with the typed words comes first, then the newest records.
- A resource without searchable columns is found only by `#id`.

## Who sees what

Search shows only what the person may open:

- a resource's records need its `view` permission, and they stay inside the resource's `query()` (one site, one owner);
- a record opens its edit page when the person has the `update` permission and `canEdit()` allows it, otherwise the list searched for its title;
- settings pages need their permission, media items `media.view`, Users `users.manage`, Activity `activity.view`;
- "New …" actions need the `create` permission and `canCreate()`;
- recent records are checked again every time they are shown.

The trash and the activity log are not searched; each keeps its own search.

## Your own resources

```php
final class ListingResource extends Resource
{
    // Leave this resource out of Ctrl+K (its list search still works)
    protected static bool $globallySearchable = false;

    // The line under the title (default: the next searchable column)
    public function searchDetails(object $record): ?string
    {
        return $record->city . ' · ' . number_format((float) $record->price) . ' €';
    }

    // Where the result goes (default: the edit page, or the list searched for it)
    public function searchUrl(object $record): ?string
    {
        return null;
    }
}
```

## Your own results

A site can add groups, for example its help pages, in `config/admin.php`:

```php
'search' => [
    'sources' => [App\Admin\Search\HelpArticles::class],
],
```

```php
namespace App\Admin\Search;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Search\SearchResult;
use FloCMS\Admin\Search\SearchSource;

final class HelpArticles implements SearchSource
{
    public function label(): string
    {
        return 'Help';
    }

    /** @return list<SearchResult> */
    public function search(string $query, int $limit): array
    {
        return [
            SearchResult::make('Upload a gallery', Admin::url('help/gallery'))->detail('Media')->icon('image'),
        ];
    }
}
```

A source returns only what the current person may open: the panel checks nothing more for it. Links must be a site path (`/…`) or an `http(s)` address; any other result is dropped. A source that throws is skipped and logged.

## Settings

```php
// config/admin.php
'search' => true,                      // the default
'search' => false,                     // no search field, no Ctrl+K, /admin/search answers 404
'search' => ['per_group' => 3],        // results per group, 1 to 10 (default 5)
'search' => ['shortcut' => false],     // keep Ctrl+K and / for the browser; the field stays
```

## Limits

- **Contains, not fuzzy.** "vila" does not find "villa". Arabic letters are matched as typed (أ and ا differ).
- **Large tables.** "Contains" reads the whole table; it is quick up to tens of thousands of rows per resource. A big log table can be left out with `$globallySearchable = false`, or keep fewer searchable columns.
- **Time budget.** Groups are asked one after another; after 1.5 seconds no new group starts, and the box says some were skipped.
- **Recent records** live in the session: a new sign-in starts with an empty list.
