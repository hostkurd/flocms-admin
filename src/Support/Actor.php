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

    /** @var array{0: ?int, 1: ?string}|null */
    private static ?array $as = null;

    /**
     * Run work credited to someone who is not signed in, such as a person who
     * resets their password with an emailed link (0.26).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function as(?int $id, ?string $name, callable $work): mixed
    {
        $previous = self::$as;
        $name = $name !== null ? trim($name) : null;
        self::$as = [$id, $name === '' || $name === null ? null : mb_substr($name, 0, 190)];
        try { return $work(); } finally { self::$as = $previous; }
    }

    public static function id(): ?int
    {
        if (self::$as !== null) { return self::$as[0]; }
        if (self::$system > 0) { return null; }
        $id = Session::get('user_id');
        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    public static function name(): ?string
    {
        if (self::$as !== null) { return self::$as[1]; }
        if (self::$system > 0) { return null; }
        $name = trim((string) (Session::get('fullname') ?: Session::get('email') ?: ''));
        return $name === '' ? null : mb_substr($name, 0, 190);
    }
}
