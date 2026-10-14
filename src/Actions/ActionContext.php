<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;

use FloCMS\Admin\{Resource, Data\RecordRepository};

/** Transaction-scoped mutation helpers; callbacks may also use the same Core connection. */
final class ActionContext
{
    public readonly ?object $record;
    private ?object $original;
    public function __construct(public readonly Resource $resource, private readonly RecordRepository $repository, ?object $record)
    {
        $this->record = $record !== null ? clone $record : null;
        $this->original = $record !== null ? clone $record : null;
    }
    public function update(array $data): void
    {
        $record = $this->original ?? throw new \LogicException('This action has no active record.');
        $pk = $this->resource::getPrimaryKey();
        unset($data[$pk], $data['created_at'], $data['updated_at']);
        if ($this->resource::usesTimestamps()) { $data['updated_at'] = date('Y-m-d H:i:s'); }
        $this->repository->updateUnchanged((string) $record->{$pk}, $data, $record);
        foreach ($data as $column => $value) { $record->{$column} = $value; }
    }
    public function delete(): void
    {
        $record = $this->original ?? throw new \LogicException('This action has no active record.');
        $this->repository->deleteUnchanged((string) $record->{$this->resource::getPrimaryKey()}, $record);
        $this->original = null;
    }
}
