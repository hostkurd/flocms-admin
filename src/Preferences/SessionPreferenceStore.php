<?php
declare(strict_types=1);

namespace FloCMS\Admin\Preferences;

use FloCMS\Core\Session;

/** Preferences kept in the session: used while the preferences table is missing. */
final class SessionPreferenceStore implements PreferenceStore
{
    private const KEY = '_flo_admin_preferences';

    public function all(string $user): array
    {
        $all = Session::get(self::KEY, []);
        $mine = is_array($all) ? ($all[$user] ?? []) : [];
        return is_array($mine) ? $mine : [];
    }

    public function set(string $user, string $key, mixed $value): void
    {
        $all = Session::get(self::KEY, []);
        $all = is_array($all) ? $all : [];
        $mine = is_array($all[$user] ?? null) ? $all[$user] : [];
        if ($value === null) { unset($mine[$key]); } else { $mine[$key] = $value; }
        $all[$user] = $mine;
        Session::set(self::KEY, $all);
    }
}
