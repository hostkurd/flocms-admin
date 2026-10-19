# Forgot password

Since 0.26 the panel's login page can show **Forgot password?**. A person types the address they sign in with, gets a one-time link by email, and chooses a new password. Saving it signs that account out everywhere else.

Requires flocms-core 2.5 (for the two pages that open without signing in, and for ending other sessions) and the panel's login styling (`'login_theme' => true`).

## Turning it on

New installations have it in `config/admin.php`. An existing site adds:

```php
'password_reset' => true,
```

then runs:

```
php flo admin:install     (writes the migration of the admin_password_resets table)
php flo migrate
php flo admin:doctor      (checks the table, the email settings and APP_URL)
php flo admin:mail-test you@example.com
```

Until the table exists the login page shows no link, and `/admin/password/forgot` says that password reset is not available.

Options (all optional):

```php
'password_reset' => [
    'expires' => 60,      // minutes a link works (5 to 1440)
    'per_email' => 3,     // requests per address per hour
    'per_ip' => 10,       // requests per IP address per hour
    'table' => 'admin_password_resets',
],
```

`'password_reset' => false` switches it off.

## Email

The panel sends two emails: the link, and a short note after the password changed ("If this wasn't you, contact your site administrator"). They are written in the language of the page the person used, in the panel's colours, with a plain-text version. Settings come from `.env`:

```
MAIL_TRANSPORT=smtp          # mail (PHP mail(), the default), smtp or log
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls          # tls (STARTTLS, port 587), ssl (port 465) or none
MAIL_USERNAME=no-reply@example.com
MAIL_PASSWORD=…
MAIL_FROM=no-reply@example.com   # default: PRIMARY_EMAIL
MAIL_FROM_NAME="My site"         # default: the panel's brand
```

- **cPanel**: `mail()` usually works as it is. If messages land in spam, create a mailbox in cPanel and use its SMTP settings.
- **`log`** writes the messages to `storage/logs/admin-mail.log` instead of sending them: for a computer without mail (XAMPP). Never on a live site: the log holds the links. `admin:doctor` warns when `APP_ENV=production`.
- **`APP_URL`** must be the site's full address (`https://example.com`): it starts the link.
- **Your own mailer** (PHPMailer, an email API): set `'mail' => ['send' => fn (\FloCMS\Admin\Mail\Message $message) => …]` in `config/admin.php`. The message has `to`, `toName`, `subject`, `text` and `html`.

## What the person sees

1. **Forgot password?** on the login page opens `/admin/password/forgot`.
2. They type their address. The answer is always the same, "If an account exists for … we've sent it a link", whether the address has an account or not, so the form can't be used to find out who has one. The email is sent after the page has been answered (on PHP-FPM), so both cases also take the same time.
3. The link opens `/admin/password/reset/<token>`: a new password, typed twice, at least 8 characters (as on the profile page), with a show/hide button.
4. Saving sends them to the login page with "Your password has been changed. You can sign in now." They are not signed in automatically, so the usual login checks (status, role, throttling) still apply.

A used, expired or wrong link shows "This link no longer works" with a button to request a new one. All pages work without JavaScript, right-to-left, and on phones.

## Security

- Only hashes are stored: of the address and the IP address (keyed with `APP_KEY`), and of the link's token (SHA-256). Rows are deleted after a day.
- A link works **once** and for `expires` minutes; a newer link replaces older ones; two tabs saving at the same moment can't both use it.
- Emails go only to **active** accounts (status 1). Pending and suspended accounts get the same answer and no email.
- **Limits**: 3 requests per address and 10 per IP address an hour; over a limit the answer stays the same and no email is sent. Wrong links are limited per IP address too. Behind a proxy, list it in `TRUSTED_PROXIES` so the visitor's address is used.
- The pages are not cached, not indexed, can't be framed, and send no referrer, so the token in the address never reaches another site.
- **Other sessions end**: each signed-in session keeps a fingerprint of the account's password hash, which FloCMS checks when it reloads the user on admin requests. When the password changes (by a reset link, on the profile page, or by an admin on Users) the account's other sessions end with "Your session has ended. Please log in again." The person who changed their own password stays signed in. With `auth.refresh_interval` above 0, other sessions end at their next refresh instead of at once. A PHP upgrade that rehashes passwords at login ends a user's other sessions once.

## Activity log

- **Reset link** (`password_reset_requested`): a link was sent, credited to the account. Requests for an address without an account, or for an inactive account, are logged without an email being sent; the address itself is never written down.
- **Reset limit** (`password_reset_limited`): logged once when a limit is reached.
- **Password reset** (`password_reset`): the password was changed with a link.

## Pages and URLs

| URL | Who |
| --- | --- |
| `/{lang}/admin/password/forgot` | anyone (a signed-in admin is sent to the dashboard) |
| `/{lang}/admin/password/forgot/sent` | after a request |
| `/{lang}/admin/password/reset/<token>` | anyone with the link |

The slug `password` is reserved for these pages. A site's own `PasswordController` always wins (`admin:doctor` says so).
