<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;
use Closure;
use FloCMS\Admin\Resource;
use FloCMS\Core\HttpException;

/** A definition for an explicit, bounded selection; policies run per record. */
class BulkAction extends Action
{
    protected bool $deleting = false;
    private ?Closure $bulkHandler = null;
    public function action(Closure $callback): static
    {
        if ($this->deleting) { throw new \LogicException('Bulk Delete retains guarded deletion.'); }
        $this->bulkHandler = $callback; return $this;
    }
    public function allowed(Resource $resource, ?object $record): bool
    {
        return (!$this->deleting || ($record !== null && $resource->canDelete($record))) && parent::allowed($resource, $record);
    }
    /** The built-in Delete selected (DeleteBulkAction). */
    public function isDeleting(): bool { return $this->deleting; }
    public function available(Resource $resource): bool
    {
        try { $this->authorizePermission($resource); return true; }
        catch (HttpException $error) { if ($error->status !== 403) { throw $error; } return false; }
    }
    public function invokeBulk(BulkActionContext $context, array $data): void
    {
        if ($this->deleting) { $context->deleteAll(); return; }
        if ($this->bulkHandler === null) { throw new \LogicException('Bulk action needs an action() callback.'); }
        ($this->bulkHandler)($context, $data);
    }
}
