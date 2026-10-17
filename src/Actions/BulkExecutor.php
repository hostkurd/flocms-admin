<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;
use FloCMS\Admin\{Resource, Data\RecordRepository, Notifications\Notification, Notifications\Notifications, Support\AdminLang};
use FloCMS\Core\{Auth, HttpException, ValidationException};

/** Membership/fingerprints are held in the server session, never trusted from a draft. */
final class BulkExecutor
{
    public const LIMIT = 50;
    public function __construct(private readonly Resource $resource, private readonly RecordRepository $repository)
    {
        if (!class_exists(ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
    }
    public static function ids(mixed $input, bool $allowEmpty = false): array
    {
        if (is_string($input)) {
            if (strlen($input) > 3500) { throw new HttpException(400); }
            if (!is_array(json_decode($input))) { throw new HttpException(400); }
            $input = json_decode($input, true);
        }
        if (!is_array($input) || !array_is_list($input) || count($input) > self::LIMIT || (!$allowEmpty && $input === [])) { throw new HttpException(400); }
        $ids = [];
        foreach ($input as $id) {
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1 || in_array($id, $ids, true)) { throw new HttpException(400); }
            $ids[] = $id;
        }
        sort($ids, SORT_STRING); return $ids;
    }
    /** The confirmation text of a bulk action: Delete selected says where the records go. */
    public static function confirmText(Resource $resource, ?Action $action, int $count): string
    {
        if ($action instanceof BulkAction && $action->isDeleting() && \FloCMS\Admin\Trash\Trash::enabledFor($resource)) {
            $days = \FloCMS\Admin\Trash\Trash::days();
            return AdminLang::get($days > 0 ? 'bulk.confirm_trash' : 'bulk.confirm_trash_kept', ['count' => $count, 'days' => $days]);
        }
        if ($action instanceof BulkAction && $action->isDeleting()) { return AdminLang::get('bulk.confirm_delete', ['count' => $count]); }
        return AdminLang::get('bulk.confirm', ['count' => $count]);
    }

    private static function digest(array $ids): string { return hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR)); }
    public function open(BulkAction $action, array $ids): array
    {
        $ids = self::ids($ids);
        $records = $this->records($action, $ids);
        $fingerprints = [];
        foreach ($records as $record) { $fingerprints[(string) $record->{$this->resource::getPrimaryKey()}] = RecordRepository::fingerprint($record); }
        $token = OperationStore::issue($this->resource::getSlug(), 'bulk', $action->getName(), self::digest($ids), json_encode((object) $fingerprints, JSON_THROW_ON_ERROR));
        return ['ids' => $ids, 'operation' => $token, 'state' => $action->initialState($action->getForm($this->resource), null)];
    }
    public function authorize(BulkAction $action, array $ids, string $token, bool $checkPermission = true): void
    {
        OperationStore::get($token, $this->resource::getSlug(), 'bulk', $action->getName(), self::digest(self::ids($ids)));
        Auth::authorize($this->resource::permission('view'));
        if ($checkPermission) { $action->authorizePermission($this->resource); }
    }
    /** Dependency refreshes must enforce every current record policy too. */
    public function preview(BulkAction $action, array $ids, string $token): void
    {
        $this->authorize($action, $ids, $token);
        $this->records($action, self::ids($ids));
    }
    private function records(BulkAction $action, array $ids): array
    {
        Auth::authorize($this->resource::permission('view'));
        $action->authorizePermission($this->resource);
        $records = [];
        foreach ($ids as $id) {
            $record = $this->repository->find($id) ?? throw new ValidationException(['_form' => AdminLang::get('bulk.missing')]);
            if ((string) $record->{$this->resource::getPrimaryKey()} !== $id) { throw new HttpException(400, 'Use canonical record IDs.'); }
            $action->authorizeRequest($this->resource, $record);
            $records[] = $record;
        }
        return $records;
    }
    public function execute(BulkAction $action, array $ids, string $token, array $input, array &$accepted, ?int $maxBytes = null): void
    {
        $ids = self::ids($ids);
        if ($this->repository->inTransaction()) { throw new \LogicException('Bulk dispatch owns its transaction.'); }
        $entry = OperationStore::get($token, $this->resource::getSlug(), 'bulk', $action->getName(), self::digest($ids));
        $batch = new \FloCMS\Admin\Uploads\UploadBatch();
        try {
        Notifications::capture(function () use ($action, $ids, $input, $entry, &$accepted, $maxBytes, $batch): void {
            $this->repository->transaction(function () use ($action, $ids, $input, $entry, &$accepted, $maxBytes, $batch): void {
                $records = $this->records($action, $ids);
                $fingerprints = json_decode($entry['fingerprint'], true, 512, JSON_THROW_ON_ERROR);
                // Lock in deterministic ID order; reject every stale row before writing any.
                foreach ($records as $record) {
                    $id = (string) $record->{$this->resource::getPrimaryKey()};
                    if (!isset($fingerprints[$id]) || !hash_equals($fingerprints[$id], RecordRepository::fingerprint($record))) { throw new ValidationException(['_form' => AdminLang::get('bulk.conflict')]); }
                    $this->repository->lockUnchanged($id, $record);
                }
                $form = $action->getForm($this->resource);
                $state = ActionForm::state($action, $form, $input, null);
                $accepted = ActionForm::safe($form, $state);
                if ($maxBytes !== null && strlen(json_encode((object) $accepted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > $maxBytes) { $accepted = []; throw new ValidationException(['_form' => AdminLang::get('reactive.large_form')]); }
                $errors = ActionForm::errors($action, $form, $state, null);
                if ($errors !== []) { throw new ValidationException($errors); }
                $action->invokeBulk(new BulkActionContext($this->resource, $this->repository, $records, $batch), ActionForm::dehydrate($form, $state));
                if ($action->isDeleting()) {
                    \FloCMS\Admin\Trash\Trash::notifyDeleted($this->resource, count($records), array_map(fn (object $record): string => ActionExecutor::label($this->resource, $record), $records));
                } else {
                    \FloCMS\Admin\Activity\Activity::record('bulk_action', $this->resource::getSlug(), meta: [
                        'action' => mb_substr(strip_tags($action->getLabel($this->resource)), 0, 120), 'count' => count($records),
                        'titles' => array_slice(array_map(fn (object $record): string => \FloCMS\Admin\Trash\Trash::titleOf($this->resource, $record), $records), 0, 20),
                    ], db: $this->repository->connection());
                    Notification::make()->title(AdminLang::get('bulk.completed', ['count' => count($ids)]))->success()->send();
                }
            });
        });
        } catch (\Throwable $error) { $batch->rollback(); throw $error; }
        $batch->commit();
        OperationStore::consume($token);
    }
}
