# Actions and Notifications

Admin 0.4 uses one set of PHP action definitions for its Reactive buttons and standard forms. Existing resource classes receive a Create page action and Edit/Delete row actions by default. Built-in actions continue to use the resource's normalization, validation, permissions, save hooks and password/translation handling.

## Define actions

There are three resource-list placements:

| Definition | Placement | Record passed to callbacks |
| --- | --- | --- |
| `Resource::headerActions(): array` | Page heading | `null` |
| `Table::headerActions(array $actions)` | Table toolbar | `null` |
| `Table::actions(array $actions)` | Each row | Current scoped record |

In the list, an action named `edit` is shown as an icon button on each row; all other row actions are in the row's **More actions** menu, with `danger` actions last. `Table::actions()` replaces the default row actions; include `EditAction::make()` and `DeleteAction::make()` when keeping them. Return `CreateAction::make()` from an overridden `Resource::headerActions()` to retain Create. Empty definitions remove those workflows, including their corresponding default CRUD routes. Names must be unique within a placement and use lowercase letters, digits, underscores or hyphens, starting with a letter. The same name can exist in separate placements.

```php
use FloCMS\Admin\Actions\{Action, ActionContext, EditAction, DeleteAction};
use FloCMS\Admin\Form\{Form, Fields\TextInput};
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Table\Table;

public function table(Table $table): Table
{
    return $table->columns([/* your columns */])->actions([
        EditAction::make(),
        DeleteAction::make(),
        Action::make('publish')
            ->label('admin.posts.publish') // site lang-file key, or plain text
            ->icon('file')
            ->color('success')
            ->permission('content.posts.publish')
            ->visible(fn (?object $record): bool => $record?->status === 'draft')
            ->authorize(fn (?object $record): bool => $record !== null && $this->canEdit($record))
            ->form(fn (Form $form): Form => $form->schema([
                TextInput::make('reason')->label('admin.posts.reason')->rules('required|max:190'),
            ]))
            ->action(function (ActionContext $context, array $data): void {
                $context->update(['status' => 'published']);
                Notification::make()->title('admin.posts.published')->body($data['reason'])->success()->send();
            }),
    ]);
}
```

Add those language keys to each application's `lang/<code>.php`. Labels and notification title/body keys resolve through the site's language files. Built-in UI and validation use Admin's EN/AR/KU translations. Available colors are `default`, `primary`, `danger`, `success`, `warning`; icon names use the existing Admin icon catalog.

Custom actions default to `Resource::permission($actionName)`, e.g. `content.posts.publish`; override with `permission()`. Built-ins use `create/update/delete` and the existing `canCreate/canEdit/canDelete` policies. Custom row actions must explicitly apply any additional record policy with `authorize()`, as in the example. Every action also requires the resource's view permission and current query scope. Both `visible()` and `authorize()` are enforced when opening and executing, including direct requests; visibility callbacks must be safe and free of side effects.

Custom actions request confirmation by default. Set `modalDescription()` to explain the operation. `requiresConfirmation(false)` runs an action immediately only when it has no form; form actions always open a dialog. Built-in CRUD actions retain their form/confirmation flow. They can customize labels, colors, icons, permissions and policy, but cannot replace their validated persistence with `action()` or an independent action form.

## Action forms

`form(Closure(Form): Form)` uses the existing flat field types and translatable controls. For custom actions, writable fields start from their declared defaults. `fillForm(Closure(?object): array)` can supply initial values. Read-only fields receive the current trusted record.

`normalizeState(Closure(array, ?object): array)` runs after declared input extraction and field preparation, before validation. Return the complete state. `validate(Closure(array, ?object): array)` returns field-name-to-message errors after field rules pass. Inside a callback, throw Core `ValidationException` for business errors; use a declared field name or `_form` for the error location. Core 2.3.2 declares that exception in `Validator.php`; Admin loads it for action/guard paths.

Only declared writable fields reach the callback, after field dehydration. Password controls and response snapshots are blank after server responses, including failures. A custom action determines its own handling of blank passwords; resource edit skip rules apply to built-in Edit only. For action fields with `unique()`, provide `uniqueUsing(Closure(Field, string, ?string, ?object): bool)` returning whether the submitted value is already taken. Action fields need not correspond to resource columns, so uniqueness does not infer a database column automatically.

Modal drafts retain the 0.3 limit of **7,000 UTF-8 bytes**. Oversized drafts offer Continue on full page; the action's existing full-page URL supports larger submissions. Copy unsaved text before switching, since the link does not transfer the draft. These forms validate the same way and use asynchronous navigation when enabled; they also work without JavaScript. The standard route is `/admin/<resource>/action/<placement>/<name>[/<id>]`. GET opens an operation; CSRF-protected POST executes or cancels it. GET never invokes a handler.

## Transactions and record changes

The server rebuilds the action allowlist on every request. Active action name, placement and record context are sealed; operation tokens also bind that context, current actor and CSRF session. They expire after 30 minutes, with at most 32 outstanding operations per session. A successful action consumes its token; cancellation consumes it without executing. Failed validation or callback transactions retain it for an explicit retry. After a lost response, cancel/reopen and inspect the result before retrying. Requests never automatically repeat a mutation.

For row actions, execution reloads the record through `Resource::query()`, checks current permissions/policy and the complete-row fingerprint, then locks the original before running the callback. Changed, deleted or newly out-of-scope records cannot be overwritten. Locking does not fire UPDATE triggers. MySQL/MariaDB use a current locking read; SQLite acquires write intent without changing a row. SQLite contention becomes a recoverable conflict rather than an automatic retry.

`ActionContext::update(array)` performs a guarded update and applies resource timestamps. It protects the primary key and creation timestamp. `delete()` performs a guarded deletion. Custom updates are independent operations and do not automatically call the resource's full-form save hooks; call any required business service explicitly. Both helpers require a row context. Page/table callbacks may use the same Core database connection for their own work.

Action dispatch owns its request transaction so that delivery and token consumption happen after commit. Dispatching inside an already-open database transaction is rejected before the callback runs. Within a callback, Core nested transactions use savepoints, and database work on the same transactional connection rolls back together. DDL, nontransactional tables, other connections, filesystem writes and external deliveries cannot be rolled back. Use an outbox for external delivery.

## Duplicate

Since 0.40 any resource can offer **Duplicate**: it opens the resource's New form filled from a record. Nothing is saved until the editor saves it, and the save is an ordinary create: the resource's `normalizeState()`, validation (including `unique()`), `beforeSave()` / `afterSave()`, BelongsToMany pivots, media usage and the activity log all run as they do for a new record. The record that was copied is only read to fill the form; it is never written, locked or used for uploads.

It is off until you add `DuplicateAction` to the row actions, usually between Edit and Delete:

```php
use FloCMS\Admin\Actions\{DeleteAction, DuplicateAction, EditAction};

public function table(Table $table): Table
{
    return $table->columns([/* your columns */])->actions([
        EditAction::make(),
        DuplicateAction::make(),
        DeleteAction::make(),
    ]);
}
```

It shows in the row's **More actions** menu (with the copy icon, in its own group just before Delete) and in the More menu of the record's view page. It opens the way the resource's Create does: a dialog, a slide-over or a full page (`$formMode`, or the Create action's own `modal()` / `slideOver()` / `page()`); `DuplicateAction::make()->page()` and the other mode methods choose for it. A form with a Builder field always opens as a page. The heading says `Duplicate “A1”` (the record's title as the list shows it), and a note above the fields says the form is a copy, that nothing is saved yet, and (when the resource has relation managers) that records linked to the original are not copied.

**What is copied.** Every field the form shows, read as the Edit form reads it: translations, selects, toggles, numbers, dates, BelongsTo and BelongsToMany, repeaters, blocks, MediaPicker items (by id: the library item is shared, never copied) and rich text. Fields hidden or disabled on a new record start at their default, as on Create. Automatically:

- **Password fields** and the **primary key** (when the form shows it) are never copied.
- **Slug fields** get `-copy` at the end (`villa-a1-copy`), or `-copy-2`, `-copy-3`… when that one is taken. A copy of a copy is `villa-a1-copy-2`, not `villa-a1-copy-copy`. The package's `Slug` can't be `translatable()`; a site's own Slug subclass that allows it gets `-copy` per language, checked against that language.
- **Other `unique()` fields** (a reference code) start at their default, so the editor gives the copy its own value.
- **Related records** in relation managers are not copied.

Only fields marked `unique()` are cleared. A column that is unique only in the database (a unique index, or a composite one such as `(site_id, code)`, not declared with `unique()`) is copied like any other field and the save then fails on the database: list such fields in `->except()`.

**Options**, all optional:

```php
DuplicateAction::make()->except(['code', 'published_on']);   // these start at their default
DuplicateAction::make()->only(['title', 'type', 'features']); // copy only these; the rest start at their default
DuplicateAction::make()->fill(fn (array $state, object $source): array => ['status' => 'draft'] + $state);
```

`except()` and `only()` together throw a `LogicException`. `fill()` runs last, with the form's values and the copied record, and returns the values (only the form's own fields are kept).

**Uploads.** A `FileUpload` or `ImageUpload` field's file is copied into the panel's pending uploads as a new pending upload of the person's session (as if they had just chosen the file), so the copy gets its own stored file when it is saved: deleting either record never removes the other's file. A file that is missing, or larger than the field's `maxSize()`, is left out: the field starts empty and a note above the form names it. Pending copies that are never saved are removed with the other pending uploads after 30 minutes. Opening the same copy again in the same session (a reload of the page) reuses its pending file instead of making another, and a dialog that closes without saving (Cancel, Escape, another dialog) releases its copies at once. When the session already holds too many pending files (32, or 64 MB), the note says so instead ("too many files are waiting to be saved"). The note stays after a save that fails its checks.

**Permissions.** Duplicate creates a record, so it follows the resource's own **Create** action: without a `CreateAction` in `headerActions()`, or when that action's permission, `visible()`, `authorize()` or `disabled()` refuses, there is no Duplicate either. Duplicate uses the Create action's permission (its `->permission()` if it has one, else the resource's `create` permission) unless you give Duplicate its own `->permission()`. It also needs `canCreate()` and the right to view the copied record: the `view` permission, the resource's `query()` and `canView()`. All of this is checked again when the form opens and when it is saved (in the dialog and on the page). `visible()`, `authorize()` and `disabled()` on Duplicate itself work as on other actions. Inside a relation manager it follows the manager's `canCreate(false)` and `readOnly()`. If the copied record is deleted, or the person may no longer view it, while the page form is being filled, saving shows the form again with their input and a message, and the form then saves as a plain new record; opening `create?duplicate=<id>` for such a record answers 404 or 403.

**Create and duplicate again.** The copy's form has a second button that saves and opens a fresh copy of the same record (the slug moves on to `-copy-2`).

**Without JavaScript** the action is a link to `/admin/<resource>/create?duplicate=<id>` (with `parent` and `back` when there are). The form posts to the same URL, so the panel knows the record for the note, the activity entry and "Create and duplicate again", and saves a new record. An id that is not a record's (or not in `query()`) answers 404, a record the person may not view or a missing create permission 403, and a resource without `DuplicateAction` 404.

**Activity.** The new record's "created" entry keeps where it came from (`duplicated_from`: the id and title of the original). Its History, the Activity page and the entry's details say "Duplicated from Villa A1", the title linked to the original while it exists, is in the resource's `query()`, and the person may view it (`canView()`) and open it; otherwise the title is plain text. The originals of a page of entries are looked up together. Your own code can add facts to a save's activity entry the same way: `FormSubmission::save(..., meta: ['key' => 'value'])`.

Pages keep their own instant Duplicate (a draft copy made at once), unchanged.

## Notifications

```php
Notification::make()->title('Saved')->body('Your changes are available.')->success()->send();
Notification::make()->title('Review required')->warning()->send();
Notification::make()->title('Unable to continue')->danger()->send();
Notification::make()->title('Publishing guide')->info()->send();
```

Notifications are text-only in-app messages. Success/info use polite status announcements; warning/danger use alerts. Success and info close by themselves after 5 seconds, warning and danger after 12 seconds (0.34; before, they stayed until dismissed), with a line showing the time left; hovering or focusing one pauses it. Errors beside a form's fields are not notifications and always stay. Change it per notification with `->duration(10)` (seconds) or `->persistent()`. Every notification has an accessible localized close button. They retain their original language and direction while the surrounding panel changes language. No message HTML or scripts are evaluated.

Messages sent inside an action are staged until its transaction succeeds. Exceptions and validation failures discard staged messages. Elsewhere, `send()` queues a session delivery for the next Admin response. Existing Core flash messages are converted to the same presentation, including standard CRUD and profile messages.

Response deliveries have unique IDs and stay outside replaceable panel/component nodes after enhancement. Navigation, search and pagination preserve existing messages without redelivery. Dismissal affects the current document; this is not a durable inbox. The session queue and visible history keep the latest 10 messages; pending deliveries expire after one hour. Titles/bodies are bounded to 512/4,096 UTF-8 bytes. JavaScript-disabled pages show messages inline.

## Run the example and checks

The browser fixture uses [`PostActionResource`](../tests/Fixtures/Actions/PostActionResource.php), built on the existing Post fields/table. It demonstrates Publish with a required reason and same-connection audit write, Unpublish confirmation, a page announcement and a toolbar guide. Its EN/AR/KU labels and per-session SQLite schema are in [`router.php`](../tests/Browser/router.php).

```sh
composer install
npm ci
npm run typecheck
npm run build
FLO_ADMIN_TESTING=1 php -S 127.0.0.1:8083 tests/Browser/router.php
```

Open `/en/admin/posts`, `/ar/admin/posts` or `/ku/admin/posts` on that local server. This isolated fixture signs in a test user and contains test-only mutation endpoints. It is excluded from distributions and must never serve production traffic. In an application, add the action definitions to your own resource and use your own schema/audit service; the example's `tags` audit writes are fixture data.

Run `vendor/bin/phpunit` and `npm run test:browser` (after `npx playwright install chromium`). Set `FLO_ADMIN_TEST_DB_HOST/PORT/NAME/USER/PASS` for a dedicated disposable MySQL/MariaDB database. Tests cover action routing/context, permissions/scope, validation, replay, transactional writes/notifications, record locks and trigger behavior, persistent feedback, keyboard/RTL and no-JavaScript compatibility. CI also verifies versioned assets and the Composer distribution.

## Notifications with a button

Since 0.19 a notification can carry one button that POSTs to a panel URL (the trash's **Undo** uses it). With a duration, a bar shows the time running out; hovering or focusing the notification pauses it.

```php
Notification::make()->title('Report queued.')->body('It will be ready in a minute.')
    ->neutral()->icon('clock')
    ->action('Cancel', Admin::url('reports/cancel/' . $id))
    ->duration(10)
    ->send();
```

The button's form sends the CSRF token and `_back` (the page the person is on). See the [Trash and activity log guide](trash-and-activity.md).

## Stored notifications and the bell

Since 0.41 a notification can be **stored for other people**: a bell in the top bar counts their unread ones, its menu shows the latest eight, and `/admin/notifications` keeps the rest. `send()` stays what it was (a toast for the person on the page); `sendTo()` stores one row for each person it reaches and returns how many.

Set up its table once:

```sh
php flo admin:install
php flo migrate
```

Until the table exists there is no bell, and `sendTo()` stores nothing: it returns 0 and writes a line to the site log, so a form that notifies never fails because of it.

```php
use FloCMS\Admin\Notifications\{Notification, Recipients};

// A website form, after saving the inquiry:
Notification::make()
    ->title('site.notify.new_inquiry', ['name' => $name])      // "New inquiry from :name"
    ->body('site.notify.inquiry_body', ['ref' => $reference])
    ->icon('mail')
    ->url('inquiries/edit/' . $id)
    ->sendTo(Recipients::permission('content.inquiries.view'));
```

**The text.** `title()` and `body()` take plain text or a key of the site's `lang/<code>.php`, and an optional array for its `:placeholders`. The key and the values are stored, not the translated text, so an Arabic user reads it in Arabic and an English user in English. The stored title is cut at 512 bytes, the body at 4,096 and each placeholder value at 1,000 characters; nothing is ever read as HTML.

**Who gets it.** `Recipients` picks active users whose role opens the panel (`admin_access_roles` in `config/config.php`; a site without that setting counts the roles that hold any permission):

| | |
| --- | --- |
| `Recipients::permission('content.inquiries.view')` | everyone whose role grants that permission in the role settings (`permissions` in `config/config.php` or the Roles page) |
| `Recipients::role(2)` | one role, by its number |
| `Recipients::users([4, 9])` | chosen people (`sendTo([4, 9])` and `sendTo(4)` are the same) |
| `Recipients::everyone()` | everyone in the panel |

Each person gets their own copy, at most 5,000 people a call. Suspended people and roles without panel access never get one.

**Where it leads.** `url()` takes a panel path (`inquiries/edit/5`), a panel URL (`Admin::url('inquiries/edit/5')`, kept as its path, so each person opens it in their own panel language) or a path of this website (`/en/properties/villa-a1`). Letters of any language and spaces are kept (percent-encoded), so `/el/properties/βίλα-α1` works. Another site, `//`, `javascript:`, control characters and paths with `..` (also written `%2e%2e`) throw an `InvalidArgumentException`. Without `url()` the notification opens the Notifications page.

**The bell.** It sits in the top bar before View site. Its red count shows up to `99+` and sits on the bell's top corner; the button's name tells screen readers "Notifications, 3 unread". It is a `<details>` menu like the language menu, so it opens and closes without JavaScript. The menu has **Mark all as read**, the latest eight (unread ones tinted, with a dot) and **See all notifications**. Opening one goes through `/admin/notifications/open/<id>`, which marks it read and then opens its page.

**Live count.** With JavaScript, an open panel asks `/admin/notifications/poll` every `poll` seconds (default 60) while the tab is visible, and right away when you come back to a tab after longer. The badge, the menu (if it isn't open) and the tab title ("(3) Inquiries") update. No sound and no pop-up.

**The page.** `/admin/notifications` lists the person's own notifications, 25 a page, with All and Unread tabs, Mark as read and Delete on each row and Mark all as read at the top. Everyone signed in has it; there is no permission to give, and another person's notification answers 404. `read/<id>`, `delete/<id>` and `clear` (mark all as read) are POST requests with the CSRF token that go back to the page the person was on.

**Opened another way.** A notification also counts as read when its person opens its panel page some other way, for example the record from its list: every panel page marks read that person's unread notifications that point to it (with or without the page's query; `/admin` alone, which may be a page set as home, doesn't). Your own code can do the same for another page:

```php
\FloCMS\Admin\Notifications\Inbox::markReadFor('inquiries/edit/' . $id, $userId);
```

**Settings** in `config/admin.php`:

```php
'notifications' => [
    'days' => 90,    // removed after this many days; 0 keeps them
    'poll' => 60,    // seconds between count checks in an open panel (at least 15); 0 = only when a page opens
    'table' => 'admin_notifications',
],
// or 'notifications' => false to switch the bell off
```

Old notifications are removed by `php flo admin:notifications-purge` (for a daily cron) and by panel pages at most once an hour. `php flo admin:doctor` checks the table. A site whose own resource, panel page or controller is called `notifications` keeps `/admin/notifications`, and the bell then stays hidden.

### A count beside a resource

A resource can show a number or a short text beside its sidebar item, as panel pages can with `badge()`:

```php
public function navigationBadge(): int
{
    return \FloCMS\Core\App::db()->table('inquiries')->where('status', '=', 'new')->count();
}
```

`null`, `0` and `''` show nothing; text longer than eight characters is shortened. It runs on every panel page, so keep it to one `COUNT`. A failing one is logged and left out. Resources that don't define it run no code.

