<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table;

use Closure;
use FloCMS\Admin\Relations\RelationSource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Dates;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\Database;
use InvalidArgumentException;

/**
 * One way to group a list's rows under heading rows (0.38):
 *
 *     $table->groups([
 *         Group::make('city_id')->label('City'),             // titles of the related records
 *         Group::make('status'),                             // the form Select's labels and colours
 *         Group::make('created_at')->date('month'),          // "October 2026"
 *     ])->defaultGroup('status');
 *
 * Titles come from what the list already knows (a BelongsTo field or a
 * RelationColumn of the same name, a Select, a BadgeColumn); titleUsing()
 * sets your own.
 */
final class Group
{
    private ?string $label = null;
    private ?Closure $title = null;
    private ?string $date = null;
    private string $direction = 'asc';
    private bool $collapsible = true;
    private ?RelationSource $relation = null;
    /** @var array<string, string> */
    private array $options = [];
    /** @var array<string, string> */
    private array $colors = [];
    /** @var array<string, string> key => title, loaded per page */
    private array $titles = [];

    private function __construct(private readonly string $column)
    {
    }

    public static function make(string $column): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $column) !== 1) { throw new InvalidArgumentException('Group::make() takes a column name.'); }
        return new self($column);
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    /** Your own heading text: fn (?string $key, object $firstRow): string (escaped). */
    public function titleUsing(Closure $title): self
    {
        $this->title = $title;
        return $this;
    }

    /** Group a date or date-time column by 'day', 'month' or 'year'. */
    public function date(string $bucket = 'day'): self
    {
        if (!in_array($bucket, ['day', 'month', 'year'], true)) { throw new InvalidArgumentException('Group::date() takes "day", "month" or "year".'); }
        $this->date = $bucket;
        return $this;
    }

    /** The groups' order when someone picks this group ('asc' or 'desc'). */
    public function direction(string $direction): self
    {
        $this->direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        return $this;
    }

    /** false: the headings have no collapse button. */
    public function collapsible(bool $collapsible = true): self
    {
        $this->collapsible = $collapsible;
        return $this;
    }

    /** The related records' titles head the groups. @internal */
    public function useRelation(RelationSource $relation): void
    {
        $this->relation ??= $relation;
    }

    /** @param array<string, string> $options @param array<string, string> $colors @internal */
    public function useOptions(array $options, array $colors = []): void
    {
        if ($this->options === []) { $this->options = $options; }
        $this->colors += $colors;
    }

    public function getName(): string { return $this->column; }
    public function getDirection(): string { return $this->direction; }
    public function isCollapsible(): bool { return $this->collapsible; }
    public function getDate(): ?string { return $this->date; }

    public function getLabel(): string
    {
        return AdminLang::label($this->label ?? Str::headline(preg_replace('/_id$/', '', $this->column) ?? $this->column));
    }

    /** The group's key as SQL over $reference (a column reference such as `listings`.`city_id`). */
    public function keySql(string $reference, string $driver): string
    {
        if ($this->date === null) { return $reference; }
        $formats = ['day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'];
        return $driver === 'sqlite'
            ? "strftime('" . $formats[$this->date] . "', " . $reference . ')'
            : 'DATE_FORMAT(' . $reference . ", '" . $formats[$this->date] . "')";
    }

    /**
     * The list's query ordered by this group first (empty values last), in
     * $direction; relation groups by the related title.
     */
    public function orderQuery(Database $query, string $table, string $lang, ?string $direction = null): Database
    {
        $dir = strtoupper(($direction ?? $this->direction) === 'desc' ? 'desc' : 'asc');
        $reference = '`' . $table . '`.`' . $this->column . '`';
        $key = $this->keySql($reference, $query->driver());
        $sort = $key;
        if ($this->relation !== null && $this->title === null) {
            $relation = $this->relation;
            $title = '`' . $relation->titleColumn() . '`';
            if ($relation->isTranslatable()) {
                $default = Languages::default();
                $language = in_array($lang, Languages::all(), true) ? $lang : $default;
                $store = Translations::store();
                $title = 'COALESCE(' . $store->sortExpression($query, $relation->titleColumn(), $language, $default) . ', ' . $store->sortExpression($query, $relation->titleColumn(), $default, $default) . ')';
            }
            $sort = '(SELECT ' . $title . ' FROM `' . $relation->table() . '` flo_r WHERE flo_r.`' . $relation->key() . '` = ' . $reference . ' LIMIT 1)';
        }
        return $query->orderByRaw('CASE WHEN ' . $key . ' IS NULL THEN 1 ELSE 0 END, ' . $sort . ' ' . $dir . ', ' . $key . ' ' . $dir);
    }

    /** A row's group key, as the SQL key computes it ('' for no value is kept apart from null). */
    public function keyOf(object $row): ?string
    {
        $value = property_exists($row, $this->column) ? $row->{$this->column} : null;
        if ($value === null || !is_scalar($value)) { return null; }
        $value = (string) $value;
        if ($this->date !== null) {
            $time = strtotime($value);
            if ($time === false) { return null; }
            return date(['day' => 'Y-m-d', 'month' => 'Y-m', 'year' => 'Y'][$this->date], $time);
        }
        return $value;
    }

    /**
     * Load the related titles for these keys (one query), before headings
     * are drawn. @internal
     *
     * @param list<?string> $keys
     */
    public function prepareTitles(array $keys): void
    {
        $this->titles = [];
        $db = \FloCMS\Core\App::db();
        if ($this->relation === null || $db === null) { return; }
        $this->titles = $this->relation->titles($db, array_values(array_filter($keys, static fn (?string $k): bool => $k !== null && $k !== '')));
    }

    /** The heading's text for a key. */
    public function title(?string $key, object $firstRow): string
    {
        if ($this->title !== null) {
            $text = ($this->title)($key, $firstRow);
            return is_scalar($text) ? (string) $text : '';
        }
        if ($key === null || $key === '') { return AdminLang::get('groups.empty', ['label' => $this->getLabel()]); }
        if ($this->date !== null) {
            $time = strtotime(strlen($key) === 4 ? $key . '-01-01' : (strlen($key) === 7 ? $key . '-01' : $key));
            if ($time === false) { return $key; }
            return match ($this->date) { 'year' => Dates::year($time), 'month' => Dates::month($time), default => Dates::weekday($time) };
        }
        if ($this->relation !== null) { return $this->titles[$key] ?? AdminLang::get('relations.missing', ['id' => $key]); }
        return $this->options[$key] ?? $key;
    }

    /** The badge colour of a key (Select / BadgeColumn colours), or null. */
    public function color(?string $key): ?string
    {
        return $key !== null && isset($this->colors[$key]) ? $this->colors[$key] : null;
    }
}
