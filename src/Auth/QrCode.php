<?php
declare(strict_types=1);

namespace FloCMS\Admin\Auth;

/**
 * A small QR code writer (ISO/IEC 18004): byte mode, error correction level M,
 * versions 1–20, drawn as SVG. Enough for an otpauth:// address; written here
 * so the package needs no third-party code.
 *
 * @internal
 */
final class QrCode
{
    /**
     * Level M per version: [error correction codewords per block, blocks in group 1,
     * data codewords per group-1 block, blocks in group 2, data codewords per group-2 block].
     */
    private const BLOCKS = [
        1 => [10, 1, 16, 0, 0], 2 => [16, 1, 28, 0, 0], 3 => [26, 1, 44, 0, 0], 4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0], 6 => [16, 4, 27, 0, 0], 7 => [18, 4, 31, 0, 0], 8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37], 10 => [26, 4, 43, 1, 44], 11 => [30, 1, 50, 4, 51], 12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38], 14 => [24, 4, 40, 5, 41], 15 => [24, 5, 41, 5, 42], 16 => [28, 7, 45, 3, 46],
        17 => [28, 10, 46, 1, 47], 18 => [26, 9, 43, 4, 44], 19 => [26, 3, 44, 11, 45], 20 => [26, 3, 41, 13, 42],
    ];

    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38],
        8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58],
        13 => [6, 34, 62], 14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86], 20 => [6, 34, 62, 90],
    ];

    /** @var array<int, array<int, bool>> [row][column], true = dark */
    private array $modules = [];

    /** @var array<int, array<int, bool>> */
    private array $function = [];

    private int $size;

    /** @var list<int> */
    private static array $exp = [];

    /** @var list<int> */
    private static array $log = [];

    private function __construct(public readonly int $version)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->function = array_fill(0, $this->size, $row);
    }

    /** The QR code of $text (UTF-8 bytes). Throws when it is too long for version 20. */
    public static function encode(string $text): self
    {
        $length = strlen($text);
        foreach (self::BLOCKS as $version => [, $b1, $d1, $b2, $d2]) {
            $capacity = $b1 * $d1 + $b2 * $d2;
            $bits = 4 + ($version < 10 ? 8 : 16) + 8 * $length;
            if ($bits <= $capacity * 8) {
                $qr = new self($version);
                $qr->draw(self::codewords($text, $version, $capacity));
                return $qr;
            }
        }
        throw new \InvalidArgumentException('The text is too long for a QR code.');
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isDark(int $row, int $column): bool
    {
        return $this->modules[$row][$column];
    }

    /** The code as an SVG image (dark on white, with the 4-module quiet zone). */
    public function svg(string $label, int $pixels = 200): string
    {
        $full = $this->size + 8;
        $path = '';
        for ($r = 0; $r < $this->size; $r++) {
            for ($c = 0; $c < $this->size; $c++) {
                if ($this->modules[$r][$c]) {
                    $path .= 'M' . ($c + 4) . ' ' . ($r + 4) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" width="' . $pixels . '" height="' . $pixels
            . '" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" shape-rendering="crispEdges">'
            . '<rect width="' . $full . '" height="' . $full . '" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    /** @return list<int> the final codewords: data and error correction, interleaved */
    private static function codewords(string $text, int $version, int $capacity): array
    {
        $bits = '0100' . str_pad(decbin(strlen($text)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        if ($text === '') {
            $bits = '0100' . str_repeat('0', $version < 10 ? 8 : 16);
        }
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $data = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($data) < $capacity; $pad++) {
            $data[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }

        [$ecLength, $b1, $d1, $b2, $d2] = self::BLOCKS[$version];
        $divisor = self::divisor($ecLength);
        $blocks = [];
        $offset = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$count, $length]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($data, $offset, $length);
                $offset += $length;
                $blocks[] = [$block, self::remainder($block, $divisor)];
            }
        }

        $out = [];
        $longest = max($d1, $d2);
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as [$block]) {
                if (isset($block[$i])) { $out[] = (int) $block[$i]; }
            }
        }
        for ($i = 0; $i < $ecLength; $i++) {
            foreach ($blocks as [, $ec]) {
                $out[] = $ec[$i];
            }
        }

        return $out;
    }

    /** @param list<int> $codewords */
    private function draw(array $codewords): void
    {
        $this->drawFunctionPatterns();
        $this->drawCodewords($codewords);

        $best = 0;
        $lowest = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormat($mask);
            $penalty = $this->penalty();
            if ($penalty < $lowest) {
                $lowest = $penalty;
                $best = $mask;
            }
            $this->applyMask($mask); // undo (XOR)
        }
        $this->applyMask($best);
        $this->drawFormat($best);
    }

    private function set(int $row, int $column, bool $dark): void
    {
        $this->modules[$row][$column] = $dark;
        $this->function[$row][$column] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $size = $this->size;
        for ($i = 0; $i < $size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [3, $size - 4], [$size - 4, 3]] as [$row, $column]) {
            for ($dr = -4; $dr <= 4; $dr++) {
                for ($dc = -4; $dc <= 4; $dc++) {
                    $r = $row + $dr;
                    $c = $column + $dc;
                    if ($r >= 0 && $r < $size && $c >= 0 && $c < $size) {
                        $distance = max(abs($dr), abs($dc));
                        $this->set($r, $c, $distance !== 2 && $distance !== 4);
                    }
                }
            }
        }
        $positions = self::ALIGNMENT[$this->version];
        $last = count($positions) - 1;
        foreach ($positions as $i => $row) {
            foreach ($positions as $j => $column) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $this->set($row + $dr, $column + $dc, max(abs($dr), abs($dc)) !== 1);
                    }
                }
            }
        }
        // Reserve the format areas (drawn per mask) and the always-dark module.
        $this->drawFormat(0);
        if ($this->version >= 7) {
            $remainder = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $remainder;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($b, $a, $dark);
                $this->set($a, $b, $dark);
            }
        }
    }

    private function drawFormat(int $mask): void
    {
        $data = (0 << 3) | $mask; // level M = 00
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        $size = $this->size;
        for ($i = 0; $i <= 5; $i++) { $this->set($i, 8, $bit($i)); }
        $this->set(7, 8, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(8, 7, $bit(8));
        for ($i = 9; $i < 15; $i++) { $this->set(8, 14 - $i, $bit($i)); }
        for ($i = 0; $i < 8; $i++) { $this->set(8, $size - 1 - $i, $bit($i)); }
        for ($i = 8; $i < 15; $i++) { $this->set($size - 15 + $i, 8, $bit($i)); }
        $this->set($size - 8, 8, true);
    }

    /** @param list<int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $size = $this->size;
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) { $right = 5; }
            for ($vertical = 0; $vertical < $size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $column = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $row = $upward ? $size - 1 - $vertical : $vertical;
                    if (!$this->function[$row][$column] && $i < $total) {
                        $this->modules[$row][$column] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                    // Remainder bits stay light.
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($r = 0; $r < $this->size; $r++) {
            for ($c = 0; $c < $this->size; $c++) {
                if ($this->function[$r][$c]) { continue; }
                $invert = match ($mask) {
                    0 => ($r + $c) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($r + $c) % 3 === 0,
                    4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                    5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
                    6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
                    default => ((($r + $c) % 2) + ($r * $c) % 3) % 2 === 0,
                };
                if ($invert) { $this->modules[$r][$c] = !$this->modules[$r][$c]; }
            }
        }
    }

    private function penalty(): int
    {
        $size = $this->size;
        $score = 0;
        $dark = 0;
        $lines = [];
        for ($r = 0; $r < $size; $r++) {
            $lines[] = $this->modules[$r];
            $column = [];
            for ($c = 0; $c < $size; $c++) {
                $column[] = $this->modules[$c][$r];
                if ($this->modules[$r][$c]) { $dark++; }
                if ($r < $size - 1 && $c < $size - 1) {
                    $v = $this->modules[$r][$c];
                    if ($v === $this->modules[$r][$c + 1] && $v === $this->modules[$r + 1][$c] && $v === $this->modules[$r + 1][$c + 1]) {
                        $score += 3;
                    }
                }
            }
            $lines[] = $column;
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($i = 1; $i <= $size; $i++) {
                if ($i < $size && $line[$i] === $line[$i - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) { $score += 3 + $run - 5; }
                $run = 1;
            }
            $text = implode('', array_map(static fn (bool $v): string => $v ? '1' : '0', $line));
            $score += 40 * (substr_count('0000' . $text . '0000', '10111010000') + substr_count('0000' . $text . '0000', '00001011101'));
        }
        $percent = intdiv($dark * 100, $size * $size);
        $score += intdiv(abs($percent - 50), 5) * 10;

        return $score;
    }

    /** @return list<int> */
    private static function divisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);
                if ($j + 1 < $degree) { $result[$j] ^= $result[$j + 1]; }
            }
            $root = self::multiply($root, 2);
        }
        return $result;
    }

    /**
     * @param list<int> $data
     * @param list<int> $divisor
     * @return list<int>
     */
    private static function remainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::multiply($coefficient, $factor);
            }
        }
        return $result;
    }

    private static function multiply(int $x, int $y): int
    {
        if ($x === 0 || $y === 0) { return 0; }
        if (self::$exp === []) {
            $value = 1;
            for ($i = 0; $i < 255; $i++) {
                self::$exp[$i] = $value;
                self::$log[$value] = $i;
                $value <<= 1;
                if ($value & 0x100) { $value ^= 0x11D; }
            }
        }
        return self::$exp[(self::$log[$x] + self::$log[$y]) % 255];
    }
}
