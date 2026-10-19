# Table filters, bulk actions and login presentation

Admin 0.5 adds these features to existing resources without changing their base class or database schema. Configure only columns that actually exist in your application's table; `php flo admin:doctor` reports missing filter columns.

## Filters

```php
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Table\Filters\{SelectFilter, ToggleFilter, DateRangeFilter};

public function table(Table $table): Table
{
    return $table
        ->columns([/* existing columns */])
        ->filters([
            SelectFilter::make('status')->label('Status')
                ->options(['draft' => 'Draft', 'published' => 'Published']),
            ToggleFilter::make('featured')->label('Featured'),
            DateRangeFilter::make('published_on')->label('Publication date'),
        ]);
}
```

`make()` names the URL/form state and defaults to the same database column. Use `column('actual_column')` to change that mapping. Names and columns are validated identifiers; select values are allowlisted and query values use bound parameters. Labels and options may be site translation keys.

Toggle filters use All / Yes / No, preserving both `1` and `0`. Date ranges accept optional `from`/`until` dates in `YYYY-MM-DD` format; both calendar-date boundaries are inclusive, including datetime columns through `DATE(column)`. Invalid options/dates display errors and are not applied. Unknown filter names or malformed JSON are rejected. Filters use AND semantics and combine with the resource's query scope, search and sorting.

Open **Filters** in the table toolbar (the button shows how many filters are active), choose values, then **Apply filters**. Active filters are listed as chips under the toolbar; each chip's × removes that filter alone, and **Clear filters** removes all of them. The popover is a native `<details>` element, so it also works without JavaScript, and it opens by itself when a value is invalid. Applying/clearing filters resets the page and selection. Reactive bookmarks use `admin-resource_filters`, a JSON object encoded in the query string; back/forward reloads current server state without replacing the document. The standard list uses `filter[status]=draft`, `filter[featured]=0`, and `filter[published_on][from]=2026-10-14`. There are at most 20 definitions, 100 options per select and 2,000 UTF-8 bytes of filter state. The date expression may affect index use on large tables; use an application query/service for specialized reporting.

## Explicit bulk selections

Add definitions to `Table::bulkActions()`; existing resources have none by default. Selection is explicit, at most **50 record IDs**, and never expands to every matching row. **Select this page** adds/removes only visible rows. Selection survives pagination and clears on search, sorting or filter changes. Navigating away or reloading starts a fresh selection. The bulk bar appears above the table while records are selected and shows one button per bulk action. Without JavaScript the bar stays visible: select individual rows on the current standard page and press the action's button.

```php
use FloCMS\Admin\Actions\{BulkAction, BulkActionContext, DeleteBulkAction};
use FloCMS\Admin\Form\{Form, Fields\TextInput};

// Append to the table definition:
->bulkActions([
    BulkAction::make('publish')->label('Publish')->color('success')
        ->permission('content.posts.publish')
        ->authorize(fn (?object $record): bool => $record !== null && $this->canEdit($record))
        ->form(static fn (Form $form): Form => $form->schema([
            TextInput::make('reason')->label('Reason')->rules('required|max:190'),
        ]))
        ->action(static function (BulkActionContext $context, array $data): void {
            $context->updateAll(['status' => 'published']);
            // Persist $data['reason'] in your same-connection audit service if needed.
        }),
    BulkAction::make('unpublish')->label('Unpublish')
        ->permission('content.posts.unpublish')
        ->authorize(fn (?object $record): bool => $record !== null && $this->canEdit($record))
        ->action(static fn (BulkActionContext $context) => $context->updateAll(['status' => 'draft'])),
    DeleteBulkAction::make(),
])
```

Custom permissions default to `Resource::permission(actionName)`; declare record policies explicitly. `DeleteBulkAction` also checks `canDelete()` for every row. Permission-only availability controls whether an action appears in the menu; visibility and authorization callbacks receive each record when the selection is opened and executed. A visible menu option does not grant permission to execute it for every selection.

`BulkActionContext` exposes cloned `records`, `updateAll(array)`, `deleteAll()` and `each(Closure(ActionContext))`. The guarded per-record helpers preserve primary-key and creation-timestamp protection and apply update timestamps. Bulk forms validate once, use declared writable fields and pass a `null` record to form callbacks. They retain shared translations and password scrubbing. Full resource save hooks are not automatically called for custom bulk mutations.

All selected rows are reloaded through `Resource::query()`, authorized and locked in deterministic ID order before callbacks write. A missing, stale, forbidden or out-of-scope row rejects the complete selection. One owned transaction wraps every same-connection mutation; callback exceptions roll back writes and discard staged notifications. The server holds selection fingerprints in its session, with a one-use operation token bound to membership, actor and CSRF session. A successful commit consumes it and delivers one summary notification. Additional messages explicitly sent by a callback are also delivered after commit. Dispatch inside an outer transaction is rejected. External deliveries, DDL, other connections and nontransactional tables cannot be undone; use the existing outbox guidance.

Review always opens a confirmation/form, even if `requiresConfirmation(false)` is set. Modal form state remains limited to 7,000 UTF-8 bytes. For larger forms use the standard list via `?static=1`; the standard route is `/admin/<resource>/bulk/<action>`, GET with `ids[]`, POST with `_ids` JSON and `_operation` plus CSRF. Cancellation remains available after action permission revocation when the resource remains viewable. A lost response must be inspected before retrying; writes are never automatically retried.

## Optional Admin login design

FloCMS still owns authentication, CSRF, throttling, session refresh and logout. Admin supplies only the presentation, including localized EN/AR/KU labels, feedback, native credential fields and an accessible password toggle. It does not load Reactive on the login page. Sites may use their own `auth.login_view` callable instead.

New Admin configurations enable `'login_theme' => true`; existing configurations opt in by adding that setting to `config/admin.php`. The skeleton must include its optional `auth.login_view` rendering hook (introduced for skeleton 1.8.3). Composer updates do not replace application views. See [UPGRADING.md](../UPGRADING.md) for the exact existing-site patch. Setting `login_theme` to false restores the original view. Since 0.26 the styled login page also shows **Forgot password?** when password reset is on; see [Forgot password](password-reset.md). Removing Admin or its config likewise falls back; standalone FloCMS requires no Admin or Reactive dependency.

## Run the example

The repository's browser fixture demonstrates all three filters, Publish/Unpublish and Delete bulk actions, and existing row actions. Run the commands in [Actions and Notifications](actions-and-notifications.md#run-the-example-and-checks), then open `/en/admin/posts` (or `/ar` / `/ku`). `/en/__login-preview` previews presentation only; actual credential, CSRF and throttle regression tests live in the skeleton. The fixture auto-signs in a test user and is for isolated local testing only. It is excluded from Composer distributions.
