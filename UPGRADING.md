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
