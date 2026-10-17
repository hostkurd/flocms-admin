<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

use FloCMS\Core\Session;

/**
 * The person doing something in the panel, as the trash and the activity log
 * remember them: the user id and the name shown at that moment. Commands and
 * scripts have no user (null), shown as "System".
 *
 * @internal
 */
final class Actor
{
    private static int $system = 0;

    /**
     * Run work as "System" (no user), such as the hourly cleanup that a panel
     * page runs: what it logs is not credited to whoever opened that page.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function asSystem(callable $work): mixed
    {
        self::$system++;
        try { return $work(); } finally { self::$system--; }
    }

    public static function id(): ?int
    {
        if (self::$system > 0) { return null; }
        $id = Session::get('user_id');
        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    public static function name(): ?string
    {
        if (self::$system > 0) { return null; }
        $name = trim((string) (Session::get('fullname') ?: Session::get('email') ?: ''));
        return $name === '' ? null : mb_substr($name, 0, 190);
    }
}
