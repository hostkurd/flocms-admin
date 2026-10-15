# File and image fields (0.7)

Upload fields belong to **resource create/edit forms**, including their standard action pages and Reactive dialogs. Each field owns one nullable string/TEXT column. Columns contain managed relative paths, never user-supplied URLs or original filenames. There are no automatic schema changes.

```php
use FloCMS\Admin\Form\Fields\{FileUpload, ImageUpload};

ImageUpload::make('cover')
    ->label('Cover image')->maxSize(2048)
    ->dimensions(maxWidth: 2400, maxHeight: 1600, minWidth: 320, minHeight: 200),
FileUpload::make('brochure')
    ->acceptedFileTypes(['application/pdf'])->maxSize(5120),
```

`maxSize()` is in **KiB**; default 5120, maximum 16384. Both the actual file size and detected MIME are checked, and the filename extension must match that MIME's own allowed extensions. Explicit supported MIME types are PDF, plain text, CSV, JPEG, PNG, GIF and WebP (`FileUpload::TYPES`). The default file field accepts PDF/plain text; an image field accepts JPEG/PNG/GIF/WebP, checks image headers, and limits both dimensions to 8192 pixels unless narrowed by `dimensions()`. SVG, HTML, executable extensions, empty files, and mismatched MIME/extensions are rejected. Browser `accept` is only a convenience. Images are validated and stored unchanged; no thumbnail conversion or GD/Imagick dependency is introduced.

Use `required()`, `requiredOnCreate()`, `disabled()`, `visibleWhen()`, `requiredWhen()`, `nullable()`, `help()` and `columnSpan()` as with other fields. Required validation also applies to explicit removal. Hidden and disabled fields cannot upload, remove or overwrite a column. Upload fields cannot be translated or used as dependency parents. Custom and bulk **input forms** reject upload fields because their callbacks do not define ownership of a single column. Built-in row and bulk deletion clean up managed files after the database transaction succeeds.

## Storage and access

Admin integrates the released `hostkurd/flocms-uploader ^1.2.0` local storage and validation APIs. No uploader configuration is changed globally. Storage is fixed at `<project root>/storage/admin-uploads`, outside the normal `public/` document root. Keep all of `storage/` inaccessible to the web server and include it in application backups alongside the database. The PHP process needs write access there. The package creates this directory on the first upload; it does not change existing application directories or install migrations.

Saved paths use `<resource>/<field>/<random name>.<validated extension>`. Do not copy managed paths into other records: the field owns its file. Files are served by `/LANG/admin/RESOURCE/file/ID/FIELD`, which rechecks login, the resource view/update permission, the current resource query scope and `canEdit()` policy. File fields download as attachments with a generated filename; image fields preview inline only for detected raster image MIME types. Responses disable caching and MIME sniffing and apply a restrictive CSP. Access to Admin files is intentionally restricted to editors; an application that needs public storefront media must implement its own delivery policy.

Do not rewrite managed file columns in `beforeSave()` (enforced), `afterSave()`, custom SQL, or action callbacks. Let CRUD own replacement/removal/deletion; share other application files through separate columns. Existing external/legacy paths remain unchanged when an edit omits the field, but are not served or deleted by Admin. Select a new file to migrate an existing column to managed storage.

## Requests, validation and cleanup

Standard forms use multipart POST and work without JavaScript. With async navigation, files remain multipart. Reactive dialogs use a dedicated CSRF-protected multipart endpoint for a declared create/edit action. The endpoint rechecks the operation, current record fingerprint, action permissions/policies, dependency visibility, and writable field before accepting bytes. Reactive scalar state carries only a random pending token, never file bytes, temporary filesystem paths, or client-supplied storage names. `flocms-reactive` stays at `^0.4.0` and requires no runtime changes.

Pending tokens are bound to the actor, CSRF token, resource, record and field, expire after 30 minutes, and are revalidated at save time. A session may retain at most 32 pending files and 64 MiB. A failed field/form validation retains valid pending selections for retry. New selections replace pending choices in the form; abandoned selections expire. Files are copied to their final managed paths inside the save transaction; database exceptions remove those new copies and preserve both the old file and the pending retry. Replaced/removed files and consumed pending files are deleted only after the outer Admin transaction commits. If post-commit filesystem cleanup fails, the error is logged and the database save remains committed. Filesystem/DB operations cannot be atomic across a process or machine crash; backups/reconciliation remain an application operational responsibility.

Pending files older than 30 minutes are pruned on upload. For idle applications, schedule this in an application command after boot:

```php
\FloCMS\Admin\Uploads\Uploads::prune(); // up to 256 expired files per invocation
```

Canceling a dialog or closing a page does not save a selected file. Its pending copy expires through that cleanup policy. Existing application tables and files are untouched unless a declared managed field is explicitly replaced/removed or its record deleted through Admin. Raw application SQL deletion bypasses Admin's file lifecycle.

Configure PHP `upload_max_filesize`, `post_max_size`, request-body limits and timeouts for your selected field sizes. PHP's multipart limit must fit **all** files plus text fields in a standard submission; a dialog uploads files individually. If PHP discards an oversized POST before parsing it, Core rejects the missing CSRF token; no file or record is written. Signed text drafts retain Admin 0.6's 64,000-byte limit.

## Runnable example and checks

The isolated browser fixture includes `/en/admin/assets` (also `/ar` and `/ku`), with cover image, document, hidden and disabled upload fields. Run `npm run test:browser -- tests/Browser/uploads.spec.ts` to exercise actual multipart uploads, preview, validation retry, replacement/removal, no-JavaScript forms, async navigation, RTL/mobile and permission revocation. `vendor/bin/phpunit --filter Uploads` covers MIME/extension/dimension/size validation, token scoping, forgery, rollback, private reads and expiration. These fixtures are never installed into an application.
