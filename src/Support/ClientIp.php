<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

use FloCMS\Core\Config;

/**
 * The visitor's IP address for rate limits. X-Forwarded-For is used only when
 * the request comes from a proxy listed in Config 'trusted_proxies'
 * (TRUSTED_PROXIES in the skeleton's .env), as for the login throttle.
 *
 * @internal
 */
final class ClientIp
{
    public static function get(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) { return '0.0.0.0'; }
        $trusted = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), (array) Config::get('trusted_proxies', []))));
        if ($trusted === [] || !self::matchesAny($remote, $trusted)) { return $remote; }

        $forwarded = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($forwarded as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) { break; }
            if (!self::matchesAny($ip, $trusted)) { return $ip; }
        }
        return $remote;
    }

    /** @param list<string> $ranges exact IPs or CIDR ranges */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) { return false; }
        foreach ($ranges as $range) {
            [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
            $net = @inet_pton($subnet);
            if ($net === false || strlen($net) !== strlen($binary)) { continue; }
            $length = $bits === null ? strlen($binary) * 8 : (int) $bits;
            if ($length < 0 || $length > strlen($binary) * 8) { continue; }
            $bytes = intdiv($length, 8);
            if (substr($binary, 0, $bytes) !== substr($net, 0, $bytes)) { continue; }
            $rest = $length % 8;
            if ($rest === 0) { return true; }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($binary[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) { return true; }
        }
        return false;
    }
}
