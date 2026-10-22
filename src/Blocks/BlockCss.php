<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Core\Auth;

/**
 * A block's own CSS (0.33), for people with the blocks.custom_css permission.
 * "&" is the block; rules without it apply inside the block. The panel checks
 * it when it is saved and scopes every rule to the block:
 *
 *     & { border-radius: 24px; }           .flo-block[data-block-id="b8f2c1"] { border-radius: 24px; }
 *     .flo-card { gap: 2rem; }       →     .flo-block[data-block-id="b8f2c1"] .flo-card { gap: 2rem; }
 *     @media (max-width: 767px) { & { padding-inline: 0; } }
 *
 * Refused: braces that don't match, rules inside rules, url() and the other
 * functions that load files, @import and other at-rules (only @media,
 * @supports, @container and @keyframes), backslashes, "<", javascript:,
 * expression(), behavior and -moz-binding.
 *
 * On the website the CSS of the blocks on a page, with the rules of the
 * theme's colours (BlockColours), is one cached stylesheet,
 * /flo-admin/asset/block-styles-<hash>.css, linked by blocks_assets() — a file,
 * so it works under a strict Content-Security-Policy. When the file can't be
 * written it is a <style> tag, with the nonce of 'block_style_nonce'.
 */
final class BlockCss
{
    public const PERMISSION = 'blocks.custom_css';
    public const MAX = 5000;
    private const AT_RULES = ['media', 'supports', 'container'];

    public static function allowed(): bool
    {
        return Auth::can(self::PERMISSION);
    }

    /** The first problem of a block's CSS as a message ("Line 3: …"), or null when it is fine. */
    public static function problem(string $css): ?string
    {
        $result = self::parse($css);
        return $result['error'] === null ? null : AdminLang::get('blocks.css_' . $result['error'][1], ['line' => (string) $result['error'][0]] + $result['error'][2]);
    }

    /** The CSS scoped to one block, or '' when it has a problem. */
    public static function scope(string $css, string $id): string
    {
        if (trim($css) === '' || preg_match('/^b[a-z0-9]{1,24}$/D', $id) !== 1) { return ''; }
        $result = self::parse($css);
        if ($result['error'] !== null) { return ''; }
        return self::write($result['items'], '.flo-block[data-block-id="' . $id . '"]');
    }

    /**
     * @return array{items: list<array<string, mixed>>, error: array{0: int, 1: string, 2: array<string, string>}|null}
     */
    private static function parse(string $css): array
    {
        if (mb_strlen($css, 'UTF-8') > self::MAX) { return ['items' => [], 'error' => [1, 'too_long', ['max' => (string) self::MAX]]]; }
        $css = str_replace("\r\n", "\n", $css);
        // Comments go (their line breaks stay, so the lines keep their numbers).
        $plain = preg_replace_callback('~/\*.*?(\*/|$)~s', static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? '', $css) ?? '';
        $lineAt = static fn (int $offset): int => substr_count(substr($plain, 0, $offset), "\n") + 1;
        // Control characters (a lone CR or a form feed ends a string in a browser, not here).
        if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $plain, $m, PREG_OFFSET_CAPTURE) === 1) {
            return ['items' => [], 'error' => [$lineAt((int) $m[0][1]), 'not_allowed', ['what' => sprintf('U+%04X', ord($m[0][0]))]]];
        }
        if (($problem = self::structure($plain)) !== null) {
            return ['items' => [], 'error' => [$lineAt($problem[0]), $problem[1], []]];
        }
        $refused = [
            '/\\\\/' => '\\', '/</' => '<',
            '/@import\b/i' => '@import',
            '/(?<![\w-])(?:-[a-z]+-)?(url|image-set|image|cross-fade|element|src|expression|paint)\s*\(/i' => null,
            '/javascript\s*:/i' => 'javascript:', '/\bbehavior\b/i' => 'behavior', '/-moz-binding/i' => '-moz-binding',
        ];
        foreach ($refused as $pattern => $what) {
            if (preg_match($pattern, $plain, $m, PREG_OFFSET_CAPTURE) === 1) {
                $what ??= strtolower($m[1][0]) . '(';
                return ['items' => [], 'error' => [$lineAt((int) $m[0][1]), 'not_allowed', ['what' => $what]]];
            }
        }
        $pos = 0;
        $error = null;
        $items = self::items($plain, $pos, false, $lineAt, $error);
        return ['items' => $error === null ? $items : [], 'error' => $error];
    }

    /**
     * Strings close on their own line, and ( and [ close in order with nothing
     * of { } ; inside them: what a browser would read differently from this
     * parser (and so out of the block) is refused. Returns [offset, problem].
     *
     * @return array{0: int, 1: string}|null
     */
    private static function structure(string $css): ?array
    {
        $stack = [];
        $length = strlen($css);
        for ($i = 0; $i < $length; $i++) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") {
                $close = strpos($css, $c, $i + 1);
                $newline = strpos($css, "\n", $i + 1);
                if ($close === false || ($newline !== false && $newline < $close)) { return [$i, 'string']; }
                $i = $close;
                continue;
            }
            if ($c === '(' || $c === '[') { $stack[] = [$c === '(' ? ')' : ']', $i]; continue; }
            if ($c === ')' || $c === ']') {
                if ($stack === [] || end($stack)[0] !== $c) { return [$i, 'brackets']; }
                array_pop($stack);
                continue;
            }
            if (($c === '{' || $c === '}' || $c === ';') && $stack !== []) { return [end($stack)[1], 'brackets']; }
        }
        return $stack !== [] ? [end($stack)[1], 'brackets'] : null;
    }

    /**
     * Rules and declarations up to the end (or the "}" of an at-rule).
     *
     * @param \Closure(int): int $lineAt
     * @param array{0: int, 1: string, 2: array<string, string>}|null $error
     * @return list<array<string, mixed>>
     */
    private static function items(string $css, int &$pos, bool $inBlock, \Closure $lineAt, ?array &$error): array
    {
        $items = [];
        $length = strlen($css);
        while ($error === null) {
            $start = $pos;
            $end = self::until($css, $pos, '{;}');
            $prelude = trim(substr($css, $start, $pos - $start));
            // The line where the item starts (its first character that isn't a space).
            $at = $lineAt(min($start + strspn($css, " \t\r\n", $start), $length));
            if ($end === null) {
                // The end of the text: a last declaration without ";".
                if ($inBlock) { $error = [$lineAt($start), 'braces', []]; break; }
                if ($prelude !== '') { $items[] = ['decl' => $prelude]; }
                break;
            }
            $pos++;
            if ($end === '}') {
                if (!$inBlock) { $error = [$lineAt($pos - 1), 'braces', []]; break; }
                if ($prelude !== '') { $items[] = ['decl' => $prelude]; }
                return $items;
            }
            if ($end === ';') {
                if ($prelude === '') { continue; }
                if (str_starts_with($prelude, '@')) { $error = [$at, 'at_rule', []]; break; }
                $items[] = ['decl' => $prelude];
                continue;
            }
            // "{": a rule or an at-rule.
            if ($prelude === '') { $error = [$at, 'selector', []]; break; }
            if (str_starts_with($prelude, '@')) {
                preg_match('/^@([a-z-]+)/i', $prelude, $m);
                $name = strtolower($m[1] ?? '');
                if (in_array($name, self::AT_RULES, true)) {
                    $inner = self::items($css, $pos, true, $lineAt, $error);
                    $items[] = ['at' => preg_replace('/\s+/', ' ', $prelude), 'items' => $inner];
                    continue;
                }
                if (preg_match('/^(?:-[a-z]+-)?keyframes$/', $name) === 1) {
                    // Kept as written: its frames hold declarations only.
                    $bodyStart = $pos;
                    $depth = 1;
                    while ($depth > 0) {
                        $c = self::until($css, $pos, '{}');
                        if ($c === null) { $error = [$at, 'braces', []]; break 2; }
                        $depth += $c === '{' ? 1 : -1;
                        if ($depth > 2) { $error = [$lineAt($pos), 'nested', []]; break 2; }
                        $pos++;
                    }
                    $items[] = ['raw' => preg_replace('/\s+/', ' ', $prelude) . '{' . trim(substr($css, $bodyStart, $pos - 1 - $bodyStart)) . '}'];
                    continue;
                }
                $error = [$at, 'at_rule', []];
                break;
            }
            // "&" is the block: only at the start of a selector, and never followed by + or ~ (its neighbours).
            foreach (self::split($prelude) as $selector) {
                $amps = substr_count($selector, '&');
                if ($amps > 1 || ($amps === 1 && preg_match('/^&(?![\w-])/', $selector) !== 1) || preg_match('/^&[^\s>+~]*\s*[+~]/', $selector) === 1) {
                    $error = [$at, 'ampersand', []];
                    break 2;
                }
            }
            $bodyStart = $pos;
            $c = self::until($css, $pos, '{}');
            if ($c === null) { $error = [$at, 'braces', []]; break; }
            if ($c === '{') { $error = [$lineAt($pos), 'nested', []]; break; }
            $items[] = ['selector' => $prelude, 'body' => trim(substr($css, $bodyStart, $pos - $bodyStart))];
            $pos++;
        }
        if ($error === null && $pos > $length) { $pos = $length; }
        return $items;
    }

    /** Moves to the next of the characters outside strings and brackets; returns it, or null at the end. */
    private static function until(string $css, int &$pos, string $stops): ?string
    {
        $length = strlen($css);
        $depth = 0;
        while ($pos < $length) {
            $c = $css[$pos];
            if ($c === '"' || $c === "'") {
                $close = strpos($css, $c, $pos + 1);
                $newline = strpos($css, "\n", $pos + 1);
                // A string that doesn't close on its line ends there.
                $pos = $close === false || ($newline !== false && $newline < $close) ? ($newline === false ? $length : $newline) : $close + 1;
                continue;
            }
            if ($c === '(' || $c === '[') { $depth++; }
            elseif (($c === ')' || $c === ']') && $depth > 0) { $depth--; }
            elseif ($depth === 0 && str_contains($stops, $c)) { return $c; }
            $pos++;
        }
        return null;
    }

    /** @param list<array<string, mixed>> $items */
    private static function write(array $items, string $scope): string
    {
        $out = '';
        $decls = [];
        foreach ($items as $item) {
            if (isset($item['decl'])) { $decls[] = rtrim((string) $item['decl'], ';'); continue; }
            if ($decls !== []) { $out .= $scope . '{' . implode(';', $decls) . '}'; $decls = []; }
            if (isset($item['raw'])) { $out .= $item['raw']; continue; }
            if (isset($item['at'])) { $out .= $item['at'] . '{' . self::write($item['items'], $scope) . '}'; continue; }
            $selectors = array_map(static function (string $selector) use ($scope): string {
                $selector = preg_replace('/\s+/', ' ', trim($selector)) ?? '';
                return str_contains($selector, '&') ? str_replace('&', $scope, $selector) : $scope . ' ' . $selector;
            }, self::split((string) $item['selector']));
            $out .= implode(',', $selectors) . '{' . preg_replace('/\s*\n\s*/', ' ', (string) $item['body']) . '}';
        }
        if ($decls !== []) { $out .= $scope . '{' . implode(';', $decls) . '}'; }
        return $out;
    }

    /** @return list<string> a selector list split at its commas (not those inside :is(…) or strings) */
    private static function split(string $selector): array
    {
        $parts = [];
        $pos = 0;
        $start = 0;
        while (self::until($selector, $pos, ',') !== null) { $parts[] = substr($selector, $start, $pos - $start); $pos++; $start = $pos; }
        $parts[] = substr($selector, $start);
        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
    }

    /* ---------------------------------------------------------------
     * On the website
     * ------------------------------------------------------------- */

    /**
     * The rule of a theme colour (only when config/admin.php lists the colours;
     * blocks.css styles the four default ones with its variables).
     */
    public static function colourRule(string $name): string
    {
        if (!BlockColours::configured() || ($colour = BlockColours::find($name)) === null) { return ''; }
        // A Section's own is-bg-<name> rules (blocks.css) are more specific: this one matches them.
        return '.has-bg-' . $name . ',.flo-section.has-bg-' . $name . '{background-color:' . $colour['value'] . ';color:' . ($colour['text'] === 'light' ? '#fff' : 'var(--flo-dark,#101828)') . '}';
    }

    /** The tag of a page's block CSS: a link to its cached file, else a <style> tag. */
    public static function tag(string $css): string
    {
        if (trim($css) === '') { return ''; }
        $hash = substr(hash('sha256', $css), 0, 24);
        $file = self::file($hash);
        if ($file !== null) {
            // Written whole, then renamed into place: a file served for a year is never half written.
            if (!is_file($file)) {
                $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($temporary, $css) !== false && !@rename($temporary, $file)) { @unlink($temporary); }
            }
            elseif ((int) @filemtime($file) < time() - 86400) { @touch($file); }
            if (is_file($file)) {
                return '<link rel="stylesheet" href="' . htmlspecialchars(\FloCMS\Admin\Assets::blockStylesUrl($hash), ENT_QUOTES, 'UTF-8') . '">' . "\n";
            }
        }
        $nonce = Admin::config()->blockStyleNonce();
        return '<style' . ($nonce !== null ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '') . '>' . str_replace('<', '\3c ', $css) . '</style>' . "\n";
    }

    /** Where a page's stylesheet is kept: storage/cache/blocks/styles-<hash>.css (null for a hash that isn't one). */
    public static function path(string $hash): ?string
    {
        return preg_match('/^[a-f0-9]{24}$/D', $hash) === 1 ? Admin::root() . '/storage/cache/blocks/styles-' . $hash . '.css' : null;
    }

    /** storage/cache/blocks/styles-<hash>.css to write, or null when the folder can't be written. */
    public static function file(string $hash): ?string
    {
        if (preg_match('/^[a-f0-9]{24}$/D', $hash) !== 1) { return null; }
        $directory = Admin::root() . '/storage/cache/blocks';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) { return null; }
        return is_writable($directory) ? $directory . '/styles-' . $hash . '.css' : null;
    }

    /** Stylesheets not used for 30 days go (now and then, from BlockRenderer). */
    public static function clean(): void
    {
        foreach (glob(Admin::root() . '/storage/cache/blocks/styles-*.css') ?: [] as $old) {
            if ((int) @filemtime($old) < time() - 30 * 86400) { @unlink($old); }
        }
    }
}
