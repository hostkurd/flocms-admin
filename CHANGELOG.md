# Changelog

All notable changes to `hostkurd/flocms-admin` are documented here.

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
