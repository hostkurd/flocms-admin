<?php
declare(strict_types=1);

namespace FloCMS\Admin\Preferences;

use FloCMS\Core\App;
use FloCMS\Core\Session;
use Throwable;

/**
 * The logged-in user's personal preferences (theme, panel language, table
 * density, visible columns), read once per request.
 *
 *     Preferences::current()?->get('theme');
 *     Preferences::current()?->set('columns.posts', ['city']);
 *
 * Kept in the admin_user_preferences table; in the session while that table
 * does not exist (php flo migrate not run yet).
 */
final class Preferences
{
    /** Keys a user sets on My preferences. */
    public const KEYS = ['theme', 'language', 'density'];

    private static ?self $current = null;
    private static bool $resolved = false;

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function __construct(private readonly string $user, private readonly PreferenceStore $store)
    {
    }

    /** Preferences of the logged-in user, or null when nobody is logged in. */
    public static function current(): ?self
    {
        if (self::$resolved) {
            return self::$current;
        }
        self::$resolved = true;
        $user = Session::get('user_id');
        if ($user === null || $user === '' || !is_numeric($user)) {
            return self::$current = null;
        }
        return self::$current = new self((string) (int) $user, self::store());
    }

    /** The database store when the table exists, else the session. */
    public static function store(): PreferenceStore
    {
        $db = App::db();
        if ($db !== null) {
            try {
                $db->pdo()->query('SELECT `user_id`, `key`, `value` FROM `' . DatabasePreferenceStore::TABLE . '` WHERE 1 = 0');
                return new DatabasePreferenceStore($db);
            } catch (Throwable) {
                // Table missing: fall back to the session
            }
        }
        return new SessionPreferenceStore();
    }

    /** Forget the loaded user (tests, or after logging in as someone else). */
    public static function reset(): void
    {
        self::$current = null;
        self::$resolved = false;
    }

    public function user(): string
    {
        return $this->user;
    }

    public function persistent(): bool
    {
        return $this->store instanceof DatabasePreferenceStore;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->values === null) {
            try {
                $this->values = $this->store->all($this->user);
            } catch (Throwable) {
                $this->values = [];
            }
        }
        return $this->values;
    }

    /** Store a preference; null (or '') removes it, so the panel default applies again. */
    public function set(string $key, mixed $value): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Invalid preference key "' . $key . '".');
        }
        $value = $value === '' ? null : $value;
        $this->store->set($this->user, $key, $value);
        $values = $this->all();
        if ($value === null) { unset($values[$key]); } else { $values[$key] = $value; }
        $this->values = $values;
    }
}
