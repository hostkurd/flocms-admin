<?php
declare(strict_types=1);

namespace FloCMS\Admin\Auth;

use FloCMS\Core\Env;

/**
 * Encrypts two-factor secrets at rest with a key derived from APP_KEY
 * (AES-256-GCM). A database copy alone can't produce codes.
 *
 * @internal
 */
final class SecretBox
{
    public const MIN_KEY_LENGTH = 32;
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'v1:';

    /** Whether APP_KEY is long enough (admin:doctor and the profile page check this). */
    public static function ready(): bool
    {
        return function_exists('openssl_encrypt') && strlen(self::appKey()) >= self::MIN_KEY_LENGTH;
    }

    public static function encrypt(string $plain): string
    {
        if (!self::ready()) {
            throw new \RuntimeException('Two-factor sign-in needs APP_KEY of at least ' . self::MIN_KEY_LENGTH . ' characters in .env.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'flo-admin-2fa');
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt the two-factor secret.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** The plain value, or null when it can't be read (another APP_KEY, damaged data). */
    public static function decrypt(string $stored): ?string
    {
        if (!self::ready() || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'flo-admin-2fa');

        return $plain === false ? null : $plain;
    }

    /** A keyed hash (backup codes, device tokens): useless without APP_KEY. */
    public static function hash(string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind . '|' . $value, 'flo-admin|' . self::appKey());
    }

    private static function key(): string
    {
        return hash_hmac('sha256', 'flo-admin-2fa-secret', self::appKey(), true);
    }

    private static function appKey(): string
    {
        $key = Env::get('APP_KEY');

        return is_string($key) ? $key : '';
    }
}
