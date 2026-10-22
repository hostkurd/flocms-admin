<?php
declare(strict_types=1);

namespace FloCMS\Admin\Auth;

/**
 * Time-based one-time codes (RFC 6238, as authenticator apps use them):
 * HMAC-SHA1, 6 digits, 30-second steps, one step either side for clock drift.
 *
 * @internal
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    public const WINDOW = 1;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret: 20 bytes (160 bits), the size RFC 4226 recommends. */
    public static function newSecret(): string
    {
        return random_bytes(20);
    }

    /** The code for $step (a 30-second step counted from 1970). */
    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF), $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * The step $code belongs to, within the drift window and later than
     * $lastStep (a code works once), or null.
     */
    public static function verify(string $secret, string $code, int $lastStep = 0, ?int $time = null): ?int
    {
        $code = self::clean($code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $now = self::step($time);
        $found = null;
        // Compare every candidate, so the time taken doesn't tell which one matched.
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if (hash_equals(self::code($secret, $step), $code) && $step > $lastStep && $found === null) {
                $found = $step;
            }
        }

        return $found;
    }

    /** Digits only: people type "482 913" or paste "482-913". */
    public static function clean(string $code): string
    {
        return (string) preg_replace('/\D+/', '', mb_substr($code, 0, 32));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $bytes === '' ? '' : $out;
    }

    public static function base32Decode(string $text): ?string
    {
        $text = strtoupper((string) preg_replace('/[\s=-]+/', '', $text));
        if ($text === '' || strspn($text, self::ALPHABET) !== strlen($text)) {
            return null;
        }
        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr((int) bindec($chunk));
            }
        }

        return $out;
    }

    /** The key in groups of four, for typing into an app by hand. */
    public static function displayKey(string $secret): string
    {
        return trim(chunk_split(self::base32Encode($secret), 4, ' '));
    }

    /** The otpauth:// address the QR code holds (Key Uri Format). */
    public static function uri(string $secret, string $issuer, string $account): string
    {
        $issuer = trim(str_replace(':', '', $issuer)) ?: 'FloCMS';
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?secret=' . self::base32Encode($secret) . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }
}
