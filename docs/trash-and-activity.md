# Trash and activity log

Admin 0.19 makes changes in the panel safe and traceable:

- **Trash**: deleting a record moves it to the trash. A toast offers **Undo** for 10 seconds; after that, the record can be restored from the list's **Trash** button or from **Site → Trash**, for 30 days (configurable). Then it is deleted for good.
- **Activity log**: every create, edit, delete, restore, action, reorder, settings save and media change is written down with who, when, the record and the fields that changed (before → after). Each record shows it as **History**; **Site → Activity** lists everything, with filters.

Websites don't change: no column is added to your tables and no query changes.

## Setting up

```bash
php flo admin:install   # writes the migration of the admin_trash and admin_activity tables (nothing is overwritten)
php flo migrate         # creates them
php flo admin:doctor    # checks them
```

Until the tables exist, deleting works as before (at once, no Undo) and nothing is logged.

Grant the Activity page's permission to the roles that should see it, in `config/config.php`:

```php
2 => ['content.*', 'users.manage', 'users.assign_role', 'settings.*', 'activity.view'], // Admin
```

Super Admin (`*`) has it already. Editors (`content.*`) see the History of the records they can edit, not the Activity page.

## The trash

### How a deleted record moves

1. **Delete** (a row, Delete selected, the record page's Delete or a Delete action): the row leaves its table at once, so the website stops showing it. A copy waits in `admin_trash`: the row as JSON, its many-to-many links, its upload files and who deleted it when. The toast offers Undo.
2. **Restore**: the row goes back with **the same id**, its many-to-many links (to related records that still exist) and its media usage.
3. **Delete for good** (by hand, Empty trash, or after the configured days): the copy, its upload files and its media usage are removed.

A bulk delete is one operation: one Undo brings every record back. Undo works for the person who deleted, for an hour after the delete (the toast shows it for 10 seconds; hovering or focusing it pauses it).

### Where it is

- **Trash (n)** in a list's toolbar opens that resource's trash (`/admin/<slug>/trash`), with the list's own columns, who deleted each record, when, and how many days it has left. Select rows to restore or delete several at once; **Empty trash** deletes the resource's whole trash for good (it asks first).
- **Site → Trash** (`/admin/trash`) shows everything you may manage, with a tab per type.

### Who may do what

| | Needs |
| --- | --- |
| See a resource's trash | its `view` permission, and `create` or `delete` |
| Restore (and Undo) | `create` (it adds a record back), and `canCreate()` |
| Delete for good, Empty trash | `delete`, and `canDelete()` for the record (Empty trash leaves the ones it refuses) |

### Resources that show part of a table

A resource that narrows `query()` (the records of one site, one owner, one branch) can't ask the database whether a deleted row still belongs to the current user: the row has left its table. So, by default, each person sees in its trash **only the records they deleted themselves**. Give the resource its own rule to share them:

```php
public function canSeeInTrash(object $record, ?int $deletedBy): bool
{
    return (int) $record->site_id === current_site_id(); // $record is the row as it was deleted
}
```

The rule decides who sees, restores and deletes an entry for good (with the permissions above). A resource that overrides `query()` only to sort or to load relations can return `true`. The Activity page shows every resource's entries to the roles with `activity.view`; grant it only to people who may see the whole site.

### Restores that can't happen

A restore is refused with a reason, and the record stays in the trash, when something now blocks it:

- a value that must be unique (`->unique()`, a slug) is used by a newer record: "Another listing now uses the slug “old-town-flat”. Change that one, then restore this one again.";
- a parent it belongs to (`BelongsTo`) was deleted;
- a record with the same id exists again.

### Files and media

Upload fields keep their files while the record is in the trash, and media library items it uses stay "used" (shown as "Listings · Villa (in trash) · Cover"), so they can't be deleted from the library meanwhile. Both go when the record is deleted for good.

### Settings

```php
// config/admin.php
'trash' => ['days' => 30],          // 0 keeps records until the trash is emptied by hand
'trash' => false,                   // delete at once, as before 0.19
'trash' => ['table' => 'site_trash'], // another table name, up to 48 characters (set it before php flo admin:install)
```

One resource can delete at once:

```php
final class LogEntryResource extends Resource
{
    protected static bool $trash = false;
}
```

The built-in users management never uses the trash: accounts and their sessions don't restore cleanly, so deleted users are logged, not trashed.

Records older than the configured days are deleted for good by panel pages, at most once an hour. On quiet sites, add a cron job:

```bash
php flo admin:trash-purge         # what has expired
php flo admin:trash-purge --all   # empty the whole trash (asks first)
```

### Hooks

```php
public function beforeRestore(object $record): void
{
    // Runs inside the restore transaction. Refuse with a reason:
    if ($record->locked ?? false) {
        throw new \FloCMS\Core\ValidationException(['_form' => 'Locked records stay in the trash.']);
    }
}

public function afterRestore(object $record): void
{
    // The record, its links and its media usage are back (still inside the transaction).
}
```

`afterDelete()` runs when a record goes to the trash (it leaves its table then). From a script: `Trash::restore($entry)`, `Trash::purge($entry)`, `Trash::findRecord('listings', '42')`.

### Limits

- **Only what the panel deletes.** Rows removed by the website, an import script or SQL don't reach the trash.
- **Database cascades.** If your tables use `ON DELETE CASCADE`, the database removes child rows itself and the trash can't bring them back. Admin's own relations (many-to-many links, media usage) are saved and restored. `admin:doctor` warns about cascading foreign keys on resource tables.
- **Media library items** keep their own rule: an item in use can't be deleted, an unused one is deleted for good.
- The trash keeps a copy of the row as it was: a column added to the table later gets its default when the record comes back, and a column removed since is left out.

## The activity log

### What it records

| Action | Entry |
| --- | --- |
| Create | "created" |
| Edit | "edited 3 fields", with each field before → after; nothing when no field changed |
| Delete, restore, delete for good | one entry each (a bulk delete: one per record) |
| A custom action | "Ran “Publish” on Villa" |
| A bulk action | "Ran “Publish” on 12 listings" |
| Reorder | "Reordered 12 FAQs" (one entry per save) |
| Settings | "Settings · Contact details", with the fields that changed |
| Media | uploaded, details edited (title, alt text), file replaced, moved to a folder, deleted |
| Users | creating, editing (a role change shows Editor → Admin) and deleting accounts; profile changes |
| Sign-ins | off by default: `'activity' => ['logins' => true]` writes one "Signed in" entry per session |

How values are written:

- **Passwords** are never recorded.
- **Hidden fields**: `->hiddenFromActivity()` on a field, or `protected static array $activityExcept = ['notes'];` on the resource. A change shows as "Changed. The value is not recorded."
- **Rich text** and text longer than 200 characters: "Changed: 1,240 → 1,310 characters" (the text itself is not stored).
- **Relations** by name ("Pool, Garden"), **media** by title, **choices** by their label, toggles as Yes/No, translatable fields per language.
- Values are written as they read at the time, so the log stays readable when a related record is renamed or deleted.

No IP addresses or browsers are stored.

### Where it shows

- **History** on a record's page (a button next to Save): the record's entries, grouped by day, the first changes of each edit inline, and **Show changes** for the whole table. Anyone who can edit the record sees it (the update permission, the record within the resource's `query()`, and `canEdit()`); the changes of a record deleted for good need `activity.view`.
- **Site → Activity** (`/admin/activity`, permission `activity.view`): every entry, with filters for the person, the type, the period (today, 7 days, 30 days, 12 months, all time), the action (created, edited, deleted, restored, other) and a search over titles and changed values. **Show changes** opens the before → after table.

Both work without JavaScript: History and the changes table then open as pages.

### Your own entries

```php
use FloCMS\Admin\Activity\Activity;

Activity::log('published', $record, ['reason' => 'Scheduled'], ListingResource::class);
```

The entry appears in the record's History and on the Activity page as "Published" (name it in your lang file as `admin.activity.badge.published`), with its details in the changes view.

### Settings

```php
// config/admin.php
'activity' => ['days' => 365],     // 0 keeps entries forever
'activity' => ['logins' => true],  // also record sign-ins
'activity' => false,               // no log
'activity' => ['table' => 'site_activity'],
```

Old entries are removed by panel pages (hourly) and by `php flo admin:activity-purge`.

## Notifications with an action

The Undo toast uses two additions to `Notification`, which your own actions can use:

```php
Notification::make()->title('Report queued.')->neutral()->icon('clock')
    ->action('Cancel', Admin::url('reports/cancel/' . $id))  // a button that POSTs to a panel URL (with the CSRF token)
    ->duration(10)                                           // shows a bar counting down
    ->send();
```
