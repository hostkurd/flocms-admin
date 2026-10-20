<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\Database;

/**
 * Reads the value of an imported cell (0.25) the way people type it in a
 * spreadsheet, into what the form would receive: "1.250.000,50" → 1250000.50,
 * "yes" → 1, "19/10/2026" → 2026-10-19, "For rent" → rent, "Athens" → the
 * city's id. A value that cannot be read is passed on as it is, so the form's
 * own rules report it; a relation that matches no record, or several, is an
 * error of its own.
 */
final class Values
{
    private const TRUE = ['1', 'yes', 'y', 'true', 'on', 'x', '✓', 'نعم', 'بەڵێ', 'ja', 'oui', 'si', 'sí', 'ναι'];
    private const FALSE = ['0', 'no', 'n', 'false', 'off', '', '-', 'لا', 'نەخێر', 'nein', 'non', 'όχι'];

    /** @var array<string, array<string, list<string>>> field => normalized title => ids */
    private array $titles = [];

    /**
     * @param string $dateOrder 'dmy' or 'mdy' (how 05/10/2026 is read)
     * @param bool $decimalComma whether "1.250,5" means 1250.5 (files separated by ";")
     */
    public function __construct(private readonly Database $db, private readonly string $dateOrder = 'dmy', private readonly bool $decimalComma = false)
    {
    }

    /**
     * The form input for one field from its cell, or an error message.
     *
     * @return array{0: string|list<string>, 1: ?string} [input, error]
     */
    public function read(Field $field, string $cell): array
    {
        $cell = trim($cell);
        return match (true) {
            $field instanceof Fields\Toggle => [self::toggle($cell) ?? $cell, self::toggle($cell) === null ? AdminLang::get('import.not_yes_no') : null],
            $field instanceof Fields\DateTime => [self::dateTime($cell, $this->dateOrder) ?? $cell, null],
            $field instanceof Fields\Date => [self::date($cell, $this->dateOrder) ?? $cell, null],
            $field instanceof Fields\Select => [self::option($field, $cell), null],
            $field instanceof Fields\ColorPicker => [self::color($cell), null],
            $field instanceof Fields\BelongsTo => $this->belongsTo($field, $cell),
            $field instanceof Fields\BelongsToMany => $this->belongsToMany($field, $cell),
            $field instanceof Fields\KeyValue => [self::keyValue($field, $cell), null],
            $field instanceof Fields\Repeater, $field instanceof Fields\Builder => [$cell, null],
            $field instanceof Fields\RichEditor => [self::richText($cell), null],
            in_array('numeric', $field->getRules(), true) || in_array('integer', $field->getRules(), true) => [self::number($cell, $this->decimalComma), null],
            default => [$cell, null],
        };
    }

    /** Tags that make a cell HTML even without a closing tag. */
    private const VOID_TAGS = ['br', 'img', 'hr', 'video', 'iframe', 'source'];

    /**
     * A rich text cell (0.25.1). HTML is passed on as it is: the editor's own
     * allow-list cleans it when the row is saved, exactly as when it is typed,
     * images and videos included. Plain text becomes paragraphs: a blank line
     * starts a new one, a line break stays a line break.
     */
    public static function richText(string $cell): string
    {
        $cell = trim(str_replace(["\r\n", "\r"], "\n", $cell));
        if ($cell === '' || self::isHtml($cell)) { return $cell; }
        $paragraphs = preg_split('/\n\s*\n/', $cell) ?: [$cell];
        return implode('', array_map(static function (string $text): string {
            // "Tom &amp; Jerry" typed as text means "Tom & Jerry", not "&amp;amp;".
            $escaped = htmlspecialchars(html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            return '<p>' . (preg_replace('/\s*\n\s*/', '<br>', $escaped) ?? $escaped) . '</p>';
        }, $paragraphs));
    }

    /**
     * Whether a cell is HTML: a tag that is opened and closed (<p>…</p>,
     * <del>…</del>), or a void tag (<br>, <img>). "Use <b> for bold" or
     * "a < b" is text. Linear: tag names are collected, never backtracked.
     */
    public static function isHtml(string $cell): bool
    {
        if (preg_match_all('~<([a-z][a-z0-9]*)\b[^<>]*>~i', $cell, $open) === 0) { return false; }
        $opened = array_map('strtolower', $open[1]);
        if (array_intersect($opened, self::VOID_TAGS) !== []) { return true; }
        preg_match_all('~</([a-z][a-z0-9]*)\s*>~i', $cell, $close);
        return array_intersect($opened, array_map('strtolower', $close[1])) !== [];
    }

    /** "1 250 000,50", "1,250,000.50", "€ 1.250.000" → "1250000.50"; anything else as it is. */
    public static function number(string $cell, bool $decimalComma = false): string
    {
        $value = preg_replace('/[\s\x{00A0}\x{202F}\'’]|[€$£¥₺]|(?i:eur|usd|iqd)/u', '', $cell) ?? $cell;
        if (preg_match('/^[+-]?[\d.,]+$/D', $value) !== 1) { return $cell; }
        $dot = strrpos($value, '.');
        $comma = strrpos($value, ',');
        if ($dot !== false && $comma !== false) {
            $decimal = $dot > $comma ? '.' : ',';
            $value = str_replace($decimal === '.' ? ',' : '.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($comma !== false) {
            $value = !$decimalComma && preg_match('/^[+-]?\d{1,3}(,\d{3})+$/D', $value) === 1 ? str_replace(',', '', $value)
                : (substr_count($value, ',') === 1 ? str_replace(',', '.', $value) : $value);
        } elseif ($dot !== false && substr_count($value, '.') > 1 || ($dot !== false && $decimalComma && preg_match('/^[+-]?\d{1,3}(\.\d{3})+$/D', $value) === 1)) {
            $value = str_replace('.', '', $value);
        }
        return ltrim($value, '+');
    }

    public static function toggle(string $cell): ?string
    {
        $value = mb_strtolower(trim($cell));
        if (in_array($value, self::TRUE, true)) { return '1'; }
        if (in_array($value, self::FALSE, true)) { return '0'; }
        return null;
    }

    /** 2026-10-19, 19/10/2026, 19.10.2026, 10/19/2026 (mdy), an Excel day number → Y-m-d. */
    public static function date(string $cell, string $order = 'dmy'): ?string
    {
        if ($cell === '') { return ''; }
        $parsed = self::parseDate($cell, $order);
        return $parsed?->format('Y-m-d');
    }

    public static function dateTime(string $cell, string $order = 'dmy'): ?string
    {
        if ($cell === '') { return ''; }
        $parsed = self::parseDate($cell, $order, true);
        return $parsed?->format('Y-m-d H:i:s');
    }

    private static function parseDate(string $cell, string $order, bool $time = false): ?\DateTimeImmutable
    {
        // An Excel day number: 46314 or 46314.5
        if (preg_match('/^\d{5}(\.\d+)?$/D', $cell) === 1) {
            $days = (float) $cell;
            if ($days > 20000 && $days < 80000) {
                $date = (new \DateTimeImmutable('1899-12-30 00:00:00', new \DateTimeZone('UTC')))->modify('+' . (int) floor($days) . ' days');
                $seconds = (int) round(($days - floor($days)) * 86400);
                return $date->modify('+' . $seconds . ' seconds');
            }
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/D', $cell, $m) === 1) {
            return self::build((int) $m[1], (int) $m[2], (int) $m[3], $m[4] ?? '', $m[5] ?? '', $m[6] ?? '');
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/D', $cell, $m) === 1) {
            [$day, $month] = $order === 'mdy' ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]];
            return self::build((int) $m[3], $month, $day, $m[4] ?? '', $m[5] ?? '', $m[6] ?? '');
        }
        return null;
    }

    private static function build(int $year, int $month, int $day, string $hour, string $minute, string $second): ?\DateTimeImmutable
    {
        if (!checkdate($month, $day, $year)) { return null; }
        $h = $hour === '' ? 0 : (int) $hour;
        $i = $minute === '' ? 0 : (int) $minute;
        $s = $second === '' ? 0 : (int) $second;
        if ($h > 23 || $i > 59 || $s > 59) { return null; }
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $h, $i, $s));
    }

    /** The stored key of an option given by its key or its label (any case). */
    public static function option(Fields\Select $field, string $cell): string
    {
        $options = $field->getOptions();
        if (array_key_exists($cell, $options)) { return $cell; }
        $wanted = Schema::normalize($cell);
        foreach ($options as $key => $label) {
            if (Schema::normalize((string) $key) === $wanted || Schema::normalize(strip_tags((string) $label)) === $wanted) { return (string) $key; }
        }
        return $cell;
    }

    public static function color(string $cell): string
    {
        $value = strtolower(trim($cell));
        return preg_match('/^[0-9a-f]{6}$/D', $value) === 1 ? '#' . $value : $value;
    }

    /** "Parking: 2 spaces | Pool: yes" (or the stored JSON) → the stored JSON. */
    public static function keyValue(Fields\KeyValue $field, string $cell): string
    {
        if ($cell === '' || str_starts_with($cell, '[')) { return $cell; }
        $items = [];
        $translatable = $field->fields()['value']->isTranslatable();
        foreach (preg_split('/\s*[|\n]\s*/u', $cell) ?: [] as $index => $pair) {
            if (trim($pair) === '') { continue; }
            [$key, $value] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            $items[] = ['_key' => 'k' . $index, 'key' => $key, 'value' => $translatable ? [Languages::default() => $value] : $value];
        }
        return json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array{0: string, 1: ?string} */
    private function belongsTo(Fields\BelongsTo $field, string $cell): array
    {
        if ($cell === '' || !$field->hasRelation()) { return [$cell, null]; }
        [$ids, $error] = $this->related($field, [$cell]);
        return [$ids[0] ?? $cell, $error];
    }

    /** @return array{0: list<string>, 1: ?string} */
    private function belongsToMany(Fields\BelongsToMany $field, string $cell): array
    {
        if ($cell === '' || !$field->hasRelation()) { return [[], null]; }
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*[|;\n]\s*/u', $cell) ?: []), static fn (string $p): bool => $p !== ''));
        if (count($parts) === 1 && str_contains($parts[0], ',') && preg_match('/^[\d,\s]+$/D', $parts[0]) === 1) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $parts[0])), static fn (string $p): bool => $p !== ''));
        }
        return $this->related($field, $parts);
    }

    /**
     * Keys for keys or titles: a key that exists is used as it is; a title must
     * match exactly one record (case-insensitive, any language).
     *
     * @param list<string> $values
     * @return array{0: list<string>, 1: ?string}
     */
    private function related(Field $field, array $values): array
    {
        /** @var Fields\BelongsTo|Fields\BelongsToMany $field */
        $relation = $field->relation();
        // Any value may be a key (ids, slugs, UUIDs), but only an exact match counts:
        // MySQL compares "12 Main St" with a number column as 12.
        $existing = array_flip(array_values(array_intersect($values, $relation->existing($this->db, $values))));
        $ids = [];
        foreach ($values as $value) {
            if (isset($existing[$value])) { $ids[] = $value; continue; }
            $found = $this->byTitle($field->getName(), $relation, $value);
            if ($found === []) { return [$ids, AdminLang::get('import.no_match', ['label' => strip_tags($field->getLabel()), 'value' => $value])]; }
            if (count($found) > 1) { return [$ids, AdminLang::get('import.many_matches', ['label' => strip_tags($field->getLabel()), 'value' => $value])]; }
            $ids[] = $found[0];
        }
        return [array_values(array_unique($ids)), null];
    }

    /** @return list<string> */
    private function byTitle(string $name, \FloCMS\Admin\Relations\RelationSource $relation, string $title): array
    {
        $wanted = mb_strtolower(trim($title));
        if (isset($this->titles[$name][$wanted])) { return $this->titles[$name][$wanted]; }
        $ids = [];
        foreach ($relation->search($this->db, $title, 50) as $option) {
            $row = $relation->query($this->db)->select([$relation->table() . '.' . $relation->key(), $relation->table() . '.' . $relation->titleColumn()])
                ->where($relation->table() . '.' . $relation->key(), '=', $option['id'])->first();
            if ($row === null) { continue; }
            $value = $row->{$relation->titleColumn()} ?? null;
            $titles = $relation->isTranslatable() ? array_values(Translations::all($value)) : [is_scalar($value) ? (string) $value : ''];
            foreach ($titles as $text) {
                if (mb_strtolower(trim((string) $text)) === $wanted) { $ids[] = $option['id']; break; }
            }
        }
        return $this->titles[$name][$wanted] = array_values(array_unique($ids));
    }
}
