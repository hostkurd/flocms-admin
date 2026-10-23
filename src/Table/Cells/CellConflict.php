<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Cells;

/**
 * Somebody changed the cell's value (or deleted the record) since the list
 * was drawn (0.38). Carries the record as it is now, or null when it is gone.
 *
 * @internal
 */
final class CellConflict extends \RuntimeException
{
    public function __construct(public readonly ?object $current)
    {
        parent::__construct('The value changed since the list was drawn.');
    }
}
