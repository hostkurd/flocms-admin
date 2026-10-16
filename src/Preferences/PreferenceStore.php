<?php
declare(strict_types=1);

namespace FloCMS\Admin\Preferences;

/**
 * Where a user's personal preferences are kept: the admin_user_preferences
 * table, or the session (before the table exists).
 */
interface PreferenceStore
{
    /** @return array<string, mixed> every preference of the user, decoded */
    public function all(string $user): array;

    /** Store a value; null removes the preference. */
    public function set(string $user, string $key, mixed $value): void;
}
