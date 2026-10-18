<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Form\Form, Table\Column, Support\Str, Support\AdminLang};

abstract class RecordsWidget extends Widget
{
    protected string $resource = '';
    protected int $limit = 5;
    protected string $sortColumn = 'id';
    protected string $sortDirection = 'desc';
    final public function resourceClass(): string { return $this->resource; }
    /** @return list<Column> Display columns, rendered with the same escaping/i18n as resource tables. */
    abstract protected function columns(): array;
    /** Optionally narrow the already-scoped query. */
    protected function query(WidgetQuery $query): WidgetQuery { return $query; }
    final public function render(WidgetContext $context, string $id): string
    {
        $resource = $context->resource($this->resource); $columns = $this->columns();
        if ($columns === [] || count($columns) > 8 || !array_is_list($columns)) { throw new \InvalidArgumentException('Use a list of 1–8 widget columns.'); }
        $translatable = $resource->form(new Form())->translatableNames(); $headers = ''; $names = [];
        foreach ($columns as $column) {
            if (!$column instanceof Column || isset($names[$column->getName()])) { throw new \InvalidArgumentException('Use distinct Column objects.'); }
            if ($column instanceof \FloCMS\Admin\Table\Columns\TranslationsColumn) { throw new \InvalidArgumentException('The translations column is not supported in widgets.'); }
            if ($column instanceof \FloCMS\Admin\Table\Columns\RelationColumn && $column->getPivot() !== null) { throw new \InvalidArgumentException('Many-to-many relation columns are not supported in widgets yet.'); }
            $names[$column->getName()] = true; $column->detectTranslatable(in_array($column->getName(), $translatable, true));
            $headers .= '<th scope="col">' . Str::e($column->getLabel()) . '</th>';
        }
        $records = $this->query($context->query($this->resource))->records(array_keys($names), $this->sortColumn, $this->sortDirection, $this->limit);
        $html = '';
        foreach ($columns as $column) { $column->prepareRecords($records); }
        foreach ($records as $record) { $html .= '<tr>'; foreach ($columns as $column) { $html .= '<td class="' . Str::e($column->cellClass()) . '">' . $column->render($record) . '</td>'; } $html .= '</tr>'; }
        return ($html === '' ? '<p class="fa-muted">' . Str::e(AdminLang::get('widgets.empty')) . '</p>' : '<div class="fa-table-wrap" role="region" tabindex="0" aria-labelledby="' . Str::e($id . '-title') . '"><table class="fa-table"><caption class="fa-sr">' . Str::e($this->title()) . '</caption><thead><tr>' . $headers . '</tr></thead><tbody>' . $html . '</tbody></table></div>')
            . '<a class="fa-widget-link" href="' . Str::e($context->resourceUrl($this->resource)) . '">' . Str::e(AdminLang::get('widgets.view_all')) . '</a>';
    }
}
