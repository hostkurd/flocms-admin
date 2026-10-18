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
    #[State(rules: 'max:2000', query: 'filters')] public string $filters = '{}';
    #[State(rules: 'max:3500')] public string $selectedIds = '[]';
    #[State(rules: 'max:2000')] public string $columns = '[]';
    #[State(rules: 'max:64')] public string $selectedBulk = '';
    #[State(writable: false)] public string $bulkIds = '[]';
    #[State(rules: 'max:100')] public string $selectedSort = '';
    #[State(rules: 'max:64')] public string $selectedId = '';
    #[State(rules: 'max:160')] public string $selection = '';
    #[State(writable: false)] public string $activeAction = '';
    #[State(writable: false)] public string $activePlacement = '';
    #[State(writable: false)] public string $editingId = '';
    #[State(writable: false)] public string $fingerprint = '';
    #[State(writable: false)] public string $mode = '';
    #[State(writable: false)] public string $operation = '';
    #[State(maxBytes: 65536)] public string $draft = '';
    /** Page-level translation (0.23): the language the dialog's switch shows. */
    #[State(rules: 'max:16')] public string $editing = '';
    /** A relation manager (0.22): the parent's slug and id and the manager's name, signed. */
    #[State(writable: false)] public string $parentSlug = '';
    #[State(writable: false)] public string $parentId = '';
    #[State(writable: false)] public string $relation = '';
    private ?Resource $scoped = null;

    public function authorize(Request $request): void
    {
        if (!Admin::config()->isInstalled() || !Admin::config()->reactiveEnabled()) { throw new HttpException(404); }
        if (!Session::get('isloggedin') || !Session::get('admin_access')) { throw new HttpException(403); }
        Auth::authorize($this->resource()::permission('view'));
        if ($this->mode !== '' && $request->input('_action') !== 'cancel') { $this->authorizeActive(); }
    }
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'sort', 'direction', 'filters'], true)) { $this->page = 1; $this->selectedIds = '[]'; }
        if ($property === 'selectedIds') { $this->selectedIds = json_encode(\FloCMS\Admin\Actions\BulkExecutor::ids($this->selectedIds, true), JSON_THROW_ON_ERROR); }
    }
    #[Action] public function paginate(): void {}
    #[Action] public function applyFilters(): void
    {
        $this->page = 1;
        $filters = new \FloCMS\Admin\Table\FilterSet($this->table(), $this->filters);
        if ($filters->errors !== []) {
            if (!class_exists(ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
            throw new ValidationException(['_form' => AdminLang::get('filters.not_applied')]);
        }
    }
    /** The Columns button: $columns holds the names of the toggleable columns to show (JSON). */
    #[Action] public function applyColumns(): void
    {
        $chosen = json_decode($this->columns, true);
        if (!is_array($chosen) || !array_is_list($chosen)) { throw new HttpException(400); }
        $this->visibility()->save(array_values(array_filter($chosen, 'is_string')));
        $this->columns = '[]';
    }
    #[Action] public function resetColumns(): void { $this->visibility()->reset(); $this->columns = '[]'; }
    private function visibility(): \FloCMS\Admin\Table\ColumnVisibility
    {
        return new \FloCMS\Admin\Table\ColumnVisibility($this->table(), $this->columnsKey());
    }
    /** A relation manager remembers its own column choices (0.22). */
    private function columnsKey(): string
    {
        return $this->resource()->parentScope()?->columnsKey() ?? $this->resourceSlug;
    }
    #[Action] public function clearFilters(): void { $this->filters = '{}'; $this->page = 1; $this->selectedIds = '[]'; }
    #[Action] public function clearSelection(): void { $this->selectedIds = '[]'; }
    #[Action] public function clear(): void { $this->search = ''; $this->page = 1; $this->selectedIds = '[]'; }
    #[Action] public function sortBy(): void
    {
        if ($this->table()->sortableColumn($this->selectedSort) === null) { throw new HttpException(400); }
        $this->direction = $this->sort === $this->selectedSort && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $this->selectedSort; $this->page = 1;
        $this->selectedIds = '[]';
    }
    #[Action] public function openAction(): void
    {
        $parts = explode(':', $this->selection);
        if (count($parts) !== 3) { throw new HttpException(400); }
        [$placement, $name, $id] = $parts;
        $this->open($placement, $name, $id);
    }
    #[Action] public function openBulk(): void
    {
        $this->discard();
        $action = (new ActionRegistry($this->resource()))->find('bulk', $this->selectedBulk);
        $opened = $this->bulkExecutor()->open($action, \FloCMS\Admin\Actions\BulkExecutor::ids($this->selectedIds));
        $this->activeAction = $action->getName(); $this->activePlacement = 'bulk'; $this->mode = 'bulk';
        $this->bulkIds = json_encode($opened['ids'], JSON_THROW_ON_ERROR); $this->operation = $opened['operation'];
        $this->draft = json_encode((object) ActionForm::safe($action->getForm($this->resource()), $opened['state']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($this->draft) > 64000) { $this->discard(); throw new ValidationException(['_form' => AdminLang::get('reactive.large_form')]); }
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
        // An existing record opens in the language last saved in; a new one in the default language.
        $this->editing = \FloCMS\Admin\Translation\EditingLanguage::resolve(null, $opened['record'] !== null);
        $this->draft = json_encode((object) ActionForm::safe($action->getForm($resource), $opened['state']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($this->draft) > 64000) {
            $this->discard(); throw new ValidationException(['_form' => AdminLang::get('reactive.large_form')]);
        }
        if (!$action->needsDialog($resource)) { $this->execute(); }
    }
    /** Refresh declared dependencies without validating/saving the resource. */
    #[Action] public function refreshForm(): void
    {
        if ($this->mode === '') { throw new HttpException(400); }
        $input = json_decode($this->draft, true);
        if (!is_array($input) || !json_decode($this->draft) instanceof \stdClass) { throw new HttpException(400); }
        $action = $this->active(); $resource = $this->resource(); $form = $action->getForm($resource);
        if ($this->mode === 'bulk') { $this->bulkExecutor()->preview($action, \FloCMS\Admin\Actions\BulkExecutor::ids($this->bulkIds), $this->operation); }
        $record = $this->editingId !== '' ? $this->repository()->find($this->editingId) : null;
        if ($this->activePlacement === 'row' && $record === null) { throw new ValidationException(['_form' => AdminLang::get('reactive.missing')]); }
        $state = in_array($action->getKind(), ['create', 'edit'], true)
            ? (new \FloCMS\Admin\Form\FormSubmission($resource, $this->repository()))->state($form, $input, $record)
            : ActionForm::state($action, $form, $input, $record);
        $form->refreshState($state, $record);
        $this->draft = json_encode((object) ActionForm::safe($form, $state), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($this->draft) > 64000) { $this->draft = '{}'; throw new ValidationException(['_form' => AdminLang::get('reactive.large_form')]); }
    }
    #[Action] public function execute(): void
    {
        if ($this->mode === '') { throw new HttpException(400); }
        $action = $this->active();
        $decoded = json_decode($this->draft);
        if (!$decoded instanceof \stdClass) { throw new HttpException(400); }
        $accepted = ActionForm::safe($action->getForm($this->resource()), json_decode($this->draft, true, 512, JSON_THROW_ON_ERROR));
        try {
            if ($this->mode === 'bulk') {
                $this->bulkExecutor()->execute($action, \FloCMS\Admin\Actions\BulkExecutor::ids($this->bulkIds), $this->operation, json_decode($this->draft, true, 512, JSON_THROW_ON_ERROR), $accepted, 64000);
                $this->selectedIds = '[]';
            } else {
                $this->executor()->execute($action, $this->activePlacement, $this->editingId, $this->operation,
                    json_decode($this->draft, true, 512, JSON_THROW_ON_ERROR), $accepted, 64000);
            }
        } catch (ValidationException $error) {
            $this->draft = json_encode((object) $accepted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            // The dialog opens on the language of the first error.
            if ($this->switches($action)) { $this->editing = \FloCMS\Admin\Translation\EditingLanguage::forErrors($error->getErrors(), $this->editingLanguage()); }
            throw $error;
        }
        if ($this->switches($action) && in_array($this->mode, ['create', 'edit'], true)) { \FloCMS\Admin\Translation\EditingLanguage::remember($this->editingLanguage()); }
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
        $this->bulkIds = '[]';
        $this->mode = $this->activeAction = $this->activePlacement = $this->editingId = $this->fingerprint = $this->draft = $this->operation = $this->editing = '';
    }
    /** Whether the open dialog shows the language switch: a page-mode resource and a form with translatable fields (not bulk forms). */
    private function switches(?Definition $action): bool
    {
        return $action !== null && $this->mode !== 'bulk' && $this->mode !== 'delete' && $this->resource()::usesLanguageSwitch()
            && $action->getForm($this->resource())->translatableNames() !== [];
    }
    private function editingLanguage(): string
    {
        return \FloCMS\Admin\Translation\EditingLanguage::valid($this->editing) ?? Languages::default();
    }
    private function active(): Definition
    {
        return (new ActionRegistry($this->resource()))->find($this->activePlacement, $this->activeAction);
    }
    private function authorizeActive(): void
    {
        $resource = $this->resource(); $action = $this->active();
        if ($this->mode === 'bulk') { $this->bulkExecutor()->authorize($action, \FloCMS\Admin\Actions\BulkExecutor::ids($this->bulkIds), $this->operation); return; }
        OperationStore::get($this->operation, $this->resourceSlug, $this->activePlacement, $this->activeAction, $this->editingId);
        $action->authorizePermission($resource);
        $record = $this->editingId !== '' ? $this->repository()->find($this->editingId) : null;
        if ($this->activePlacement === 'row' && $record === null) { return; }
        $action->authorizeRequest($resource, $record);
    }
    private function resource(): Resource
    {
        if ($this->scoped !== null) { return $this->scoped; }
        $class = Admin::registry()->find($this->resourceSlug) ?? throw new HttpException(404);
        $resource = new $class();
        if ($this->parentSlug === '') { return $resource; }
        // Checked again on every request: the parent exists and the user may still edit it.
        return $this->scoped = $resource->withinParent(\FloCMS\Admin\Relations\ParentScope::resolve($this->parentSlug, $this->parentId, $this->relation, $this->resourceSlug));
    }
    private function table(): Table
    {
        $resource = $this->resource(); $table = $resource->table(new Table());
        $table = ($scope = $resource->parentScope()) !== null ? $scope->adjustTable($table) : $table;
        return $table->prepare($resource->form(new Form()));
    }
    private function repository(): RecordRepository
    {
        return new RecordRepository($this->resource(), App::db() ?? throw new \RuntimeException('Database required.'), Translations::store());
    }
    private function executor(): ActionExecutor { return new ActionExecutor($this->resource(), $this->repository()); }
    private function bulkExecutor(): \FloCMS\Admin\Actions\BulkExecutor { return new \FloCMS\Admin\Actions\BulkExecutor($this->resource(), $this->repository()); }

    /** This list with its search, filters, sort and page, for page-mode forms to return to. */
    private function listUrl(string $slug): string
    {
        $key = $this->instanceKey;
        return Admin::url($slug, [
            $key . '_q' => $this->search, $key . '_page' => $this->page > 1 ? $this->page : null,
            $key . '_sort' => $this->sort, $key . '_dir' => $this->sort !== '' ? $this->direction : null,
            $key . '_filters' => $this->filters !== '{}' ? $this->filters : null,
        ]);
    }

    public function render(): string
    {
        $resource = $this->resource(); $table = $this->table();
        $scope = $resource->parentScope();
        $filters = new \FloCMS\Admin\Table\FilterSet($table, $this->filters);
        foreach ($table->getFilters() as $filter) {
            if ($filter instanceof \FloCMS\Admin\Table\Filters\RelationFilter) { $filter->lookupFrom(Admin::url($resource::getSlug() . '/lookup')); }
        }
        $visibility = new \FloCMS\Admin\Table\ColumnVisibility($table, $this->columnsKey());
        // Sorting by a column the user hid falls back to the default sort.
        if ($this->sort !== '' && $visibility->isHidden($this->sort)) { $this->sort = ''; $this->direction = 'asc'; }
        $result = $this->repository()->paginate($table, $this->search, $this->sort ?: null, $this->direction, $this->page,
            $table->getPerPage() ?? Admin::config()->perPage(), AdminLang::language(), $filters);
        $last = max(1, (int) $result['pagination']['last_page']);
        if ($this->page > $last) { $this->page = $last; return $this->render(); }
        foreach ($visibility->visibleColumns() as $column) { $column->prepareRecords($result['data']); }
        $registry = new ActionRegistry($resource, $resource->table(new Table()));
        $selected = \FloCMS\Admin\Actions\BulkExecutor::ids($this->selectedIds, true);
        $bulkActions = array_filter($registry->all('bulk'), fn ($action): bool => $action->available($resource));
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
            if (strlen($this->draft) > 64000) { $this->draft = '{}'; $this->errors['_form'] = AdminLang::get('reactive.large_form'); }
        }
        $prefix = 'fa-' . $this->instanceKey;
        $switch = $this->switches($action);
        $editing = $this->editingLanguage();
        $formRenderer = new FormRenderer($state, $this->errors, Languages::all(), Languages::default(), $this->mode === 'edit', false, $prefix . '-', true, \FloCMS\Admin\Uploads\Uploads::url($resource, $record),
            in_array($this->mode, ['create', 'edit'], true) ? Admin::url($resource::getSlug() . '/media') : null, $this->operation,
            lookupUrl: in_array($this->mode, ['create', 'edit'], true) ? Admin::url($resource::getSlug() . '/lookup') : null,
            recordId: $this->editingId !== '' ? $this->editingId : null, ownTable: $resource::getTable(),
            languageSwitch: $switch, editing: $editing, switchActive: $switch,
            sameNote: $switch ? AdminLang::get('translations.same_note', ['language' => Languages::name(Languages::default())]) : null);
        $formFields = $form->components() === [] && $form->asideComponents() === [] ? '' : $formRenderer->render($form);
        $renderer = new ActionRenderer($resource, $registry);
        $listUrl = $this->listUrl($resource::getSlug());
        return $this->view(dirname(__DIR__, 2) . '/resources/views/reactive-resource.html', [
            't' => \FloCMS\Admin\Http\PageView::translator(), 'resource' => $resource, 'table' => $table, 'result' => $result,
            'visibleColumns' => $visibility->visibleColumns(), 'columnsChanged' => $visibility->changed(), 'columnsIcon' => \FloCMS\Admin\Support\Icons::svg('columns'),
            'reorderUrl' => $scope !== null ? null : \FloCMS\Admin\Http\PageView::reorderUrl($resource, $table, $filters, \FloCMS\Core\Auth::can($resource::permission('update'))),
            'reorderIcon' => \FloCMS\Admin\Support\Icons::svg('arrows-up-down'),
            'trash' => $scope !== null ? null : \FloCMS\Admin\Http\TrashPage::toolbarLink($resource::class),
            'embedded' => $scope !== null, 'emptyText' => $scope?->manager->getEmptyText() ?? '',
            'managerCount' => $scope !== null ? $this->repository()->newQuery()->count() : 0,
            'emptyIcon' => \FloCMS\Admin\Support\Icons::svg($scope?->manager->getIcon() ?? 'layers'), 'trashIcon' => \FloCMS\Admin\Support\Icons::svg('trash'),
            'columnChoices' => array_map(static fn (string $name, bool $shown): array => ['name' => $name, 'label' => $table->getColumns()[$name]->getLabel(), 'visible' => $shown], array_keys($visibility->choices()), $visibility->choices()),
            'search' => $this->search, 'page' => $this->page, 'last' => $last, 'prefix' => $prefix, 'mode' => $this->mode,
            'filterFields' => $filters->render($prefix), 'filters' => $this->filters, 'hasActiveFilters' => $filters->active() !== [],
            'filterErrors' => $filters->errors !== [], 'filterCount' => count($filters->active()), 'chips' => $filters->chips(),
            'pageNumbers' => \FloCMS\Admin\Http\PageView::pageNumbers($this->page, $last),
            'searchIcon' => \FloCMS\Admin\Support\Icons::svg('search'), 'filterIcon' => \FloCMS\Admin\Support\Icons::svg('filter'), 'removeIcon' => \FloCMS\Admin\Support\Icons::svg('x'),
            'prevIcon' => \FloCMS\Admin\Support\Icons::svg('chevron-left', 'fa-icon fa-flip-rtl'), 'nextIcon' => \FloCMS\Admin\Support\Icons::svg('chevron-right', 'fa-icon fa-flip-rtl'),
            'bulkActions' => $bulkActions, 'selected' => $selected, 'selectedIds' => $this->selectedIds, 'selectedBulk' => $this->selectedBulk,
            'selectionLabel' => AdminLang::get('bulk.selected', ['count' => count($selected)]),
            'fullPageUrl' => Admin::url($resource::getSlug(), ['static' => 1, 'parent' => $scope?->token(), 'q' => $this->search, 'sort' => $this->sort, 'dir' => $this->direction, 'page' => $this->page, 'filter' => $filters->active()]),
            'pageActions' => $renderer->render('page', prefix: $prefix, backUrl: $listUrl), 'tableActions' => $renderer->render('table', prefix: $prefix, backUrl: $listUrl),
            'rowActions' => static fn (object $row): string => $renderer->render('row', $row, prefix: $prefix, backUrl: $listUrl),
            'dialogMinHeight' => $action?->getModalMinHeight(),
            'dialogStyle' => $action !== null && $action->getMode($resource) === 'slide-over' ? 'slide-over' : 'modal',
            'actionTitle' => $action?->getTitle($resource) ?? '', 'actionDescription' => $this->mode === 'bulk' ? \FloCMS\Admin\Actions\BulkExecutor::confirmText($resource, $action, count(\FloCMS\Admin\Actions\BulkExecutor::ids($this->bulkIds))) : trim(($action?->getDescription($resource) ?? '')
                // Deleting a parent says how many related records it has (0.22).
                . ($this->mode === 'delete' && $this->editingId !== '' ? ' ' . (\FloCMS\Admin\Relations\ManagersView::deleteCount($resource, $this->editingId) ?? '') : '')),
            'submitLabel' => $action?->getSubmitLabel($resource) ?? '', 'submitColor' => $action?->getColor() ?? 'default',
            'uploadUrl' => Admin::url($resource::getSlug() . '/upload', ['parent' => $scope?->token()]), 'uploadOperation' => $this->operation, 'uploadPlacement' => $this->activePlacement, 'uploadAction' => $this->activeAction, 'uploadId' => $this->editingId, 'uploadCsrf' => \FloCMS\Core\Csrf::token(),
            'submitAction' => match ($this->mode) { 'create', 'edit' => 'save', 'delete' => 'delete', default => 'execute' },
            'formShowsFields' => array_filter($form->fields(), static fn ($field): bool => $field->isVisible() && !$field instanceof \FloCMS\Admin\Form\Fields\Hidden) !== [],
            // No body for a confirmation without fields (it only padded the dialog).
            'formFields' => $formFields,
            'translation' => $switch ? [
                'editing' => $editing, 'defaultLang' => Languages::default(),
                'switch' => \FloCMS\Admin\Form\LanguageSwitch::render($prefix . '-switch', Languages::all(), Languages::default(), $editing, $formRenderer->languageStats(), true),
            ] : null,
            'notifications' => NotificationView::pending($this->instanceKey),
        ]);
    }
}
