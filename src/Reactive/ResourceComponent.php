<?php
declare(strict_types=1);
namespace FloCMS\Admin\Reactive;

use FloCMS\Admin\{Admin, Resource, Data\RecordRepository, Form\Form, Form\FormRenderer, Form\Fields\TextInput, Support\AdminLang, Translation\Languages, Translation\Translations, Table\Table};
use FloCMS\Admin\Actions\{Action as Definition, ActionRegistry, ActionRenderer, ActionExecutor, ActionForm, OperationStore};
use FloCMS\Admin\Notifications\NotificationView;
use FloCMS\Core\{App, Auth, Session, HttpException, ValidationException};
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Component, Attributes\State, Attributes\Action};

/** One registered adapter. Clients select names, never PHP callbacks or classes. */
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
    #[State(rules: 'max:160')] public string $selection = '';
    #[State(writable: false)] public string $activeAction = '';
    #[State(writable: false)] public string $activePlacement = '';
    #[State(writable: false)] public string $editingId = '';
    #[State(writable: false)] public string $fingerprint = '';
    #[State(writable: false)] public string $mode = '';
    #[State(writable: false)] public string $operation = '';
    #[State] public string $draft = '';

    public function authorize(Request $request): void
    {
        if (!Admin::config()->isInstalled() || !Admin::config()->reactiveEnabled()) { throw new HttpException(404); }
        if (!Session::get('isloggedin') || !Session::get('admin_access')) { throw new HttpException(403); }
        Auth::authorize($this->resource()::permission('view'));
        if ($this->mode !== '' && $request->input('_action') !== 'cancel') { $this->authorizeActive(); }
    }
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'sort', 'direction'], true)) { $this->page = 1; }
    }
    #[Action] public function paginate(): void {}
    #[Action] public function clear(): void { $this->search = ''; $this->page = 1; }
    #[Action] public function sortBy(): void
    {
        if ($this->resource()->table(new Table())->sortableColumn($this->selectedSort) === null) { throw new HttpException(400); }
        $this->direction = $this->sort === $this->selectedSort && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $this->selectedSort; $this->page = 1;
    }
    #[Action] public function openAction(): void
    {
        $parts = explode(':', $this->selection);
        if (count($parts) !== 3) { throw new HttpException(400); }
        [$placement, $name, $id] = $parts;
        $this->open($placement, $name, $id);
    }
    // Keep the 0.3 RPC entry points, but resolve them through the same allowlist.
    #[Action] public function create(): void { $this->open('page', 'create', ''); }
    #[Action] public function edit(): void { $this->open('row', 'edit', $this->selectedId); }
    #[Action] public function confirmDelete(): void { $this->open('row', 'delete', $this->selectedId); }

    private function open(string $placement, string $name, string $id): void
    {
        $this->discard();
        $resource = $this->resource();
        $action = (new ActionRegistry($resource))->find($placement, $name);
        $opened = $this->executor()->open($action, $placement, $id);
        $this->activeAction = $name; $this->activePlacement = $placement;
        $this->editingId = $id; $this->operation = $opened['operation'];
        $this->fingerprint = $opened['record'] !== null ? RecordRepository::fingerprint($opened['record']) : '';
        $this->mode = $action->getKind();
        $this->draft = json_encode((object) ActionForm::safe($action->getForm($resource), $opened['state']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($this->draft) > 7000) {
            $this->discard(); throw new ValidationException(['_form' => AdminLang::get('reactive.large_form')]);
        }
        if (!$action->needsDialog($resource)) { $this->execute(); }
    }
    #[Action] public function execute(): void
    {
        if ($this->mode === '') { throw new HttpException(400); }
        $action = $this->active();
        $decoded = json_decode($this->draft);
        if (!$decoded instanceof \stdClass) { throw new HttpException(400); }
        $accepted = ActionForm::safe($action->getForm($this->resource()), json_decode($this->draft, true, 512, JSON_THROW_ON_ERROR));
        try {
            $this->executor()->execute($action, $this->activePlacement, $this->editingId, $this->operation,
                json_decode($this->draft, true, 512, JSON_THROW_ON_ERROR), $accepted, 7000);
        } catch (ValidationException $error) {
            $this->draft = json_encode((object) $accepted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            throw $error;
        }
        $this->discard();
    }
    #[Action] public function save(): void
    {
        if (!in_array($this->mode, ['create', 'edit'], true)) { throw new HttpException(400); }
        $this->execute();
    }
    #[Action] public function delete(): void
    {
        if ($this->mode !== 'delete') { throw new HttpException(400); }
        $this->execute();
    }
    #[Action] public function cancel(): void { $this->discard(); }
    private function discard(): void
    {
        OperationStore::consume($this->operation);
        $this->mode = $this->activeAction = $this->activePlacement = $this->editingId = $this->fingerprint = $this->draft = $this->operation = '';
    }
    private function active(): Definition
    {
        return (new ActionRegistry($this->resource()))->find($this->activePlacement, $this->activeAction);
    }
    private function authorizeActive(): void
    {
        $resource = $this->resource(); $action = $this->active();
        OperationStore::get($this->operation, $this->resourceSlug, $this->activePlacement, $this->activeAction, $this->editingId);
        $action->authorizePermission($resource);
        $record = $this->editingId !== '' ? $this->repository()->find($this->editingId) : null;
        if ($this->activePlacement === 'row' && $record === null) { return; }
        $action->authorizeRequest($resource, $record);
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
    private function executor(): ActionExecutor { return new ActionExecutor($this->resource(), $this->repository()); }

    public function render(): string
    {
        $resource = $this->resource(); $table = $resource->table(new Table());
        $resourceForm = $resource->form(new Form());
        foreach ($table->getColumns() as $name => $column) { $column->detectTranslatable(in_array($name, $resourceForm->translatableNames(), true)); }
        $result = $this->repository()->paginate($table, $this->search, $this->sort ?: null, $this->direction, $this->page,
            $table->getPerPage() ?? Admin::config()->perPage(), AdminLang::language());
        $last = max(1, (int) $result['pagination']['last_page']);
        if ($this->page > $last) { $this->page = $last; return $this->render(); }
        $registry = new ActionRegistry($resource, $table);
        $action = $this->mode !== '' ? $registry->find($this->activePlacement, $this->activeAction) : null;
        $form = $action !== null ? $action->getForm($resource) : new Form();
        $record = $this->editingId !== '' ? $this->repository()->find($this->editingId) : null;
        $input = json_decode($this->draft, true);
        $safe = ActionForm::safe($form, is_array($input) ? $input : []);
        $state = $safe;
        foreach ($form->fields() as $name => $field) {
            if (!$field->isWritable()) { $state[$name] = $field->stateFromRecord($record, Languages::default()); }
        }
        if ($this->draft !== '') {
            $this->draft = json_encode((object) $safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($this->draft) > 7000) { $this->draft = '{}'; $this->errors['_form'] = AdminLang::get('reactive.large_form'); }
        }
        $prefix = 'fa-' . $this->instanceKey;
        $renderer = new ActionRenderer($resource, $registry);
        return $this->view(dirname(__DIR__, 2) . '/resources/views/reactive-resource.html', [
            't' => \FloCMS\Admin\Http\PageView::translator(), 'resource' => $resource, 'table' => $table, 'result' => $result,
            'search' => $this->search, 'page' => $this->page, 'last' => $last, 'prefix' => $prefix, 'mode' => $this->mode,
            'pageActions' => $renderer->render('page', prefix: $prefix), 'tableActions' => $renderer->render('table', prefix: $prefix),
            'rowActions' => static fn (object $row): string => $renderer->render('row', $row, prefix: $prefix),
            'actionTitle' => $action?->getTitle($resource) ?? '', 'actionDescription' => $action?->getDescription() ?? '',
            'submitLabel' => $action?->getSubmitLabel($resource) ?? '', 'submitColor' => $action?->getColor() ?? 'default',
            'submitAction' => match ($this->mode) { 'create', 'edit' => 'save', 'delete' => 'delete', default => 'execute' },
            'formFields' => (new FormRenderer($state, $this->errors, Languages::all(), Languages::default(), $this->mode === 'edit', true, $prefix . '-'))->render($form),
            'notifications' => NotificationView::pending($this->instanceKey),
        ]);
    }
}
