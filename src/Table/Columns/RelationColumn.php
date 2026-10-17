<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Relations\DefinesPivot;
use FloCMS\Admin\Relations\DefinesRelation;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;
use FloCMS\Core\App;

/**
 * Titles of related records in the list, in the admin's language:
 *
 *     RelationColumn::make('city_id')->resource(CityResource::class)
 *     RelationColumn::make('features')->resource(FeatureResource::class)
 *         ->pivot('property_feature', 'property_id', 'feature_id')->limit(3)   // "Pool, Garden +2"
 *
 * Titles are loaded once per page (one query per column), not once per row.
 * A many-to-many column is not sortable; a one-to-one column sorts by its key.
 */
final class RelationColumn extends Column
{
    use DefinesRelation;
    use DefinesPivot;

    private int $limit = 3;
    /** @var array<string, string> key => title */
    private array $titles = [];
    /** @var array<string, list<string>> owner => related keys */
    private array $related = [];
    private bool $prepared = false;

    /** Many-to-many: show at most this many titles, then "+n". */
    public function limit(int $limit): static
    {
        $this->limit = max(1, min(20, $limit));
        return $this;
    }

    public function searchable(bool $searchable = true): static
    {
        if ($searchable) {
            throw new \LogicException('Relation columns cannot be searchable yet; search the record\'s own columns.');
        }
        return $this;
    }

    public function isSortable(): bool
    {
        return $this->getPivot() === null && parent::isSortable();
    }

    public function isDatabaseColumn(): bool
    {
        return $this->getPivot() === null;
    }

    public function detectTranslatable(bool $translatable): void
    {
        // Titles come from the related table
    }

    public function prepareRecords(array $records): void
    {
        $db = App::db();
        if ($db === null || !$this->hasRelation()) {
            return;
        }
        $pivot = $this->getPivot();
        if ($pivot !== null) {
            $owners = array_map(static fn (object $r): string => (string) ($r->{$pivot->parentKey} ?? ''), $records);
            $this->related = $pivot->related($db, $owners);
            $ids = array_merge([], ...array_values($this->related));
        } else {
            $ids = array_map(fn (object $r): string => (string) ($r->{$this->name} ?? ''), $records);
        }
        $this->titles = $this->relation()->titles($db, $ids);
        $this->prepared = true;
    }

    public function render(object $record): string
    {
        if (!$this->prepared) {
            $this->prepareRecords([$record]);
        }
        $pivot = $this->getPivot();
        if ($pivot === null) {
            $id = (string) ($record->{$this->name} ?? '');
            return $id === '' ? '<span class="fa-muted">&ndash;</span>' : $this->title($id);
        }
        $ids = $this->related[(string) ($record->{$pivot->parentKey} ?? '')] ?? [];
        if ($ids === []) {
            return '<span class="fa-muted">&ndash;</span>';
        }
        $shown = array_map(fn (string $id): string => $this->title($id), array_slice($ids, 0, $this->limit));
        $more = count($ids) - count($shown);
        return implode(', ', $shown) . ($more > 0 ? ' <span class="fa-muted">' . Str::e(AdminLang::get('relations.and_more', ['count' => (string) $more])) . '</span>' : '');
    }

    private function title(string $id): string
    {
        return isset($this->titles[$id])
            ? '<span dir="auto">' . Str::e($this->titles[$id]) . '</span>'
            : '<span class="fa-muted">' . Str::e(AdminLang::get('relations.missing', ['id' => $id])) . '</span>';
    }
}
