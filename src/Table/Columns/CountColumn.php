<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;
use FloCMS\Core\App;

/**
 * How many related records each row has (0.22), e.g. a property's units:
 *
 *     CountColumn::make('units')->relation(UnitResource::class, 'property_id')
 *
 * Counted with one grouped query per page, through the child resource's
 * query() (archived units it hides are not counted). Not sortable or searchable.
 */
final class CountColumn extends Column
{
    /** @var class-string<Resource>|null */
    private ?string $child = null;
    private string $foreignKey = '';
    private string $ownerKey = 'id';
    /** @var array<string, int> owner key => count */
    private array $counts = [];
    private bool $prepared = false;
    private bool $allowed = true;

    /**
     * @param class-string<Resource> $resource the related resource (UnitResource::class)
     * @param string $foreignKey its column holding this record's key ('property_id')
     * @param string $ownerKey this table's column it points to (default 'id')
     */
    public function relation(string $resource, string $foreignKey, string $ownerKey = 'id'): static
    {
        if (!is_subclass_of($resource, Resource::class)) {
            throw new \InvalidArgumentException('CountColumn::relation() expects a resource class.');
        }
        foreach ([$foreignKey, $ownerKey] as $column) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) { throw new \InvalidArgumentException('"' . $column . '" is not a valid column name.'); }
        }
        $this->child = $resource;
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;
        return $this;
    }

    public function sortable(bool $sortable = true): static
    {
        if ($sortable) { throw new \LogicException('Count columns cannot be sorted yet.'); }
        return $this;
    }

    public function searchable(bool $searchable = true): static
    {
        if ($searchable) { throw new \LogicException('Count columns cannot be searched.'); }
        return $this;
    }

    public function isDatabaseColumn(): bool
    {
        return false;
    }

    public function detectTranslatable(bool $translatable): void
    {
    }

    public function prepareRecords(array $records): void
    {
        $this->prepared = true;
        $this->counts = [];
        $db = App::db();
        // Counts only for people who may view the related records.
        $this->allowed = $this->child !== null && \FloCMS\Core\Auth::can($this->child::permission('view'));
        if ($db === null || !$this->allowed) { return; }
        $owners = array_values(array_unique(array_filter(array_map(fn (object $r): string => is_scalar($r->{$this->ownerKey} ?? null) ? (string) $r->{$this->ownerKey} : '', $records), static fn (string $id): bool => $id !== '')));
        if ($owners === []) { return; }
        $child = $this->child;
        $key = '`' . $this->foreignKey . '`';
        $rows = (new $child())->query($db->table($child::getTable()))
            ->selectRaw($key . ' AS owner, COUNT(*) AS total')
            ->whereIn($this->foreignKey, $owners)
            ->groupBy($this->foreignKey)
            ->get();
        foreach ($rows as $row) { $this->counts[(string) $row->owner] = (int) $row->total; }
    }

    public function value(object $record): mixed
    {
        if (!$this->prepared) { $this->prepareRecords([$record]); }
        return $this->counts[(string) ($record->{$this->ownerKey} ?? '')] ?? 0;
    }

    public function render(object $record): string
    {
        $count = (int) $this->value($record);
        if (!$this->allowed) { return '<span class="fa-muted">&ndash;</span>'; }
        return '<span class="fa-count-cell' . ($count === 0 ? ' is-zero' : '') . '">' . Str::e((string) $count) . '</span>';
    }

    public function cellClass(): string
    {
        return 'fa-cell-number';
    }
}
