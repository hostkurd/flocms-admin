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
    public static function id(): ?int
    {
        $id = Session::get('user_id');
        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    public static function name(): ?string
    {
        $name = trim((string) (Session::get('fullname') ?: Session::get('email') ?: ''));
        return $name === '' ? null : mb_substr($name, 0, 190);
    }
}
