<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;
use FloCMS\Admin\{Resource, Data\RecordRepository};

/** All selected rows are authorized and locked before the callback runs. */
final class BulkActionContext
{
    public readonly array $records;
    private array $contexts;
    public function __construct(public readonly Resource $resource, RecordRepository $repository, array $records)
    {
        $this->records = array_map(static fn (object $record): object => clone $record, $records);
        $this->contexts = array_map(fn (object $record): ActionContext => new ActionContext($resource, $repository, $record), $records);
    }
    public function updateAll(array $data): void { foreach ($this->contexts as $context) { $context->update($data); } }
    public function deleteAll(): void { foreach ($this->contexts as $context) { $context->delete(); } }
    public function each(\Closure $callback): void { foreach ($this->contexts as $context) { $callback($context); } }
}
