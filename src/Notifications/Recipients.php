<?php
declare(strict_types=1);

namespace FloCMS\Admin\Notifications;

use FloCMS\Admin\Roles\Roles;
use FloCMS\Admin\Users\UsersResource;
use FloCMS\Core\App;

/**
 * Who receives a stored notification (0.41). Only active users, and only
 * roles that may open the admin panel ('admin_access_roles'; without that
 * setting, roles that hold any permission):
 *
 *     Recipients::permission('content.inquiries.view') // whoever may see that
 *     Recipients::role(2)                              // a role, by its number
 *     Recipients::users([4, 9])                        // chosen people
 *     Recipients::everyone()                           // everyone in the panel
 */
final class Recipients
{
    /** @param array{type: string, value: mixed} $audience */
    private function __construct(private readonly array $audience) {}

    /** @param list<int>|int $ids */
    public static function users(array|int $ids): self
    {
        return new self(['type' => 'users', 'value' => array_values(array_map('intval', (array) $ids))]);
    }

    public static function role(int $number): self
    {
        return new self(['type' => 'role', 'value' => $number]);
    }

    /** Everyone whose role grants this permission (as Auth::can() checks it). */
    public static function permission(string $permission): self
    {
        if (!Roles::validPattern($permission) || str_contains($permission, '*')) {
            throw new \InvalidArgumentException('Recipients::permission() takes a permission name such as content.posts.view.');
        }
        return new self(['type' => 'permission', 'value' => $permission]);
    }

    public static function everyone(): self
    {
        return new self(['type' => 'everyone', 'value' => null]);
    }

    /**
     * The user ids it reaches now: active users whose role opens the panel.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $db = App::db() ?? throw new \RuntimeException('Notifications need a database connection.');
        $all = Roles::all();
        $roles = array_filter($all, static fn ($role): bool => $role->adminAccess);
        // A site without 'admin_access_roles': roles holding any permission count as panel roles.
        if ($roles === [] && \FloCMS\Core\Auth::adminAccessRoles() === null) {
            $roles = array_filter($all, static fn ($role): bool => $role->permissions !== []);
        }
        $type = $this->audience['type'];
        if ($type === 'role') {
            $roles = array_filter($roles, fn ($role): bool => $role->number === (int) $this->audience['value']);
        } elseif ($type === 'permission') {
            $roles = array_filter($roles, fn ($role): bool => $role->grants((string) $this->audience['value']));
        }
        if ($roles === []) { return []; }

        $query = $db->table(Roles::usersTable())->select(['id'])
            ->whereIn('role', array_map(static fn ($role): int => $role->number, array_values($roles)))
            ->where('status', '=', UsersResource::ACTIVE);
        if ($type === 'users') {
            $ids = array_values(array_filter($this->audience['value'], static fn (int $id): bool => $id > 0));
            if ($ids === []) { return []; }
            $query = $query->whereIn('id', array_slice($ids, 0, Inbox::MAX_RECIPIENTS));
        }
        return array_map(static fn ($row): int => (int) $row->id, $query->orderBy('id', 'asc')->limit(Inbox::MAX_RECIPIENTS)->get());
    }
}
