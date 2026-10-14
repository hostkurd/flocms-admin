<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;

use FloCMS\Core\{Session, Csrf, HttpException};

/** Session authority shared by Reactive and standard action forms. */
final class OperationStore
{
    private const KEY = '_flo_admin_operations';
    private static function all(): array
    {
        return array_filter((array) Session::get(self::KEY, []), static fn ($entry): bool => is_array($entry) && is_int($entry['issued'] ?? null) && $entry['issued'] > time() - 1800);
    }
    public static function issue(string $slug, string $placement, string $name, string $id, string $fingerprint): string
    {
        $token = bin2hex(random_bytes(16));
        $entries = self::all();
        $entries[$token] = compact('slug', 'placement', 'name', 'id', 'fingerprint') + ['issued' => time(), 'actor' => (string) Session::get('user_id', ''), 'csrf' => hash('sha256', Csrf::token())];
        Session::set(self::KEY, array_slice($entries, -32, null, true));
        return $token;
    }
    public static function get(string $token, string $slug, string $placement, string $name, string $id): array
    {
        $entry = self::all()[$token] ?? null;
        if ($entry === null || ($entry['actor'] ?? null) !== (string) Session::get('user_id', '')
            || !hash_equals((string) ($entry['csrf'] ?? ''), hash('sha256', Csrf::token())) || array_intersect_key($entry, array_flip(['slug', 'placement', 'name', 'id'])) !== compact('slug', 'placement', 'name', 'id')) { throw new HttpException(409); }
        return $entry;
    }
    public static function consume(string $token): void
    {
        $entries = self::all(); unset($entries[$token]); Session::set(self::KEY, $entries);
    }
}
