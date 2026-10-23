<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Cells;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;

/**
 * Where a list's editable cells post to and whether this person may edit a
 * row (0.38). Built by the list pages; columns only render with it.
 *
 * @internal
 */
final class CellContext
{
    /** @var array<string, bool> record id => may edit */
    private array $allowed = [];

    /**
     * @param string $back the list to return to after a save without JavaScript
     * @param string $idPrefix makes control ids unique on a page with several lists
     */
    public function __construct(
        private readonly Resource $resource,
        private readonly string $back,
        private readonly string $idPrefix = 'fa-cell',
        private readonly bool $readOnly = false,
    ) {
    }

    public function resource(): Resource
    {
        return $this->resource;
    }

    public function back(): string
    {
        return $this->back;
    }

    public function url(string $id): string
    {
        return Admin::url($this->resource::getSlug() . '/cell/' . rawurlencode($id), ['parent' => $this->resource->parentScope()?->token()]);
    }

    public function idPrefix(): string
    {
        return $this->idPrefix;
    }

    public function controlId(string $id, string $column): string
    {
        return $this->idPrefix . '-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $id) . '-' . $column;
    }

    /** The same check as the edit page: the Edit action's permission, canEdit(), visible() and authorize(). */
    public function mayEdit(object $record): bool
    {
        if ($this->readOnly) { return false; }
        $id = (string) ($record->{$this->resource::getPrimaryKey()} ?? '');
        if ($id === '') { return false; }
        return $this->allowed[$id] ??= $this->resource->mayEdit($record);
    }

    /** The record's title as the list shows it ("Villa in Kifisia", or "#5"). */
    public function title(object $record): string
    {
        return \FloCMS\Admin\Actions\ActionExecutor::label($this->resource, $record);
    }
}
