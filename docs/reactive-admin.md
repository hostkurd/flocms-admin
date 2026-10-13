# Reactive Admin integration

Admin 0.3 applies its Reactive adapter to existing `Resource` classes. The list renders on the server. htmx transports component actions and Alpine CSP/Morph updates their HTML through Reactive 0.3; Admin's small navigation adapter fetches panel pages and owns browser history. There is no client-side data model or second application API.

`FormSubmission` owns normalization, validation and transactional persistence for both modal and standard forms. `RecordRepository` owns scoped reads and conditional mutations. Existing `Form`, `Table` and `Resource` contracts remain the extension points. A general Action/Notification/Widget API is a later milestone.

## Runnable example

From a source checkout with PHP 8.1+, PDO SQLite and Node 22.12+:

```sh
composer install
npm ci
npm run build
FLO_ADMIN_TESTING=1 php -S 127.0.0.1:8083 tests/Browser/router.php
```

Open `http://127.0.0.1:8083/en/admin/posts`. The example signs you in as a test super admin and creates isolated session SQLite data. Try live search, sort, pagination, create/edit/delete, translated required validation, accounts and profile. `/ar/admin/posts` and `/ku/admin/posts` demonstrate RTL. Use Open standard view to edit longer bodies. This fixture is local development only: it includes test-only mutation endpoints, is gated by `FLO_ADMIN_TESTING=1`, and is excluded from Composer distributions. Never route production traffic to it.

The working resource is [`tests/Fixtures/Resources/PostResource.php`](../tests/Fixtures/Resources/PostResource.php), with its SQLite schema and seed data in [`tests/Browser/router.php`](../tests/Browser/router.php). It demonstrates translatable title/body, generated unique slug, status select, numeric views, featured toggle and publication date; its table has searchable/sortable columns and three-row pages. The same definition runs through both rendering paths.

For an application, run `php flo make:admin-resource Post --migration`, adapt the generated class and migration to that example, and run `php flo migrate`. Keep application resource classes in the configured resource directory. Authorize the resource with the usual `content.posts.view/create/update/delete` permissions. The example's seeded super user is not part of the package's installation workflow.

## Verification

```sh
vendor/bin/phpunit
npm run typecheck
npm run build
npx playwright install chromium
npm run test:browser
composer archive --format=zip --dir=/tmp --file=flocms-admin-distribution
php scripts/check-distribution.php /tmp/flocms-admin-distribution.zip
```

For configured MySQL/MariaDB request and repository coverage, set `FLO_ADMIN_TEST_DB_HOST`, `_PORT`, `_NAME`, `_USER`, `_PASS`. Use a disposable database: tests recreate `posts`, `tags` and `users`. CI runs MySQL 8 on PHP 8.1–8.4 and MariaDB 10.6/11.4 on PHP 8.3. Without a configured database those cases skip; SQLite still runs.

Browser tests run their own local PHP fixture and Chromium. `PLAYWRIGHT_CHROMIUM_EXECUTABLE` optionally selects a local browser executable. Tests verify document preservation across navigation/actions, back navigation, validation/focus, keyboard cancellation, RTL/mobile layout, CSP, conflicts, permission/CSRF expiry, and single-attempt behavior after network failure. Database tests also cover no-op saves and a concurrent commit under MySQL repeatable-read isolation.

See [upgrade notes](../UPGRADING.md) for limits and deployment requirements. This is the first Admin integration, not a complete Filament feature set.
