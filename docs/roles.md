# Roles and permissions

Admin 0.21 adds **Site → Roles** (`/admin/roles`): change what each role may do, add roles, and see who holds them, without editing `config/config.php`. It needs flocms-core 2.4 and one migration.

```bash
composer require "hostkurd/flocms-admin:^0.21" -W   # brings flocms-core 2.4
php flo admin:install                               # adds the roles migration (nothing is overwritten)
php flo migrate                                     # creates admin_roles
php flo admin:doctor                                # checks it
```

The page needs the permission `roles.manage`. The skeleton's Super Admin has it through `*`; give it to another role on the page itself or in `config/config.php`.

## How it works

`config/config.php` stays the default: its `roles`, `permissions` and `admin_access_roles` are what you see on the page at first ("From config").

- **Changing a config role** saves a row in `admin_roles` for that role ("From config · changed"). **Reset to default** deletes the row and the config values apply again. Saving a role back to exactly its config values does the same.
- **New roles** live only in the table ("Custom"). They get the next free role number (4, 5, …); the number never changes, because `users.role` stores it.
- The package gives the result to flocms-core with `Auth::loadRolesUsing()`, once per request (one small query on requests that check a permission; none on pages that don't). So every check sees the same roles: the panel, the skeleton's login and controllers, your own controllers' `$actionPermissions`, `Auth::can()` in templates, the API.
- Permission changes apply on each person's next request. Panel access (who may open `/admin` at all) follows on the next session refresh, every admin request with the skeleton's default `auth.refresh_interval` of 0.
- When the table is missing or can't be read, or with `'roles' => false` in `config/admin.php`, the config roles apply unchanged. That is also the emergency switch when nobody can log in: edit `config/admin.php` (no shell needed), fix the roles, switch it on again.

## The list

Each role with its level, panel access, number of users, number of permissions and source. Edit, **Reset to default** (changed config roles, asks first) and **Delete** (roles added in the panel) appear only where allowed. The top role shows **Locked**.

## Editing a role

The page has the permission grid on the left and the role's details on the right.

**Details**

- **Name**, per language (the language badge opens the other languages).
- **Level**: the role's rank. A higher level manages users of a lower one; it must stay below your own level.
- **Can open the admin panel** (`admin_access_roles`): off for roles used only on the public site.
- A summary, a note on where the role comes from, and the first users holding it.

**The grid**, built from what the panel knows, so a new resource gets a row without any code:

| Group | Rows | Group switch |
| --- | --- | --- |
| Content | One row per resource: View, Create, Edit, Delete (its own `$permissionPrefix` or `$permissions` are respected) | **All content** = `content.*` (your `permission_prefix`) |
| Media | The media library: View, Upload, Edit, Delete (`content.media.*`) | covered by All content |
| Settings | One box per settings page (pages that share a permission share a box) | **All settings pages** = `settings.*` |
| Users | Manage users, Assign roles, Manage users of any level | |
| System | View the activity log, Manage roles | |
| Actions and widgets | Actions and widgets declared with `->permission()` / `$permission` | |
| Site | Names from `config/admin.php` (below) | |
| Other | Any other name the role has, as removable chips, and a field to add names (`reports.export`, `reports.*`) | |

- A group switch saves its wildcard and ticks every box it covers, including resources added later. Turned off, the boxes stay ticked and can be changed one by one.
- Ticking Create, Edit or Delete also ticks View (the list is how a record is reached); unticking View clears the row.
- A box ticked through a wider pattern (`*`, a switch) is shown pale and changes with that pattern. Without JavaScript, turning a switch off keeps the permissions it covered as separate ticks.

**Name your own permissions** so they get a proper row instead of appearing under Other:

```php
// config/admin.php
'roles' => [
    'permissions' => [
        'reports.export' => 'Export reports',      // or a lang key: 'admin.perm.export'
        'shop.refund' => 'Refund orders',
    ],
],
```

## Safety rules

The server enforces each rule; the page only hides what would be refused.

1. **The top role is locked**: the highest-level role holding `*` (Super Admin by default) can't be edited, reset or deleted on the page.
2. **Only roles below your level**: you can change, create and delete roles whose level is lower than yours. Your own role is read-only for you.
3. **No giving what you don't hold**: a box is enabled only for permissions you have; a wildcard only if you hold it or a wider one. Patterns you don't hold stay as they were when you save.
4. **Levels stay below yours**, so nobody creates a role that outranks them.
5. **Someone keeps the keys**: a save, reset or delete that would leave no active user with panel access and `roles.manage` is refused, and so is one made while the users table can't be read (it can't be checked).

**Reset** gives the config values back, so it is offered only when you could set them yourself: the config level below yours and every permission that changes held.

Role 0 (people who signed up on the website) can be given permissions for your own site code; while its panel access is on, its page shows a warning.

## Deleting a role

Only roles added in the panel can be deleted (config roles are reset). If users hold the role, the dialog asks which role they move to: a role below your level that you may give (with `users.assign_role`); the highest level at or below the deleted role's is preselected, a config role first. They move in the same transaction as the delete, so nobody keeps the deleted number, and a new role never gets a number users still hold. Without JavaScript, Delete opens a confirmation page.

## Activity log and search

Every change is an activity entry under **Roles**: name, level and panel access before → after, then **Given** and **Taken away** in words ("Posts → Edit", "All content"). Ctrl+K finds roles by name for people with `roles.manage`.

## Levels and flocms-core 2.4

Before 2.4 the role number was the rank, so a new role could not sit between Editor (1) and Admin (2). flocms-core 2.4 adds levels: `Auth::canAssignRole()` and `Auth::canManageUser()` compare `Auth::level($role)`, which is the role number unless a level is set. The Roles page sets levels for the roles it stores; your own code can use `Auth::level()` and `Auth::roles()` (the names, including roles added in the panel).

## Commands

| Command | |
| --- | --- |
| `php flo admin:roles` | The roles in force: number, name, level, panel access, source, permissions |
| `php flo admin:roles-reset [role]` | Undo the Roles page for one role, or all (asks first). Config roles get their config values back; roles added in the panel are removed unless users hold them |

## Upgrading the skeleton

Skeleton 1.8.7 asks flocms-core for admin access at login (`Auth::hasAdminAccess()`), so a role added on the page can sign in at once. Older skeletons keep their own list of admin roles at login; FloCMS corrects the session on the next admin request (with `auth.user_loader`, which the skeleton sets), so it works there too, one redirect later.
