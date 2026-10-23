<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;

use FloCMS\Admin\{Resource, Data\RecordRepository, Form\FormSubmission, Notifications\Notification, Notifications\Notifications, Support\AdminLang};
use FloCMS\Core\{Auth, HttpException, ValidationException};

/** Shared request execution boundary for Reactive and standard forms. @internal */
final class ActionExecutor
{
    public function __construct(private readonly Resource $resource, private readonly RecordRepository $repository)
    {
        // Core 2.3 declares this exception in Validator.php, including requests
        // that encounter missing records before any form rules are evaluated.
        if (!class_exists(ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
    }

    public function open(Action $action, string $placement, string $id): array
    {
        self::validContext($placement, $id);
        Auth::authorize($this->resource::permission('view'));
        $action->authorizePermission($this->resource);
        $record = $this->record($placement, $id);
        $action->authorizeRequest($this->resource, $record);
        $state = $action->initialState($action->getForm($this->resource), $record);
        $token = OperationStore::issue($this->resource::getSlug(), $placement, $action->getName(), $id,
            $record !== null ? RecordRepository::fingerprint($record) : '');
        return ['operation' => $token, 'state' => $state, 'record' => $record];
    }

    /** $accepted lets the renderer scrub passwords even when validation fails. */
    public function execute(Action $action, string $placement, string $id, string $token, array $input, array &$accepted, ?int $maxDraftBytes = null): void
    {
        self::validContext($placement, $id);
        if ($this->repository->inTransaction()) { throw new \LogicException('Action dispatch owns its transaction; dispatch outside an existing transaction.'); }
        $entry = OperationStore::get($token, $this->resource::getSlug(), $placement, $action->getName(), $id);
        $batch = new \FloCMS\Admin\Uploads\UploadBatch();
        try {
        Notifications::capture(function () use ($action, $placement, $id, $input, $entry, &$accepted, $maxDraftBytes, $batch): void {
            $this->repository->transaction(function () use ($action, $placement, $id, $input, $entry, &$accepted, $maxDraftBytes, $batch): void {
                Auth::authorize($this->resource::permission('view'));
                $action->authorizePermission($this->resource);
                $record = $this->record($placement, $id);
                $action->authorizeRequest($this->resource, $record);
                if ($record !== null) {
                    if (!hash_equals($entry['fingerprint'], RecordRepository::fingerprint($record))) {
                        throw new ValidationException(['_form' => AdminLang::get('reactive.conflict')]);
                    }
                    $this->repository->lockUnchanged($id, $record);
                }
                $form = $action->getForm($this->resource);
                $kind = $action->getKind();
                if ($kind === 'delete') { $batch->deleting($this->resource, $record); $this->repository->deleteUnchanged($id, $record); }
                elseif (in_array($kind, ['create', 'edit'], true)) {
                    $submission = new FormSubmission($this->resource, $this->repository);
                    $input = \FloCMS\Admin\Uploads\Uploads::receive($form, $input, $_FILES['_uploads'] ?? [], $this->resource, $record);
                    $state = $submission->state($form, $input, $record);
                    $accepted = ActionForm::safe($form, $state);
                    $this->limit($accepted, $maxDraftBytes);
                    $errors = $submission->errors($form, $state, $record);
                    if (($flow = \FloCMS\Admin\Form\WizardFlow::for($form, $kind === 'edit')) !== null) { $errors = $flow->finalErrors($errors, $state, $record)[0]; }
                    if ($errors !== []) { throw new ValidationException($errors); }
                    $submission->save($form, $state, $record, true, $batch);
                } else {
                    $state = ActionForm::state($action, $form, $input, $record);
                    $accepted = ActionForm::safe($form, $state);
                    $this->limit($accepted, $maxDraftBytes);
                    $errors = ActionForm::errors($action, $form, $state, $record);
                    if (($flow = \FloCMS\Admin\Form\WizardFlow::for($form, false)) !== null) { $errors = $flow->finalErrors($errors, $state, $record)[0]; }
                    if ($errors !== []) { throw new ValidationException($errors); }
                    $action->invoke(new ActionContext($this->resource, $this->repository, $record, $batch), ActionForm::dehydrate($form, $state));
                    \FloCMS\Admin\Activity\Activity::record('action', $this->resource::getSlug(),
                        $record !== null ? (string) ($record->{$this->resource::getPrimaryKey()} ?? '') : null,
                        $record !== null ? \FloCMS\Admin\Trash\Trash::titleOf($this->resource, $record) : null,
                        meta: ['action' => mb_substr(strip_tags($action->getLabel($this->resource)), 0, 120)] + ($record !== null ? \FloCMS\Admin\Activity\Activity::parents($this->resource, $record) : []), db: $this->repository->connection());
                }
                if ($kind === 'delete') {
                    \FloCMS\Admin\Trash\Trash::notifyDeleted($this->resource, 1, [self::label($this->resource, $record)]);
                } elseif ($kind !== 'custom') {
                    Notification::make()->title(AdminLang::get(match ($kind) {
                        'create' => 'flash.created', 'edit' => 'flash.updated',
                    }, ['label' => $this->resource::getLabel()]))->success()->send();
                }
            });
        });
        } catch (\Throwable $error) { $batch->rollback(); throw $error; }
        $batch->commit();
        OperationStore::consume($token);
    }

    /**
     * A wizard's Back, Next or step button in an action's form (0.39): the same
     * checks as execute() up to the form's state, then the move. Never saves.
     * "save" in the result is true when the submit was the final one and passed:
     * the caller then runs execute().
     *
     * @return array{state: \FloCMS\Admin\Form\WizardState, save: bool, errors: array<string, string>, values: array<string, mixed>}|null null when the form has no wizard
     */
    public function wizard(Action $action, string $placement, string $id, string $token, array $input, bool $refreshing = false): ?array
    {
        self::validContext($placement, $id);
        OperationStore::get($token, $this->resource::getSlug(), $placement, $action->getName(), $id);
        Auth::authorize($this->resource::permission('view'));
        $action->authorizePermission($this->resource);
        $record = $this->record($placement, $id);
        $action->authorizeRequest($this->resource, $record);
        $form = $action->getForm($this->resource);
        $kind = $action->getKind();
        $flow = \FloCMS\Admin\Form\WizardFlow::for($form, $kind === 'edit');
        if ($flow === null || $kind === 'delete') { return null; }
        if (in_array($kind, ['create', 'edit'], true)) {
            $submission = new FormSubmission($this->resource, $this->repository);
            $input = \FloCMS\Admin\Uploads\Uploads::receive($form, $input, $_FILES['_uploads'] ?? [], $this->resource, $record);
            $state = $submission->state($form, $input, $record);
            $validate = static fn (): array => $submission->errors($form, $state, $record);
        } else {
            $state = ActionForm::state($action, $form, $input, $record);
            $validate = static fn (): array => ActionForm::errors($action, $form, $state, $record);
        }
        if ($refreshing) { $form->refreshState($state, $record); }
        return $flow->handle($input, $state, $record, $validate, $refreshing) + ['values' => ActionForm::safe($form, $state)];
    }

    /** A record's title as the list shows it, for notices. */
    public static function label(Resource $resource, ?object $record): string
    {
        if ($record === null) { return ''; }
        $text = trim(\FloCMS\Admin\Translation\Translations::get(\FloCMS\Admin\Trash\Trash::titleOf($resource, $record), \FloCMS\Admin\Support\AdminLang::language()));
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        return $text !== '' ? $text : '#' . (string) ($record->{$resource::getPrimaryKey()} ?? '');
    }

    private function limit(array &$accepted, ?int $maxBytes): void
    {
        if ($maxBytes !== null && strlen(json_encode((object) $accepted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > $maxBytes) {
            $accepted = [];
            throw new ValidationException(['_form' => AdminLang::get('reactive.large_form')]);
        }
    }

    public function record(string $placement, string $id): ?object
    {
        self::validContext($placement, $id);
        return $placement === 'row' ? ($this->repository->find($id) ?? throw new ValidationException(['_form' => AdminLang::get('reactive.missing')])) : null;
    }
    public static function validContext(string $placement, string $id): void
    {
        if (!in_array($placement, ['row', 'table', 'page'], true)
            || ($placement === 'row' ? preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1 : $id !== '')) { throw new HttpException(400); }
    }
}
