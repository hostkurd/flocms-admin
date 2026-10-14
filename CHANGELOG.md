# Changelog

All notable changes to `hostkurd/flocms-admin` are documented here.

## 0.4.1 - Unreleased

### Fixed
- Modal and full-page action forms use the same default-language input and language badge, including translated textareas. Translation Save/Cancel/Escape, keyboard focus, RTL and validation work inside the resource editor and after Reactive updates.
- Remove the normal "Open standard view" switch. Full-page URLs remain supported; a recovery link appears for oversized drafts or when JavaScript is disabled.

### Changed
- Ship full release-version assets `admin-v0.4.1.js` and `admin-v0.4.1.css`. No database migration, resource rewrite or dependency upgrade is required.

## 0.4.0 - 2026-10-14

### Added
- PHP-defined Actions for resource page headings, table toolbars and rows, with labels/icons/colors, permissions, current-record policies, confirmation and independent validated forms.
- Shared `CreateAction`, `EditAction`, `DeleteAction` defaults and a common renderer/executor for Reactive dialogs and standard action forms, including JavaScript-disabled operation.
- Session-bound action operations, conditional row locks before callbacks, guarded `ActionContext` updates/deletes, and same-connection transactional execution. Record locks do not fire UPDATE triggers.
- Text-only success, warning, danger and info Notifications, with localized accessible dismissal, response IDs, bounded delivery and persistence across panel navigation without refresh/replay duplication. Failed action transactions discard staged messages.
- Working Publish/Unpublish example, page/toolbar examples, request/notification/record-lock regressions and browser coverage.
- [Actions and Notifications guide](docs/actions-and-notifications.md) and 0.4 upgrade notes.

### Changed
- Core flash messages use the Notification presentation. Built-in CRUD actions retain resource normalization, validation, hooks, password handling and translation preservation.
- Compiled assets are `admin-v0.4.0.js` and `admin-v0.4.0.css`. No hosting-side build or Core/Reactive dependency bump is needed.
- Filters, bulk operations, richer Forms and Widgets remain later milestones.

### Fixed
- Preserve action drafts on conflicts/deleted records and keep native dialogs modal while notification deliveries are moved during DOM updates.
- Load Core's validation exception declaration for guard paths independently of test execution order.

## 0.3.0 - 2026-10-13

### Added
- Reactive resource lists with live search, sorting, pagination, create/edit dialogs, translated validation, delete confirmation and success feedback. Existing PHP resource definitions use the shared adapter.
- Panel navigation and standard/profile form submissions without full document reloads; browser history, title, language and focus follow the visible page.
- Current permission/record-scope checks, sealed active record context, one-use dialog operations, transactional saves and atomic stale-row protection for modal updates/deletes.
- External `admin-v0.3.0.js` / `admin-v0.3.0.css` assets, compiled with TypeScript/Vite and shipped in Composer distributions. No hosting-side Node build.
- Keyboard mobile menu, accessible modal errors and inline translations, EN/AR/KU transport messages, browser tests including RTL/CSP, and configured database concurrency regressions.
- [Reactive Admin guide](docs/reactive-admin.md) and [upgrade notes](UPGRADING.md).

### Changed
- Admin now requires Core `^2.3.2` and Reactive `^0.3.0`. Core and the skeleton still have no dependency on either optional package.
- Reactive navigation is enabled by default. Set `reactive => false` for the full-page behavior; the standard resource view is also available for larger forms.
- Forms use the same normalization/validation/transaction service in both request paths, retaining the 0.2.1 correctness fixes.
- Filters, uploads, bulk operations and widgets remain later milestones; this release establishes the Reactive integration.

## 0.2.1 - 2026-10-13

### Fixed
- Validate email-derived login values before saving accounts or profiles; exclude the current account and preserve password validation, hashing and blank-edit behavior.
- Check translatable unique fields against the same stored language, with exact case-sensitive equality, bound values and legacy plain-text support.
- Roll back resource writes and same-connection `beforeSave()` / `afterSave()` database writes when a save hook throws; nested transactions use Core savepoints.
- Restore trusted record context for read-only fields on invalid edit submissions.
- Preserve stored translations for languages absent from configuration; ignore submitted hidden-language keys while allowing configured languages to be cleared.
- Skip unconfigured MySQL tests without accessing an uninitialized database during teardown.

### Added
- `Resource::normalizeState()` for editable-state normalization before validation and dehydration.
- Request regressions for all save-path findings, shared SQLite/MySQL/MariaDB uniqueness and nested-transaction coverage, and configured-database execution of feature requests.
- [Upgrade notes](UPGRADING.md), including hook side effects and duplicate-data checks. No schema migration or new package dependency.

## 0.2.0 - 2026-10-12

The panel becomes the site's admin interface: dashboard, users and profile.

### Added
- **Dashboard** at `/admin/dashboard`: one card per resource the user may view, with its number of records. The package sets `Config 'admin.home'`, so `/admin` opens it on skeleton 1.8+; a site can override the setting in `config/config.php`.
- **Users management** at `/admin/accounts` for the skeleton's `users` table, with the skeleton's rules: `users.manage` for every action, roles up to your own (`Auth::canAssignRole()`), only lower roles editable unless `users.manage_any` (`Auth::canManageUser()`), no deleting, demoting or suspending yourself. Passwords hashed as by `php flo user:create`.
- **Profile** at `/admin/profile`: your own name, email, login and password; the current password is needed to change the email or the password.
- Resource API: `canCreate()`, `canEdit($record)`, `validate($state, $record)`; fields: `->requiredOnCreate()`.
- `'users' => true|false` in `config/admin.php`.
- `admin:doctor` checks the panel's pages and the users table, and says whether `/admin` can open the dashboard.

### Changed
- The URLs `dashboard`, `accounts` and `profile` are reserved for the panel; a resource using one is skipped and reported.
- The sidebar's Dashboard and Users links open the panel's pages; the user name in the top bar opens the profile.
- Filters, uploads, bulk delete and stat widgets move to 0.3.

## 0.1.0 - 2026-10-12

First release. Requires flocms-core 2.3+.

- Resources declared in PHP (`FloCMS\Admin\Resource`): table, slug, labels, icon, navigation order, timestamps, permission names, `query()` / `beforeSave()` / `afterSave()` / `canDelete()` hooks.
- Clean URLs through core 2.3's controller resolvers: `/admin/<slug>`, `/create`, `/edit/<id>`, POST `/delete/<id>`. Application controllers always win; colliding slugs are skipped and reported.
- List page: search, sorting, pagination, Edit and Delete (with a confirmation dialog).
- Form fields: `TextInput` (text, email, URL, tel, number, password), `Textarea`, `Select`, `Toggle`, `Date`, `DateTime`, `Slug`, `Hidden`, `Placeholder`.
- Table columns: `TextColumn`, `BooleanColumn`, `DateColumn`, `BadgeColumn`.
- Translatable fields stored as one JSON object per column, behind a `TranslationStore` interface; language badge and `<dialog>` editor; plain-text values already in a column are read as the default language. Website helper `localized()`.
- Validation with FloCMS's rules, applied to the default language (`required`) and to every filled language (the other rules); field type checks; `unique`; messages in the admin's language.
- Permissions per action (`content.<slug>.view|create|update|delete` by default) through `$actionPermissions`.
- RTL admin (logical CSS only), `dir` per language input, admin strings in English, Arabic and Kurdish (Sorani), overridable from the site's lang files.
- Commands: `admin:install`, `make:admin-resource` (`--migration`), `admin:doctor`.
