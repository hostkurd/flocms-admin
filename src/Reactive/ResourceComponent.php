<?php
declare(strict_types=1);
namespace FloCMS\Admin\Reactive;

use FloCMS\Admin\{Admin, Resource, Data\RecordRepository, Form\Form, Form\FormRenderer, Form\FormSubmission, Form\Fields\TextInput, Support\AdminLang, Translation\Languages, Translation\Translations, Table\Table};
use FloCMS\Core\{App, Auth, Session, HttpException, ValidationException};
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Component, Attributes\State, Attributes\Action};

/** One registered adapter for the resource registry; no client-supplied PHP classes. */
final class ResourceComponent extends Component
{
    #[State(writable: false)] public string $resourceSlug = '';
    #[State(writable: false)] public string $instanceKey = 'admin-resource';
    #[State(rules: 'max:100', query: 'q')] public string $search = '';
    #[State(rules: 'integer|min:1|max:100000', query: 'page')] public int $page = 1;
    #[State(rules: 'max:100', query: 'sort')] public string $sort = '';
    #[State(rules: 'in:asc,desc', query: 'dir')] public string $direction = 'asc';
    #[State(rules: 'max:100')] public string $selectedSort = '';
    #[State(rules: 'max:64')] public string $selectedId = '';
    #[State(writable: false)] public string $editingId = '';
    #[State(writable: false)] public string $fingerprint = '';
    #[State(writable: false)] public string $mode = '';
    #[State(writable: false)] public string $operation = '';
    #[State] public string $draft = '';
    #[State(writable: false)] public string $notice = '';

    public function authorize(Request $request): void
    {
        if (!Admin::config()->isInstalled() || !Admin::config()->reactiveEnabled()) { throw new HttpException(404); }
        if (!Session::get('isloggedin') || !Session::get('admin_access')) { throw new HttpException(403); }
        $resource = $this->resource();
        Auth::authorize($resource::permission('view'));
        // Cancellation must stay possible after update/delete permission is revoked.
        if ($this->mode !== '' && $request->input('_action') !== 'cancel') {
            $this->authorizeMode($resource);
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'search' || $property === 'sort' || $property === 'direction') { $this->page = 1; }
        $this->notice = '';
    }

    #[Action] public function paginate(): void {}
    #[Action] public function clear(): void { $this->search = ''; $this->page = 1; }
    #[Action] public function sortBy(): void
    {
        $table = $this->resource()->table(new Table());
        if ($table->sortableColumn($this->selectedSort) === null) { throw new HttpException(400); }
        $this->direction = $this->sort === $this->selectedSort && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $this->selectedSort;
        $this->page = 1;
    }
    #[Action] public function create(): void { $this->open('create'); }
    #[Action] public function edit(): void { $this->open('edit'); }
    #[Action] public function confirmDelete(): void { $this->open('delete'); }

    private function open(string $mode): void
    {
        $this->discard();
        $this->mode = $mode;
        $this->editingId = $mode === 'create' ? '' : $this->selectedId;
        $resource = $this->resource();
        $this->authorizeMode($resource);
        $record = $mode === 'create' ? null : $this->record();
        $this->fingerprint = $record === null ? '' : RecordRepository::fingerprint($record);
        $this->notice = '';
        $state = [];
        foreach (($mode === 'delete' ? [] : $resource->form(new Form())->writableFields()) as $name => $field) {
            $state[$name] = $field->stateFromRecord($record, Languages::default());
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > 7000) {
            $this->discard();
            throw new ValidationException(['_form' => AdminLang::get('reactive.large_form')]);
        }
        $this->draft = $mode === 'delete' ? '' : $encoded;
        $this->operation = bin2hex(random_bytes(16));
        $operations = $this->operations();
        // Keep a bounded set of independent tab/dialog operations for 30 minutes.
        $operations[$this->operation] = time();
        Session::set('_flo_admin_operations', array_slice($operations, -32, null, true));
    }

    #[Action] public function save(): void
    {
        if (!in_array($this->mode, ['create', 'edit'], true)) { throw new HttpException(400); }
        $this->validOperation();
        $resource = $this->resource();
        $repository = $this->repository();
        $repository->transaction(function () use ($resource, $repository): void {
            $this->authorizeMode($resource);
            $record = $this->mode === 'create' ? null : $this->unchangedRecord();
            $input = json_decode($this->draft, true);
            if (!is_array($input) || array_is_list($input)) { throw new HttpException(400); }
            $form = $resource->form(new Form());
            $submission = new FormSubmission($resource, $repository);
            $state = $submission->state($form, $input, $record);
            $errors = $submission->errors($form, $state, $record);
            // Passwords never survive in response HTML or signed snapshots.
            $safe = $state;
            foreach ($form->fields() as $name => $field) {
                if (!$field->isWritable()) { unset($safe[$name]); }
                if ($field instanceof TextInput && $field->getType() === 'password') { $safe[$name] = ''; }
            }
            $this->draft = json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($this->draft) > 7000) { $this->draft = '{}'; throw new ValidationException(['_form' => AdminLang::get('reactive.large_form')]); }
            if ($errors !== []) { throw new ValidationException($errors); }
            $submission->save($form, $state, $record, true);
        });
        $this->notice = AdminLang::get($this->mode === 'create' ? 'flash.created' : 'flash.updated', ['label' => $resource::getLabel()]);
        $this->discard();
    }

    #[Action] public function delete(): void
    {
        if ($this->mode !== 'delete') { throw new HttpException(400); }
        $this->validOperation();
        $resource = $this->resource();
        $this->repository()->transaction(function () use ($resource): void {
            $this->authorizeMode($resource);
            $this->repository()->deleteUnchanged($this->editingId, $this->unchangedRecord());
        });
        $this->notice = AdminLang::get('flash.deleted', ['label' => $resource::getLabel()]);
        $this->discard();
    }

    #[Action] public function cancel(): void { $this->discard(); }

    private function discard(): void
    {
        $operations = $this->operations();
        unset($operations[$this->operation]);
        Session::set('_flo_admin_operations', $operations);
        $this->mode = $this->editingId = $this->fingerprint = $this->draft = $this->operation = '';
    }

    private function operations(): array
    {
        return array_filter((array) Session::get('_flo_admin_operations', []), static fn ($issued): bool => is_int($issued) && $issued > time() - 1800);
    }

    private function validOperation(): void
    {
        if (!isset($this->operations()[$this->operation])) { throw new HttpException(409); }
    }

    private function authorizeMode(Resource $resource): void
    {
        $ability = match ($this->mode) { 'create' => 'create', 'edit' => 'update', 'delete' => 'delete', default => throw new HttpException(400) };
        Auth::authorize($resource::permission($ability));
        if ($ability === 'create') { $allowed = $resource->canCreate(); }
        else {
            $record = $this->repository()->find($this->editingId);
            // Deletion/conflict is a recoverable form error, but existing records
            // always pass the current scoped query and record policy.
            if ($record === null) { return; }
            $allowed = $ability === 'delete' ? $resource->canDelete($record) : $resource->canEdit($record);
        }
        if (!$allowed) { throw new HttpException(403); }
    }

    private function record(): object
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $this->editingId) !== 1) { throw new HttpException(400); }
        return $this->repository()->find($this->editingId) ?? throw new ValidationException(['_form' => AdminLang::get('reactive.missing')]);
    }

    private function unchangedRecord(): object
    {
        $record = $this->record();
        if (!hash_equals($this->fingerprint, RecordRepository::fingerprint($record))) {
            throw new ValidationException(['_form' => AdminLang::get('reactive.conflict')]);
        }
        return $record;
    }

    private function resource(): Resource
    {
        $class = Admin::registry()->find($this->resourceSlug) ?? throw new HttpException(404);
        return new $class();
    }

    private function repository(): RecordRepository
    {
        return new RecordRepository($this->resource(), App::db() ?? throw new \RuntimeException('Database required.'), Translations::store());
    }

    public function render(): string
    {
        $resource = $this->resource();
        $form = $resource->form(new Form());
        $table = $resource->table(new Table());
        foreach ($table->getColumns() as $name => $column) { $column->detectTranslatable(in_array($name, $form->translatableNames(), true)); }
        $result = $this->repository()->paginate($table, $this->search, $this->sort ?: null, $this->direction, $this->page,
            $table->getPerPage() ?? Admin::config()->perPage(), AdminLang::language());
        $last = max(1, (int) $result['pagination']['last_page']);
        if ($this->page > $last) { $this->page = $last; return $this->render(); }
        $record = $this->editingId !== '' ? $this->repository()->find($this->editingId) : null;
        $input = json_decode($this->draft, true);
        $input = is_array($input) ? $input : [];
        $state = [];
        foreach ($form->fields() as $name => $field) { $state[$name] = $field->stateFromInput($input, Languages::all()); }
        foreach ($form->fields() as $name => $field) {
            if (!$field->isWritable()) { $state[$name] = $field->stateFromRecord($record, Languages::default()); }
            if ($field instanceof TextInput && $field->getType() === 'password') { $state[$name] = ''; }
        }
        // Never echo an unvalidated draft supplied to refresh/pagination either.
        $safe = [];
        foreach ($form->writableFields() as $name => $field) {
            $safe[$name] = $state[$name] ?? '';
        }
        if ($this->draft !== '') {
            $this->draft = json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($this->draft) > 7000) { $this->draft = '{}'; $this->errors['_form'] = AdminLang::get('reactive.large_form'); }
        }
        $prefix = 'fa-' . $this->instanceKey;
        return $this->view(dirname(__DIR__, 2) . '/resources/views/reactive-resource.html', [
            't' => \FloCMS\Admin\Http\PageView::translator(), 'resource' => $resource, 'table' => $table, 'result' => $result,
            'search' => $this->search, 'page' => $this->page, 'last' => $last, 'prefix' => $prefix, 'mode' => $this->mode,
            'notice' => $this->notice, 'formFields' => (new FormRenderer($state, $this->errors, Languages::all(), Languages::default(), $this->mode === 'edit', true, $prefix . '-'))->render($form),
            'canCreate' => Auth::can($resource::permission('create')) && $resource->canCreate(),
            'canUpdate' => Auth::can($resource::permission('update')), 'canDelete' => Auth::can($resource::permission('delete')),
        ]);
    }
}
