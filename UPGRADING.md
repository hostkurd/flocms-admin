# Upgrading to Admin 0.6.0

Release Reactive 0.4.0 before Admin 0.6.0. Then run `composer require hostkurd/flocms-admin:0.6.0 -W`, `php flo view:clear` and `php flo admin:doctor`, and reload open Admin pages. Admin now requires Reactive `^0.4.0`; PHP 8.1+ and Core `^2.3.2` remain unchanged. Deploy the supplied `admin-v0.6.0.js` / `.css` and matching Reactive assets. Full release-version filenames remain the convention. No Node build, package database migration, skeleton change or resource rewrite is required.

Existing flat resource definitions remain supported. Opt into `Form\Layout\Section`, `Grid`, `Tabs`, and `Tab`, with 1–4 columns and `columnSpan('full')`. Fields retain flat column names across all layouts and must be globally unique. Tab names must be unique within a group. Without JavaScript, every tab remains visible as a section.

Use `Select::optionsFor(parent, callback)`, `visibleWhen(parent, values)` and `requiredWhen(parent, values)` for dependent fields. Parent values must be scalar and non-password. Declare no cycles or unknown fields. Callbacks must be read-only and use scoped, bound database queries; they can run multiple times. Current options and requirements are checked again on save. Hidden values are not written; existing stored values remain on edit, and hidden create columns need database defaults/nullability. Resource permissions and query scope still provide authorization.

A live dependency change refreshes controls without saving or consuming the operation. Invalid child select values clear, unrelated drafts remain, and passwords are scrubbed after the response. Enter passwords after changing dependencies. With JavaScript disabled, use the Update fields button before choosing a child value. Existing transaction, stale-record, actor/CSRF and operation replay protections remain in effect for CRUD and bulk/custom actions.

The complete modal draft limit increases from 7,000 to **64,000 UTF-8 JSON bytes**, including keys, values and translations. Full-page fallback remains above the limit and does not transfer unsaved text. Reactive opted-in requests may use up to a 1 MiB HTTP envelope; allow sufficient PHP/web-server request-body size. Signed snapshots remain readable client data; do not put secrets in state. This release adds no repeaters, uploads, nested/object state or relationship managers.

See [Forms API and example](docs/forms.md). Application fields still need matching database columns; installing/updating Admin never automatically adds example Property columns. Existing optional login-theme configuration remains unchanged.

---

# Upgrading to Admin 0.5.0

Update the package after release with `composer require hostkurd/flocms-admin:0.5.0`, run `php flo view:clear` and `php flo admin:doctor`, then reload open Admin pages. Deploy supplied `admin-v0.5.0.js` and `admin-v0.5.0.css`; no Node build is needed on the application host. Core `^2.3.2`, Reactive `^0.3.0` and PHP 8.1+ remain unchanged. No package database migration or existing resource rewrite is required.

## Filters and bulk actions

Both features are opt-in resource definitions. Add `Table::filters()` and `Table::bulkActions()` as shown in the [API guide](docs/tables-and-login.md). Application columns such as status, featured or publication date must exist before you configure filters. No generated Property fields or schema are changed automatically.

Selections contain at most 50 explicit IDs, carry across pagination, and clear on search/sort/filter changes or page navigation. There is no select-all-matching workflow. Every record must pass current scope, permission, policy and stale-row checks; one failure rolls back all same-connection writes. Bulk callbacks own one transaction, with commit-only notification delivery and operation consumption. Do not dispatch inside an outer transaction or manually commit from a callback. External side effects require an outbox. Custom helpers do not run full resource save hooks automatically.

Action form callbacks receive a null record. Bulk actions always require review, and the existing 7,000-byte modal draft limit remains. Use `?static=1` for larger bulk forms or without JavaScript. Oversized drafts do not transfer automatically; copy unsaved text first. Filter state is a bounded JSON scalar in Reactive URLs, with equivalent standard form query parameters. Invalid filter values show errors and are not applied. Existing list/bookmark URLs remain usable.

## Enable the optional login theme

Existing `config/admin.php` files are preserved by the installer. Add:

```php
'login_theme' => true,
```

Skeleton 1.8.3 adds a presentation hook to `views/users/admin_login.html`. For an existing 1.8.2 application, prepend this to that view (leave the existing markup below it):

```php
<?php
$loginRenderer = \FloCMS\Core\Config::get('auth.login_view');
if (is_callable($loginRenderer)) {
    $loginMarkup = $loginRenderer();
    if (is_string($loginMarkup) && $loginMarkup !== '') {
        echo $loginMarkup;
        return;
    }
}
?>
```

Then clear compiled views. `admin:doctor` warns when the enabled theme is missing this hook. Updating Composer alone cannot update skeleton application views. For language metadata without JavaScript, also copy the skeleton 1.8.3 admin layout's dynamic `lang` and `dir` attributes if your layout still hardcodes English. Your authentication controller, CSRF checks, login throttling and session logic remain the same.

New `admin:install` configurations enable the theme, but the view hook is still needed on older skeletons. Set `'login_theme' => false` to keep your own design. An application-provided `auth.login_view` callable takes precedence; return null/empty to use the skeleton view. Without Admin/config, the skeleton view remains usable, with no Admin or Reactive dependency added to the skeleton.

---

# Upgrading to Admin 0.4.1

Update Admin with Composer after release and deploy its supplied `admin-v0.4.1.js` and `admin-v0.4.1.css`. Reload already-open Admin pages to load the updated client. No database migration, application resource change, or Core/Reactive dependency upgrade is required.

Create/Edit and custom action forms now show the same default-language input and language badge as full-page CRUD forms. Clicking the badge opens that field's translation editor. Save accepts the translations; Cancel/Escape restores the values from when it opened and returns focus to the badge without closing the resource editor. Secondary-language validation opens the affected translation dialog. The legacy sixth `FormRenderer` constructor argument remains accepted for compatibility but no longer selects expanded language inputs.

The normal "Open standard view" link is removed. Reactive dialogs and navigation remain the default. Existing full-page CRUD/action URLs, `?static=1`, and `'reactive' => false` still work, including with JavaScript disabled. The 7,000-byte modal draft limit is unchanged: an oversized form offers **Continue on full page**. Copy unsaved text before using that link; it does not transfer the draft. With JavaScript disabled, the resource list exposes the same fallback link through `<noscript>`.

Standalone FloCMS and its login page remain unchanged. This patch does not start the 0.5.0 filters/bulk-actions milestone.

---

# Upgrading to Admin 0.4.0

Update Admin with Composer after release. Core `^2.3.2`, Reactive `^0.3.0`, PHP 8.1+ and the existing secret `APP_KEY` requirements remain unchanged. No database migration or application resource rewrite is required. Deploy the supplied `admin-v0.4.0.js` and `admin-v0.4.0.css`; filenames keep the full release version and Composer distributions include compiled assets. Core and the skeleton remain independent of optional Admin/Reactive packages.

## Action definitions and compatibility

Existing resources receive Create, Edit and Delete definitions by default. Add custom actions through `Resource::headerActions()`, `Table::headerActions()` and `Table::actions()`. These definitions render in Reactive mode and the standard view. An overridden collection replaces the defaults, so include the built-in definitions when retaining those workflows. Removing the default Create/Edit/Delete definition also removes its standard CRUD route. Customized built-in names use the generic action route.

Action `visible()` and `authorize()` callbacks are server guards, not just presentation hints. A hidden or disallowed action cannot be invoked through a crafted request. Custom actions default to `Resource::permission($name)`; apply current record policies explicitly with `authorize()`. Keep tenant/record scope in `Resource::query()`. Built-in actions retain the existing resource policies, save service and hooks. Application controllers still take precedence.

Custom row, table and page actions use independent forms. Only declared writable fields reach a handler. Custom updates do not automatically run full-form `beforeSave()/afterSave()` hooks; invoke application business services explicitly where appropriate. Standard action forms have their own CSRF-protected, session-bound operation token and can be used when Reactive is disabled. Existing standard CRUD URLs retain their previous save semantics, plus guards from their default definitions.

## Transactions, stale pages and notifications

Action dispatch owns its request transaction. Do not wrap the dispatcher/App action request inside an already-open database transaction; it is rejected before callback execution. Use same-connection nested transactions inside an action callback when needed. This ensures successful notification delivery and operation consumption follow the actual action commit. Existing `FormSubmission` savepoint semantics remain intact.

Complete-row fingerprints and locks reject stale/deleted/out-of-scope row actions before their callbacks run. `ActionContext::update()` and `delete()` provide guarded mutation helpers. Locks do not fire extra UPDATE triggers. Transactions cannot undo external deliveries, nontransactional tables or work on other connections. Existing hook/outbox guidance below still applies.

Notifications sent inside action execution are staged and discarded on failure. Success/info announce politely; warning/danger announce as alerts. Messages persist through panel navigation until dismissed, with the latest 10 retained; they are not a durable inbox. Core flash messages map to the same presentation. Custom CSS selectors targeting the old flash banner or per-component success banner should migrate to `.fa-notification` / `[data-admin-notification]`; stable delivery containers must not be removed by application scripts.

Reload pages left open across deployment: the component schema and operation store now include action context, so old 0.3 snapshots are rejected with 409. Renewing the CSRF session or changing the signed-in actor invalidates outstanding action operations. Operation expiry remains 30 minutes/32 outstanding operations per session. The 7,000-byte modal draft limit remains; standard action pages support larger forms and still navigate asynchronously. Active draft values are retained on validation/conflict errors, with password values scrubbed from responses. Requests do not automatically retry writes.

`'reactive' => false` still restores standard forms/navigation. No nested state, upload API, bulk selection, table filters or general Widgets are introduced here. See the [Actions and Notifications guide](docs/actions-and-notifications.md) for the public API, working example and test commands.

---

# Upgrading to Admin 0.3.0

After release, update `hostkurd/flocms-admin` with Composer and its dependencies. Admin requires Core `^2.3.2` and Reactive `^0.3.0`. No database migration is required. The dependency direction is Admin → Reactive → Core; standalone Core and the skeleton remain independent.

## Deployment and configuration

- Configure a stable, secret `APP_KEY` of at least 32 decoded bytes, or `Config::set('reactive.key', ...)`. Reactive also accepts `base64:` keys. All application workers must use the same key. Rotating it invalidates open component snapshots.
- Keep the skeleton's current `auth.user_loader` configured. Reactive calls Core's `Auth::refresh()` on each update; its refresh interval determines when account/role changes are reloaded. Resource permissions, scoped queries, `canCreate()`, `canEdit()` and `canDelete()` run again during actions.
- Allow the normal language-prefixed `/flo-reactive/update`, `/flo-reactive/asset/...` and `/flo-admin/asset/...` routes through your existing front controller. Preserve application controller precedence and do not create controllers that shadow these package routes.
- Deploy the supplied `dist/admin-v0.3.0.js` and `dist/admin-v0.3.0.css` through Composer. Filenames retain the complete release version; immutable caching applies only to these assets. The package supplies external asset tags; no Node process or build is needed on cPanel.
- The built-in UI works with `script-src 'self'` and `style-src 'self'`, without inline scripts or unsafe evaluation. Custom field renderers and the application's layout remain responsible for their own CSP compliance.

`config/admin.php` defaults to `'reactive' => true`. Existing resource definitions need no new base class. Set `'reactive' => false` to restore full-page forms/navigation; this does not uninstall the Composer dependency. Removing Admin still leaves Core and the site's own controllers usable.

## Interaction and form limits

Resource search/sort/page state is namespaced in URLs (`admin-resource_q`, `_page`, `_sort`, `_dir`). Bookmarks and back/forward restore lists by fetching current server state. Old `q/page/sort/dir` links remain supported by the standard view (`?static=1`). Panel navigation covers built-in dashboard, resources, accounts and profile, including standard form submissions. Logout/login, external links and application-owned controllers retain normal navigation.

Modal drafts encode declared form fields in one bounded JSON scalar, because Reactive 0.3 supports scalar state. The modal limit is 7,000 UTF-8 bytes, including field names and translations. Use **Open standard view** before editing larger records; standard form pages also submit without reloading while Reactive navigation is enabled. If a draft grows past the limit, it stays in the dialog for copying, but cannot be saved there. Standard pages remain usable with JavaScript disabled. This release does not add nested components, dependent-field RPCs, uploads or stacked dialogs.

Dialog operations expire after 30 minutes, with up to 32 outstanding operations per session. Save/delete consume the operation after a successful transaction; replaying it is rejected. Cancellation discards the server draft and stays locally available after permission, CSRF or network failures. It cannot undo a save already sent. Requests are not automatically retried. After a lost save response, cancel/reopen to check the persisted result before trying again.

## Concurrent edits and hooks

Modal edit/delete snapshots seal a fingerprint of the complete loaded row. The action reloads the record through `Resource::query()`, checks current record policy, then conditionally writes only if all original columns still match. A deleted, changed or newly out-of-scope record cannot be overwritten. Case-only changes are detected even under case-insensitive database collations. No new version column is needed. This guard applies to modal actions; standard pages retain their existing save semantics.

Keep row/tenant scope in `Resource::query()` and record rules in `canEdit()` / `canDelete()`. Snapshot state is signed, not encrypted: do not place secrets in custom component state. Password controls are blank after responses and password drafts never enter returned snapshots.

Normalization, field/resource validation, dehydration and hooks are shared with standard forms. Database writes from save hooks on the same connection roll back together. `afterSave()` is still before commit. A `beforeSave()` hook must return changes for the active record rather than writing that same row itself, which would invalidate the optimistic guard. Hooks must not commit manually. External side effects and other connections cannot be rolled back; use an outbox for external deliveries.

Read the [working integration guide](docs/reactive-admin.md) for development commands and a runnable resource example. The 0.2.1 upgrade details below still apply, including the limits of validation-only uniqueness.

---

# Upgrading to Admin 0.2.1

Update `hostkurd/flocms-admin` with Composer after the release. This patch is tested with released Core 2.3.2; Core's existing `^2.3` constraint remains unchanged. No database migration, new runtime dependency or application asset build is required. Admin remains optional; Core and the skeleton do not require Admin or Reactive. Reactive integration is deferred to 0.3.0. Existing assets are unchanged; full release-version filenames remain the convention for compiled assets.

## Normalize before validating

The save sequence is field input extraction → field `prepare()` → resource `normalizeState()` → skip rules → field validation → resource `validate()` → transactional dehydration/hooks/write.

Override `normalizeState(array $state, ?object $record): array` to derive or normalize user-editable values. Return the complete state, using strings or language-to-string arrays. Do not write to the database, deliver messages, or hash passwords here. Normalized values pass all field rules and uniqueness checks before saving. Keep trusted storage-only metadata in `beforeSave()`; changes to editable values in that late hook are not automatically revalidated.

Accounts and profiles now derive blank login from email before uniqueness validation. An unchanged login excludes the current account correctly. Blank passwords on edit retain the stored hash; new passwords are validated before hashing and never echoed back after an error. Profile email/password changes still require the current password.

## Existing duplicate logins

This fix prevents the blank-login validation bypass; it does not repair existing data or add a unique index. On a backup or through a read-only connection, audit the skeleton users table:

```sql
SELECT login, COUNT(*) AS occurrences
FROM users
GROUP BY login
HAVING COUNT(*) > 1;
```

Review each group and assign appropriate distinct logins with the account owners. Do not automatically delete or merge accounts. If database-enforced uniqueness is required, first resolve duplicates and review login collation, blank values, column size and existing application write paths before planning a unique-index migration. Validation alone cannot guarantee uniqueness under concurrent writes; this limitation also applies to translatable fields.

## Translatable uniqueness and preservation

`->translatable()->unique()` checks each nonempty submitted translation against the **same language** on other rows. Equality is exact and case-sensitive on SQLite and MySQL/MariaDB; equal text in different languages is allowed. Legacy plain-text columns count only as the default language. Empty optional translations do not trigger uniqueness checks. The current record is excluded on edit.

`FormValidator` uniqueness callbacks may accept a third `?string $language` argument (`null` for ordinary fields). Existing two-argument callbacks continue working in PHP, but custom callbacks must use the language argument to implement translated uniqueness. `RecordRepository::exists()` accepts the optional fourth language argument. Custom translation stores supply the per-language scalar SQL expression through the existing `sortExpression()` contract; values remain parameter-bound.

Saving a translation field retains stored valid language keys that are no longer configured. Posted unconfigured keys cannot replace these retained values. The form remains a full submission for configured languages: blank or omitted configured translations are cleared. Removing a language from configuration is no longer a way to delete its stored content; use an explicit application migration if deletion is intended.

## Save hooks and transactions

Resource dehydration, `beforeSave()`, the insert/update, and `afterSave()` now run in one transaction on the resource's Core database connection. `afterSave()` means **after the write, before commit**. Exceptions propagate and roll back the record and transactional database work performed by either hook on that same connection. An outer Core transaction is respected through savepoints; a resource save does not commit the caller's transaction.

Use transactional tables (for example InnoDB). DDL, nontransactional tables, separate database connections, filesystem changes, emails and HTTP deliveries are not undone by database rollback. Hooks must not manually commit the connection. Put external delivery requests in a transactional outbox and process them after commit; do not treat `afterSave()` as an after-commit notification hook.

Read-only fields, including Placeholder callbacks, now receive the original record again after failed edit validation. Editable controls retain the submitted draft.

## Test databases

`vendor/bin/phpunit` runs SQLite coverage without database configuration; MySQL-specific tests skip cleanly. Set `FLO_ADMIN_TEST_DB_HOST`, `FLO_ADMIN_TEST_DB_PORT`, `FLO_ADMIN_TEST_DB_NAME`, `FLO_ADMIN_TEST_DB_USER`, and `FLO_ADMIN_TEST_DB_PASS` to run MySQL/MariaDB repository and full request tests. Use a dedicated disposable database: fixtures drop/recreate `posts`, `tags` and `users`. CI exercises PHP 8.1–8.4 with MySQL 8.0 and PHP 8.3 with MariaDB 10.6/11.4.
