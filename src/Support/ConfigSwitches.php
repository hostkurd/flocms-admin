<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/**
 * Which login security switches of config/admin.php may be on, read from the
 * file's code without running it (0.39.4).
 *
 * The panel starts during Composer's autoload, before the site reads .env, and
 * must register the sign-in steps before the request is checked. Running the
 * file then would give Env::get() its default, so the switches are read from
 * the code instead: a switch counts as possibly on when its value is `true`,
 * an array (unless its 'enabled' is literally false or null), or anything that
 * is not a literal (Env::get(), getenv(), a variable, a constant...). Whether
 * the step is really asked is decided per request from the real settings.
 */
final class ConfigSwitches
{
    public const KEYS = ['two_factor', 'email_verification'];

    /** @return array{two_factor: bool, email_verification: bool} */
    public static function scan(string $file): array
    {
        $found = array_fill_keys(self::KEYS, false);
        $code = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($code) || $code === '') {
            return $found;
        }
        $tokens = self::tokens($code);
        $count = count($tokens);
        for ($i = 0; $i + 1 < $count; $i++) {
            $key = self::stringValue($tokens[$i]);
            if ($key === null || !array_key_exists($key, $found) || !self::is($tokens[$i + 1], T_DOUBLE_ARROW)) {
                continue;
            }
            $value = self::valueAt($tokens, $i + 2);
            if (self::mayBeOn($value)) {
                $found[$key] = true;
            }
        }

        return $found;
    }

    /** @param list<array{int, string}|string> $value */
    private static function mayBeOn(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        if (self::opensArray($value)) {
            // An array means on, unless its own 'enabled' entry says otherwise.
            $inner = self::arrayItems($value);
            $count = count($inner);
            for ($i = 0; $i + 1 < $count; $i++) {
                if (self::stringValue($inner[$i]) === 'enabled' && self::is($inner[$i + 1], T_DOUBLE_ARROW)) {
                    return self::mayBeOn(self::valueAt($inner, $i + 2));
                }
            }
            return true;
        }
        $text = strtolower(self::joined($value));
        if ($text === 'false' || $text === 'null' || $text === '0' || $text === "''" || $text === '""') {
            return false;
        }

        return true;
    }

    /**
     * The tokens of one value, from $start up to the comma or closing bracket
     * that ends it.
     *
     * @param list<array{int, string}|string> $tokens
     * @return list<array{int, string}|string>
     */
    private static function valueAt(array $tokens, int $start): array
    {
        $value = [];
        $depth = 0;
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_string($token)) {
                if (in_array($token, ['[', '(', '{'], true)) {
                    $depth++;
                } elseif (in_array($token, [']', ')', '}'], true)) {
                    if ($depth === 0) { break; }
                    $depth--;
                } elseif (($token === ',' || $token === ';') && $depth === 0) {
                    break;
                }
            } elseif ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            }
            $value[] = $token;
        }

        return $value;
    }

    /** @param list<array{int, string}|string> $value */
    private static function opensArray(array $value): bool
    {
        $first = $value[0];
        if ($first === '[') {
            return self::closingIndex($value, 0) === count($value) - 1;
        }
        if (self::is($first, T_ARRAY) && ($value[1] ?? null) === '(') {
            return self::closingIndex($value, 1) === count($value) - 1;
        }

        return false;
    }

    /**
     * The tokens between an array's brackets.
     *
     * @param list<array{int, string}|string> $value
     * @return list<array{int, string}|string>
     */
    private static function arrayItems(array $value): array
    {
        $open = $value[0] === '[' ? 0 : 1;

        return array_slice($value, $open + 1, count($value) - $open - 2);
    }

    /** @param list<array{int, string}|string> $tokens */
    private static function closingIndex(array $tokens, int $open): ?int
    {
        $depth = 0;
        foreach (array_slice($tokens, $open, null, true) as $index => $token) {
            if (in_array($token, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [']', ')', '}'], true)) {
                $depth--;
                if ($depth === 0) { return $index; }
            }
        }

        return null;
    }

    /**
     * The file's tokens without whitespace and comments.
     *
     * @return list<array{int, string}|string>
     */
    private static function tokens(string $code): array
    {
        try {
            $all = token_get_all($code);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter($all, static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)));
    }

    /** @param array{int, string}|string $token */
    private static function stringValue(array|string $token): ?string
    {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || strlen($token[1]) < 2) {
            return null;
        }

        return substr($token[1], 1, -1);
    }

    /** @param array{int, string}|string $token */
    private static function is(array|string $token, int $type): bool
    {
        return is_array($token) && $token[0] === $type;
    }

    /** @param list<array{int, string}|string> $tokens */
    private static function joined(array $tokens): string
    {
        return implode('', array_map(static fn ($token): string => is_array($token) ? $token[1] : $token, $tokens));
    }
}
