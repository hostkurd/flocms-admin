# Changelog

All notable changes to `hostkurd/flocms-admin` are documented here.

## 0.10.0 - 2026-10-16

Rich text. Additions only; existing resources, fields and settings are unchanged. Requires `hostkurd/flocms-reactive` **^0.5.1** (for `data-rx-ignore`) and `hostkurd/flocms-uploader` **^1.2.1** (its Imagick driver padded resized images to a square).

- New `RichEditor` field: headings, bold/italic/underline/strike, links (checked, optional new tab), lists, quotes, code blocks, dividers, undo/redo, images, videos and YouTube/Vimeo embeds. `toolbar()`, `images()`, `videos()`, `embeds()`, `maxImageSize()`, `maxVideoSize()`, `maxLength()`. See [docs/rich-text.md](docs/rich-text.md).
- Translatable rich text shows language tabs (EN · AR · KU) with filled/empty/invalid dots; each language has its own editor and writes in its own direction. Single-language sites get no tabs.
- Clean HTML: `RichText::clean()` removes scripts, event handlers, styles, unknown tags and unsafe URLs on the server before validation and saving; embeds are rebuilt from the video ID as sandboxed, lazy `youtube-nocookie.com` / `player.vimeo.com` iframes. `RichText::plain()` for excerpts. Empty formatting counts as empty for `required`.
- Media uploads through flocms-uploader to a public folder (new `media` setting, default `public/uploads/editor` at `/uploads/editor`): images stored as one web version of at most 1920 px; MP4/WebM videos sent in 1 MiB parts (not limited by `upload_max_filesize`), owned by the uploading user and session. New CSRF-protected endpoint `/admin/<resource>/media/<field>/…` for people who may create or edit the resource.
- The editor script (`admin-editor-v0.10.0.js`, Tiptap 3.31.4, MIT; see `THIRD_PARTY_NOTICES.md`) loads only on pages with an editor and works under a strict CSP. In the admin, embeds show as cards instead of third-party frames. Keyboard: one tab stop toolbar with arrow keys, Enter applies and Escape closes insert panels.
- In Reactive dialogs the editor keeps its content and undo history while the dialog updates; new errors for the field rebuild it from the submitted text and move focus into it.
- `admin:doctor` checks that the media folder is writable and that GD or Imagick is available when a resource uses rich text media.
- New icons for the toolbar; new strings `editor.*` and `validation.rich_text_long` in en/ar/ku.

## 0.9.0 - 2026-10-16

The panel redesign, matching the approved mockup. Additions only (new optional settings, `Resource::$description`, icons and strings); no existing resource, form, table, action or configuration API changed, so existing sites keep working and only look different. Browser tests or scripts that clicked row actions, filters or the bulk select may need updating (see the List page section).

### Foundation

- Design tokens: every colour, radius and shadow in `admin.css` is now a CSS custom property (`--fa-*`). The 70 hard-coded colour values outside the token definitions are gone (white text on solid buttons excepted).
- New `theme` setting in `config/admin.php`: `'light'` (default), `'dark'`, or `'auto'` (follows the operating system). Applies to the panel, the optional login page and toasts. Unknown values fall back to light; `admin:doctor` reports them.
- Shipped fonts: Instrument Sans (Latin) and Noto Sans Arabic (Arabic, Kurdish), SIL OFL 1.1, served by the asset route with immutable caching. Kurdish and Arabic no longer fall back to Tahoma or Segoe UI on Windows.
- One focus ring everywhere (2 px, theme-aware) instead of three different styles; stronger focus state on inputs.
- Refreshed buttons, inputs, cards, badges, alerts, tables, pagination, tabs, dialogs, toasts, login page and dashboard widgets on the tokens (radius, spacing, weights, softer borders).
- Fix: Reactive's own dialog and status styles, which load after the admin's, no longer force a white dialog background or a fixed red status colour inside the panel.
- Fix: toasts used their own font stack and colours; they now use the panel's font and theme.
- The browser fixture accepts `FLO_ADMIN_THEME=dark|auto` for local testing.

### List page
- One toolbar: search with its icon and a clear button, table actions, and a **Filters** button showing the number of active filters. Filters open in a popover (a native `<details>`, so it works without JavaScript) and re-open by themselves when a value is invalid.
- Active filters appear as chips under the toolbar; each chip removes its own filter, and **Clear filters** removes all.
- The bulk bar appears only while rows are selected and offers each bulk action as a button (danger actions in red outline). Without JavaScript it stays visible and its buttons submit the same request as before. Selected rows are highlighted.
- Row actions: Edit is a quiet icon button; the other actions are in a **More actions** (`⋯`) menu, destructive ones last after a divider. The menu works without JavaScript; with it, it closes on Escape or an outside click, follows its button while scrolling instead of being clipped by the table, and returns focus to its button when an action's dialog closes.
- Numbered pagination with previous/next arrows (mirrored in right-to-left languages) in both the Reactive and standard lists.
- New icons: `pencil`, `trash`, `more`, `filter`, `x`, `chevron-left`, `chevron-right`. New strings `table.more_actions`, `table.page`, `filters.remove` in en/ar/ku.
- Fix: the Reactive list's search field was missing its search icon.

### Modal and forms
- Dialogs end with Cancel followed by a filled primary button (Save changes, Create, or the action's own colour) on the end side; the close button is a proper icon. Delete confirmation uses the same order.
- Standard (full-page) forms keep the primary button first so Enter still submits rather than cancels; their actions sit in a sticky bar at the bottom of the card. Standard action and bulk confirmation pages use the primary style too.
- `Toggle` fields render as switches (the same checkbox underneath, so submitted values and no-JS behaviour are unchanged), mirrored in right-to-left languages.
- The translation badge joins its input like an add-on; for long text it sits on the label line. The translation dialog is a compact panel whose rows start with the language code, and ends with Cancel, then Save.
- Section descriptions sit directly under their heading, and consecutive sections are separated by a line.

### Sidebar and topbar
- Light sidebar by default (`'sidebar' => 'dark'` keeps a dark one), with a brand mark (the brand's first letter, or `logo`) and "Admin" under the brand name.
- Topbar: breadcrumbs (Content / Posts / New post) instead of a repeated page title; a language switcher that opens the same admin page in another site language; an account menu (avatar initial, name) with My profile, View site and Log out. Full-page forms lose their in-page breadcrumb link, which the topbar now carries.
- New settings in `config/admin.php`: `primary_color` (`#rrggbb`; shades for hover, highlights and the dark theme are derived from it and served as `theme-<hash>.css`, immutable and CSP-safe), `logo` (absolute URL or `/path`), `sidebar`. Invalid values fall back to the defaults; `admin:doctor` reports them and warns when white button text on `primary_color` is below 4.5:1 contrast.
- Optional `Resource::$description`, shown under the list page title. The "Content" eyebrow above list titles is gone (the breadcrumb carries it).
- 22 more icons for resources and actions (`newspaper`, `folder`, `map-pin`, `cart`, `credit-card`, `chart`, `message`, `bell`, `key`, `shield`, `layers`, `briefcase`, `book`, `camera`, `video`, `heart`, `link`, `phone`, `truck`, `grid`, `globe`, `user`), plus `chevron-down` and `external` for the topbar.
- New strings `nav.admin`, `nav.breadcrumb`, `nav.language`, `nav.account` in en/ar/ku.

### Phones, dashboard and translations
- Phones (640 px and narrower): list rows become cards. The first searchable column (else the first column) is the card's title next to the row actions, and the other columns follow as label / value pairs. "Select this page" stays visible above the cards. Dashboard widget tables are unaffected.
- Breakpoints reduced from seven to three: 640, 900 and 1024 px.
- Charts: labels are HTML text at a fixed 12 px (they used to scale with the widget's width, from about 8 px to 18 px), with dashed gridlines and a middle value. Bars and lines stretch to the widget's width; lines and points keep their thickness. Still free of inline styles.
- Stat tiles with a coloured accent bar, a larger value and a hover state when linked; a smaller Refresh dashboard button with an icon, aligned to the end.
- The translation badge sits inside the input's end edge (the top corner of a textarea) and shows only the default language plus one dot per other language: filled when translated, hollow when empty, red when invalid. Its accessible label names the missing languages and updates as you type. The badge follows the content language's direction, so it never covers the start of the text on a page in the other direction.
- Sites with a single language: translatable fields render as plain inputs, with no badge or translation dialog. Values are still saved as `{"en": "…"}`, so adding a language later needs no migration.

Ship `admin-v0.9.0.css` and `admin-v0.9.0.js`.

## 0.8.1 - 2026-10-16

- Remove the browser's default 8 px page margin around the panel when the site's admin layout places the panel directly in `<body>` (the skeleton's layout does). Pages that set their own body margin are unaffected.
- Ship `admin-v0.8.1.css` and `admin-v0.8.1.js`; the JavaScript is unchanged apart from its file name.
- Documentation: the 0.6.0 entry carries its release date, release headings use one dash style, and the Reactive Admin guide refers to Reactive 0.4 and the released Widgets.

## 0.8.0 - 2026-10-15

- Add opt-in StatsWidget/Stat, RecordsWidget and single-series SVG ChartWidget (bar/line), explicit class registration, responsive column spans, ordering, translations and accessible chart data tables.
- Add immutable scoped read helpers with bound filters, counts/sums and bounded records. Enforce registered source resources and view permissions before providers run, with optional extra widget permission and visibility checks.
- Add a signed, CSRF-protected dashboard refresh component that rechecks current configuration/permissions; retain ordinary GET refresh for no-JavaScript and Reactive-disabled projects, and keep automatic resource cards when no widgets are configured.
- Isolate provider failures, log private diagnostics, validate widget configuration through admin:doctor without invoking providers, and bound widgets, points, rows, stats and query counts.
- Add runnable fixtures, PHP/browser regression coverage, a Widgets guide, upgrade notes and admin-v0.8.0 assets. Existing Core, Reactive, Uploader and standalone skeleton requirements remain unchanged.

## 0.7.0 - 2026-10-15

- Add single-file FileUpload and raster ImageUpload fields to resource CRUD forms, with MIME/extension/actual-size/dimension validation and private local storage through flocms-uploader ^1.2.0.
- Add CSRF-protected multipart transport for Reactive dialogs, session/actor/record/field-bound pending tokens, permission-checked downloads/previews, and multipart standard/async forms including no-JavaScript submissions.
- Preserve pending selections on validation failure, compensate newly written files on transaction rollback, and clean up replaced/removed/deleted managed files only after commit. Bound pending lifetime, count and bytes; expose scheduled stale-upload pruning.
- Add English/Arabic/Kurdish controls, mobile RTL preview styling, runnable fixtures, regression coverage, the Uploads guide and upgrade notes. Ship admin-v0.7.0 assets.
- Keep Core and standalone skeleton unchanged; Reactive remains ^0.4.0. Upload fields are limited to resource CRUD forms and private local storage in this release.

## 0.6.0 - 2026-10-15

### Added
- Sections, tabs and responsive 1–4 column grids with flat, unique field names shared by CRUD and action forms.
- Accessible keyboard/RTL tabs, error-tab selection, and visible section fallback without JavaScript.
- Server-resolved dependent select options and conditional visibility/required rules; hidden values cannot bypass validation or overwrite stored columns.
- Authorized, non-writing dependency refreshes for Reactive, standard, custom and bulk action forms, preserving unrelated drafts and existing operation guards.
- Runnable Properties example and [Forms guide](docs/forms.md), including database, callback, password and no-JavaScript contracts.

### Changed
- Requires Reactive `^0.4.0` for larger bounded scalar state. Modal drafts now allow 64,000 UTF-8 JSON bytes, with full-page recovery above that size.
- Assets use complete release filenames `admin-v0.6.0.js` and `admin-v0.6.0.css`. Core and skeleton dependencies are unchanged; no application migration is performed automatically.

## 0.5.0 - 2026-10-14

### Added
- Select, toggle and inclusive date-range table filters with allowlisted values, bound queries, bookmarks and no-reload back/forward restoration.
- Explicit selection across list pages, selected counts, select-this-page and PHP-defined bulk Actions with validated forms; maximum 50 selected IDs.
- Atomic bulk dispatch with current permission/scope/policy checks for every row, stale-row locking, session-bound one-use operations and commit-only summary notifications.
- Bulk Publish/Unpublish/Delete examples, JavaScript-disabled fallback, keyboard/mobile RTL browser coverage and configured-database regressions.
- Optional localized Admin login presentation using the skeleton's authentication and optional rendering hook; password visibility toggle, CSRF, feedback and signed-in nonadmin logout.
- [Table and login API guide](docs/tables-and-login.md), compatibility diagnostics and upgrade instructions for existing application views.

### Changed
- Full release-version assets are `admin-v0.5.0.js` and `admin-v0.5.0.css`. No Core/Reactive dependency change or automatic application schema change.
- New Admin configurations enable the login theme; existing installations opt in. Standalone FloCMS remains independent.

## 0.4.1 - 2026-10-14

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
