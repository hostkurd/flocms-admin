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
    private bool $skipping = false;
    private ?Closure $bulkLink = null;
    /**
     * Records the action doesn't apply to are left out instead of refusing the
     * whole selection (0.28); the confirmation says how many and why.
     */
    public function skipUnavailable(bool $skip = true): static { $this->skipping = $skip; return $this; }
    public function skipsUnavailable(): bool { return $this->skipping; }
    /** Open a page with the chosen ids instead of a dialog (0.28): fn (list<string> $ids): string. */
    public function bulkUrl(Closure $url): static { $this->bulkLink = $url; return $this; }
    public function getBulkUrl(array $ids): ?string
    {
        if ($this->bulkLink === null) { return null; }
        $url = ($this->bulkLink)($ids);
        return is_string($url) && $url !== '' ? $url : null;
    }
    public function opensPage(): bool { return $this->bulkLink !== null; }
    private ?string $completed = null;
    private ?string $completedOne = null;
    /** The message after it ran (0.28), with :count: 'Suspended :count users.', and $one for a single record. */
    public function completedTitle(string $title, ?string $one = null): static { $this->completed = $title; $this->completedOne = $one; return $this; }
    public function getCompletedTitle(int $count): string
    {
        if ($this->completed === null) { return \FloCMS\Admin\Support\AdminLang::get('bulk.completed', ['count' => $count]); }
        $title = $count === 1 && $this->completedOne !== null ? $this->completedOne : $this->completed;
        return str_replace(':count', (string) $count, \FloCMS\Admin\Support\AdminLang::label($title));
    }
    public function action(Closure $callback): static
    {
        if ($this->deleting) { throw new \LogicException('Bulk Delete retains guarded deletion.'); }
        $this->bulkHandler = $callback; return $this;
    }
    public function allowed(Resource $resource, ?object $record): bool
    {
        return (!$this->deleting || ($record !== null && $resource->canDelete($record))) && parent::allowed($resource, $record);
    }
    /** Why a record is left out of a skipping bulk action (its disabled() reason, else a general one). */
    public function skipReason(Resource $resource, object $record): string
    {
        if ((!$this->deleting || $resource->canDelete($record)) && ($reason = $this->reasonDisabled($resource, $record)) !== null) { return $reason; }
        return \FloCMS\Admin\Support\AdminLang::get('bulk.skip_not_allowed');
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
