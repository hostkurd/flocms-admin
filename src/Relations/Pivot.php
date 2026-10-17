<?php
declare(strict_types=1);

namespace FloCMS\Admin\Relations;

use FloCMS\Core\Database;

/**
 * A pivot table linking records to related records, one row per pair:
 *
 *     property_feature (property_id, feature_id [, sort])
 *
 * @internal
 */
final class Pivot
{
    public function __construct(
        public readonly string $table,
        public readonly string $ownerKey,
        public readonly string $relatedKey,
        public readonly string $parentKey = 'id',
        public readonly ?string $orderColumn = null
    ) {
        foreach ([$table, $ownerKey, $relatedKey, $parentKey] as $identifier) { RelationSource::identifier($identifier); }
        if ($orderColumn !== null) { RelationSource::identifier($orderColumn); }
        if ($ownerKey === $relatedKey) { throw new \InvalidArgumentException('The two pivot key columns must differ.'); }
    }

    public function withOrder(?string $column): self
    {
        return new self($this->table, $this->ownerKey, $this->relatedKey, $this->parentKey, $column);
    }

    /**
     * Related keys per owner, in their saved order.
     *
     * @param list<string> $owners
     * @return array<string, list<string>>
     */
    public function related(Database $db, array $owners): array
    {
        $owners = RelationSource::keys($owners);
        $map = array_fill_keys($owners, []);
        foreach (array_chunk($owners, 500) as $chunk) {
            $query = $db->table($this->table)->select([$this->ownerKey, $this->relatedKey])->whereIn($this->ownerKey, $chunk);
            if ($this->orderColumn !== null) { $query = $query->orderBy($this->orderColumn, 'asc'); }
            foreach ($query->orderBy($this->relatedKey, 'asc')->get() as $row) {
                $map[(string) $row->{$this->ownerKey}][] = (string) $row->{$this->relatedKey};
            }
        }
        return $map;
    }

    /**
     * Make the pivot rows of $owner exactly $related: delete removed pairs,
     * insert new ones, keep unchanged rows (and their other columns). With an
     * order column, every pair gets its position (1, 2, 3…).
     *
     * @param list<string> $related
     */
    public function sync(Database $db, string $owner, array $related): void
    {
        $related = RelationSource::keys($related);
        $current = $this->related($db, [$owner])[$owner] ?? [];
        $positions = [];
        if ($this->orderColumn !== null) {
            foreach ($db->table($this->table)->select([$this->relatedKey, $this->orderColumn])->where($this->ownerKey, '=', $owner)->get() as $row) {
                $positions[(string) $row->{$this->relatedKey}] = (int) $row->{$this->orderColumn};
            }
        }
        $removed = array_values(array_diff($current, $related));
        foreach (array_chunk($removed, 500) as $chunk) {
            $db->table($this->table)->where($this->ownerKey, '=', $owner)->whereIn($this->relatedKey, $chunk)->delete();
        }
        foreach ($related as $index => $id) {
            if (!in_array($id, $current, true)) {
                $row = [$this->ownerKey => $owner, $this->relatedKey => $id];
                if ($this->orderColumn !== null) { $row[$this->orderColumn] = $index + 1; }
                $db->table($this->table)->insert($row);
            } elseif ($this->orderColumn !== null && ($positions[$id] ?? null) !== $index + 1) {
                $db->table($this->table)->where($this->ownerKey, '=', $owner)->where($this->relatedKey, '=', $id)->update([$this->orderColumn => $index + 1]);
            }
        }
    }

    /** Remove every pivot row of $owner (the owner is being deleted). */
    public function detach(Database $db, string $owner): void
    {
        $db->table($this->table)->where($this->ownerKey, '=', $owner)->delete();
    }

    /** SQL condition: the owner has at least one of $related. @return array{0: string, 1: list<string>} */
    public function hasAny(array $related): array
    {
        $related = RelationSource::keys($related);
        $marks = implode(', ', array_fill(0, count($related), '?'));
        return ['`' . $this->parentKey . '` IN (SELECT `' . $this->ownerKey . '` FROM `' . $this->table . '` WHERE `' . $this->relatedKey . '` IN (' . $marks . '))', $related];
    }
}
