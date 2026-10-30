# Login security: two-factor sign-in and email verification

Admin 0.35 adds two optional sign-in protections:

- **Two-factor sign-in**: after the password, a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Aegis …), or a backup code. Devices can be remembered for a while, roles can require it, and admins can reset it for someone who lost their phone.
- **Email verification**: people confirm their address by opening a one-time link: new accounts by invitation, a changed address before it applies, and anyone with a not-yet-confirmed address from a banner in the panel.

Both are off until you turn them on, and both need **flocms-core 2.7** (held sign-ins). Nothing changes in the skeleton's login action.

## Turning them on

1. Update: `composer require "hostkurd/flocms-admin:^0.35.0" -W` (brings flocms-core 2.7).
2. In `config/admin.php`:

   ```php
   'two_factor' => true,
   'email_verification' => true,
   ```

   A value from `.env` works too (`'two_factor' => Env::get('TWO_FACTOR', false)`, since 0.39.4). Write the switches in `config/admin.php` itself, not in another file merged into it: the panel registers the sign-in steps when it starts, before the site reads `.env`, by reading this file's code. `admin:doctor` notes a switch it can't see there.

3. Create the tables: `php flo admin:install`, then `php flo migrate`.
4. Check: `php flo admin:doctor` (section **Login security**).

They need:

- **Mail** set up, as for [Forgot password](password-reset.md): `MAIL_*` in `.env` and a full `APP_URL` (`https://…`), checked with `php flo admin:mail-test you@example.com`.
- For two-factor sign-in, an **`APP_KEY` of at least 32 characters** in `.env`: it encrypts the authenticator secrets. Changing `APP_KEY` later makes the stored secrets unreadable: everyone sets two-factor sign-in up again.
- The skeleton's **`auth.user_loader`** in `config/config.php` (skeleton 1.8 sets it). A sign-in step can't run without it, so a missing loader ends the session.

### All the options

```php
'two_factor' => [
    'issuer' => null,           // the name in the authenticator app; default: the panel's brand
    'remember_days' => 30,      // "Remember this device" for so many days; 0 = never remember
    'required_roles' => [],     // role numbers that must use it, for roles not set on the Roles page
    // 'table' => 'admin_two_factor', 'roles_table' => 'admin_two_factor_roles', 'devices_table' => 'admin_trusted_devices',
],
'email_verification' => [
    'expires' => 1440,          // minutes a link works (10 to 10080)
    'invite' => true,           // "Send an invitation" is on by default on the new-account form
    'required' => false,        // hold people with an unconfirmed address at "Check your email" after signing in
    // 'table' => 'admin_email_tokens',
],
```

## Two-factor sign-in

### Setting it up

Each person turns it on under **Profile → Security**:

1. **Set up** asks for the current password.
2. They scan the QR code with their app (or type the key shown under it) and enter the 6-digit code it shows. Only a right code turns it on.
3. The **10 backup codes** are shown once, with Download, Copy and Print. Each works once.

Turning it on or off emails the owner, since anyone with the password could do it. Five wrong passwords or codes on the Security tab pause these buttons for 15 minutes.

The Security tab then shows when it was turned on, how many backup codes are left (**New backup codes** makes a new set), the **remembered devices** with **Forget**, and **Turn off**. New backup codes and Turn off ask for the password or a current code.

### Signing in

After the password, the panel shows **Enter your code** (`/admin/two-factor`) instead of the dashboard:

- A code from the app, or **Use a backup code instead**.
- **Remember this device for 30 days** skips the step on this browser. The browser keeps a random token in a cookie; only a keyed hash of it is stored. Changing the password forgets every device; so do resetting and turning off two-factor sign-in.
- **Use another account** signs out.
- If two-factor sign-in is on but not working (for example `APP_KEY` was lost in a deploy, or its tables can't be read), people who use it, or whose role requires it, can't sign in until it is fixed: the step never lets a sign-in through without a code. `admin:doctor` says what is wrong.
- A code works once (one 30-second step either side is accepted for clock drift).
- Five wrong codes in a row end the sign-in: the person starts again at the login page, where the skeleton's login throttle applies. Ten wrong codes within an hour pause the account's codes for 15 minutes and email the owner, since their password is known to someone.

Until the step is done the person counts as signed out everywhere, the website included: flocms-core 2.7 "parks" the sign-in (`Auth::holdSessionsUsing()`), so no admin page or front-end check sees them as signed in.

### Requiring it for a role

On **Site → Roles**, a role's page has a **Sign-in** card with **Require two-factor**. People with that role who haven't set it up are taken to the setup at their next sign-in, before anything else, and can't turn it off. The card counts who still has to. You can change it for roles up to your own level (Super admin for its own role too); it goes to the activity log. Without the Roles page, list the role numbers in `'required_roles'`; a role listed there can't be switched off on the Roles page.

### When someone loses their phone

- With a backup code they sign in and set it up again (Turn off, then Set up).
- Without one, an admin opens their account's **More → Reset two-factor** (needs `users.manage`, with the same rules as Suspend: not your own account, not a higher role). Their app, backup codes and remembered devices stop working; **Email them** sends a short notice. If their role requires two-factor sign-in, they set it up again at their next sign-in.
- From the server, for example for the only super admin: `php flo admin:two-factor-reset sara@example.com`.

## Email verification

The skeleton's `users.is_verified` column says whether an address is confirmed. Links are sent to it and work once, for `'expires'` minutes; a newer link replaces older ones; three links per account and hour. Only a hash of each link's token is stored.

- **Invitations**: the new-account form gets **Send an invitation** (on by default with `'invite' => true`). The account is saved **Pending** without a password; the email's link confirms the address and lets the person choose a password, then the account becomes active. The skeleton already refuses Pending accounts at sign-in ("pending verification"). Turn the switch off to set a password yourself, as before.
- **Opening a link** asks first: the address is confirmed (or changed) only with the **Confirm** button, because mail scanners open links on their own. An invitation asks for the password instead.
- **The banner**: signed-in people with an unconfirmed address see **Confirm your email address** at the top of the panel, with **Send link**.
- **Changing your address** on the profile: the new address applies only when the link sent to it is opened; the old address gets a notice. Profile → Security shows the waiting address with **Send again** and **Cancel change**.
- When a changed address is confirmed, links sent to the old address (password resets, verification links) stop working.
- **Admins changing an address** on Users: it applies at once and is marked unconfirmed. **More → Send verification email** (an invitation for a Pending account) sends the link.
- A completed **password reset** confirms the address too.
- With `'required' => true`, people whose address isn't confirmed wait at **Check your email** after signing in, with **Send a new link**; opening the link lets them in. Existing accounts are affected too, so confirm addresses first.

## For developers

- `FloCMS\Admin\Auth\TwoFactor`: `status()`, `available()`, `isOn($userId)`, `isRequired($role)`, `requiredRoles()`, `setRequired($role, $bool)`, `enable()`, `disable()`, `regenerateCodes()`, `verifyCode()`, `verifyBackupCode()`.
- `FloCMS\Admin\Auth\EmailVerification`: `status()`, `available()`, `isVerified($user)`, `sendVerification($user)`, `invite($user)`, `requestChange($user, $email)`, `pendingChange($userId)`, `cancelChange($userId)`, `markVerified($userId)`.
- `FloCMS\Admin\Auth\Totp` (RFC 6238 codes, base32, the `otpauth://` address) and `QrCode` (a small QR code writer that draws SVG), both without a third-party package.
- The steps are core 2.7 held sign-ins at `/admin/two-factor` and `/admin/verify`, both guest admin actions; a site controller named `TwoFactorController` or `VerifyController` wins over them (`admin:doctor` says so).
- Activity log entries: `two_factor_enabled`, `two_factor_disabled`, `two_factor_reset`, `two_factor_codes`, `two_factor_backup_used`, `two_factor_locked`, `role_two_factor`, `email_link_sent`, `invite_sent`, `invite_accepted`, `email_verified`, `email_change_requested`, `email_changed`.

## Not in 0.35

Passkeys (WebAuthn), codes by SMS or email, public self-registration, and a separate "sign out everywhere" button (changing the password already does it).
