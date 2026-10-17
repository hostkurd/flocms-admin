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

## Notifications

```php
Notification::make()->title('Saved')->body('Your changes are available.')->success()->send();
Notification::make()->title('Review required')->warning()->send();
Notification::make()->title('Unable to continue')->danger()->send();
Notification::make()->title('Publishing guide')->info()->send();
```

Notifications are text-only in-app messages. Success/info use polite status announcements; warning/danger use alerts. Success and info close by themselves after 5 seconds (hovering or focusing one pauses it); warning and danger stay until dismissed. Change it per notification with `->duration(10)` (seconds) or `->persistent()`. Every notification has an accessible localized close button. They retain their original language and direction while the surrounding panel changes language. No message HTML or scripts are evaluated.

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
