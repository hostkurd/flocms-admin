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
        Notifications::capture(function () use ($action, $placement, $id, $input, $entry, &$accepted, $maxDraftBytes): void {
            $this->repository->transaction(function () use ($action, $placement, $id, $input, $entry, &$accepted, $maxDraftBytes): void {
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
                if ($kind === 'delete') { $this->repository->deleteUnchanged($id, $record); }
                elseif (in_array($kind, ['create', 'edit'], true)) {
                    $submission = new FormSubmission($this->resource, $this->repository);
                    $state = $submission->state($form, $input, $record);
                    $accepted = ActionForm::safe($form, $state);
                    $this->limit($accepted, $maxDraftBytes);
                    $errors = $submission->errors($form, $state, $record);
                    if ($errors !== []) { throw new ValidationException($errors); }
                    $submission->save($form, $state, $record, true);
                } else {
                    $state = ActionForm::state($action, $form, $input, $record);
                    $accepted = ActionForm::safe($form, $state);
                    $this->limit($accepted, $maxDraftBytes);
                    $errors = ActionForm::errors($action, $form, $state, $record);
                    if ($errors !== []) { throw new ValidationException($errors); }
                    $action->invoke(new ActionContext($this->resource, $this->repository, $record), ActionForm::dehydrate($form, $state));
                }
                if ($kind !== 'custom') {
                    Notification::make()->title(AdminLang::get(match ($kind) {
                        'create' => 'flash.created', 'edit' => 'flash.updated', 'delete' => 'flash.deleted',
                    }, ['label' => $this->resource::getLabel()]))->success()->send();
                }
            });
        });
        OperationStore::consume($token);
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
