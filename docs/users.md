# Users: photos, actions and emails

Admin 0.28 turns **Users** (`/admin/accounts`) into a people list: photos, row and bulk actions such as Suspend and Send reset link, and emails to one person or to everyone. Run `php flo admin:install`, then `php flo migrate` for the mailing tables and the `bio` column; everything else works without installing.

## The user page

**New User** and **Edit** show the person's details in the main column: **Profile** (name, email, login, phone, bio) and **Password** (with **Generate**). The sidebar holds the **Photo**, **Access** (role and status) and, for an existing user, **Quick actions**: Send reset link, Send email, Activate and Suspend, greyed out with the reason as a tooltip when they don't apply. A quick action opens its own page and comes back to the user. The Profile page uses the same layout.

**Bio** is an optional text column (`users.bio`) that `admin:install` adds to your site's own `users` table; the field shows once the column exists. On the website it is `$user->bio`.

## Photos

Each user can have a photo, set on Users (Edit) or on their own Profile. It is cropped to a square of 256 × 256 pixels (JPEG, PNG or WebP, at most 2 MB) and stored under `public/uploads/admin`, so the website can show it too. Without a photo the first letter of the name is shown on a colour picked from the user's id (always the same colour for the same person).

Photos appear in the Users list, the top bar, the Activity page, History, Changes and the Trash.

The skeleton's `users.image` column holds the photo. A site without that column shows letters only.

```php
'public_uploads' => [
    'root' => 'public/uploads/admin',   // where photos are stored
    'url'  => '/uploads/admin',         // their address on the site
],
```

On the website: `FloCMS\Admin\Support\Avatar::forUser($user, size: 2)` returns the same `<img>` or letter.

## The list

- **User**: the photo, the name and the email below it, with a tick for a verified email. Search finds the name, email, login and phone. Only the name opens the user.
- **Role** and **Status** (a coloured dot: Active, Pending, Suspended). The ID column is gone; **Phone** and **Login** can be shown with **Columns**.
- **Tabs** above the list, with counts: All, Active, Pending, Suspended. The tab is a filter, kept in the address, and works without JavaScript.
- **Role** in the Filters menu.

## Row actions

The row's **⋯** menu, each item following the person's permissions:

| Action | What it does |
| --- | --- |
| **Edit** | as before |
| **Send reset link** | emails the user a one-time password link ([Forgot password](password-reset.md)); the per-address limit applies. **Also sign them out everywhere now** replaces the current password, so the old one stops working |
| **Send email** | opens the composer for this user |
| **Activate** | sets the status to Active (a pending account also counts as verified); **Tell them by email** sends "your account is active" |
| **Suspend** | asks for an optional reason (kept in the activity log), sets the status to Suspended, ends their access on their next request; **Tell them by email** sends them a note |
| **Delete** | as before, to the trash |

An action that does not apply is **greyed out**; its reason ("Already active", "You can’t do this to your own account", "No email address") shows as a tooltip on hover, keyboard focus or a tap, and screen readers read it. The server refuses it in the same cases.

If an email can't be sent, the status still changes and a warning says the email didn't go out.

## Bulk actions

Select rows, then **Activate**, **Suspend**, **Send reset link**, **Send email** or **Delete**. Users an action doesn't apply to are skipped and counted, for example "2 are skipped: Already active (1), You can't do this to your own account." If nobody is left, nothing runs and the list says why. One user who can't be done (an email that won't go, a reset limit) doesn't stop the others; a warning names them. **Send email** opens the composer with the chosen users.

## Emails

**Email users** (top of the list) and **Send email** open the composer at `/admin/mailings/compose`: To, Subject, a rich-text Message, and a Preview as one of the recipients.

**To** works like an email program: people are chips, added by typing a name or email (arrow keys and Enter pick one; Backspace removes the last). **Group** adds Everyone, a role or a status as one chip instead. The count, the Send button and the page title follow at once. Without JavaScript the Group menu and the × on each chip still work (the page reloads with what you typed). `{name}`, `{first_name}`, `{email}` and `{site}` are replaced for each person. **Send me a test** sends it to yourself.

There are two kinds:

- **Direct email**: to up to 20 chosen users, sent at once, without an unsubscribe link. Needs `users.email`. At most `direct_per_hour` (50) an hour per sign-in.
- **Mailing**: needs `APP_KEY` in `.env` (at least 16 characters), which signs the unsubscribe links. To everyone, a role, a status or chosen users. Needs `users.mailing`. Sent in batches with an unsubscribe link in each email; people who unsubscribed are left out (unless chosen by name). **Active users only** is on by default. More than 100 people asks you to type the number first.

A mailing's page (`/admin/mailings/show/<id>`) shows its progress and sends the next batch while it is open. **Pause**, **Resume**, **Cancel** and **Retry failed**. Each email is tried 3 times; after the host refuses one, the mailing waits a minute. The composer keeps up to 500 chosen users; for more, send to a role, a status or everyone. Every mailing is listed on **Mailings** (`/admin/mailings`).

### Sending without a page open

Add the command to cron, every minute:

```
* * * * * cd /path/to/site && php flo admin:mailings
```

It sends at most `per_batch` emails a step and `per_hour` an hour, the limit most shared hosts set. Without cron a mailing goes on only while someone has its page open.

### Unsubscribing

The link in a mailing opens a page asking "Stop these emails?". After **Unsubscribe**, the address is kept as a hash in `admin_mail_optouts`. Mailings carry `List-Unsubscribe` and `Precedence: bulk` headers. Direct emails, password links and account emails are always sent.

### Settings

```php
'mailings' => [
    'per_batch' => 20,         // emails a step
    'per_hour' => 200,         // emails an hour, for all mailings together
    'direct_per_hour' => 50,   // direct emails an hour per admin
    'keep_days' => 30,         // recipient lists of finished mailings are deleted after this
],
// 'mailings' => false switches mailings off (direct emails stay).
```

## Permissions

Two new permissions on the Roles page, under Users:

- `users.email`: **Email users**, direct emails to up to 20 chosen users.
- `users.mailing`: **Send mailings**, to everyone, a role or a status.

Existing roles don't get them; tick them on the Roles page. Suspend, Activate and Send reset link need `users.manage` and follow core's rules (only users of a lower level, never yourself).

## Activity

New entries: `user_suspended` (with the reason), `user_activated`, `password_link_sent`, `email_sent`, `mailing_started`, `mailing_paused`, `mailing_cancelled`, `mailing_finished`.

## For developers

The pieces are generic and work on every resource:

- `Action::disabled(fn (?object $record): ?string)`: greyed out with the reason returned, and refused on the server. `Action::url(fn)`, `->group('…')` (dividers in the menu), `->modalHeading(fn)`, `->modalDescription(string|Closure)`.
- `BulkAction::skipUnavailable()` (skips and counts records whose `disabled()` gives a reason), `->bulkUrl(fn (array $ids): string)` (a bulk button that opens a page), `->completedTitle('Done for :count.', 'Done for 1.')`.
- `TabsFilter` with `Table::tabs()`: a filter shown as tabs with counts.
- `TextColumn::avatar('image')`, `->description('email')`, `->descriptionMark(fn, 'Verified')`; `Column::searchAlso(['email'])`; `BadgeColumn::dot()`.
- `ImageUpload::avatar()` (public, square 256, 2 MB), `ImageUpload::square(int)`, `FileUpload::public()`.
- `TextInput::generate()`: a Generate button for passwords.
