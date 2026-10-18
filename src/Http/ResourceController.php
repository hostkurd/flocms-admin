<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\HttpException;
use FloCMS\Core\Session;

/**
 * Serves every resource: /admin/<slug>, /admin/<slug>/create,
 * /admin/<slug>/edit/<id> and POST /admin/<slug>/delete/<id>.
 *
 * FloCMS has already checked the login and the CSRF token when an action runs;
 * the permission of each action is set per resource in the constructor and
 * enforced by FloCMS through $actionPermissions.
 */
final class ResourceController extends PanelController
{
    private Resource $resource;
    /** @var class-string<Resource> */
    private string $resourceClass;
    private ?RecordRepository $repository = null;
    private ?Form $form = null;
    /** Set when the page is limited to one parent record (?parent=, a relation manager without JavaScript). */
    private ?\FloCMS\Admin\Relations\ParentScope $scope = null;

    public function __construct($data = [])
    {
        parent::__construct(is_array($data) ? $data : []);

        $slug = (string) App::getRouter()->getController();
        $class = Admin::registry()->find($slug);
        if ($class === null) {
            throw new HttpException(404);
        }

        $this->resourceClass = $class;
        $this->resource = new $class();

        $this->actionPermissions = [
            'admin_index' => $class::permission('view'),
            'admin_create' => $class::permission('create'),
            'admin_edit' => $class::permission('update'),
            'admin_delete' => $class::permission('delete'),
            'admin_action' => $class::permission('view'),
            'admin_bulk' => $class::permission('view'),
            'admin_upload' => $class::permission('view'),
            'admin_file' => $class::permission('view'),
            'admin_media' => $class::permission('view'),
            'admin_lookup' => $class::permission('view'),
            'admin_reorder' => $class::permission('update'),
            'admin_trash' => $class::permission('view'),
            'admin_history' => $class::permission('update'),
        ];
    }

    /* ---------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------- */

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        $class = $this->resourceClass;
        $this->enterParent();
        if (Admin::config()->reactiveEnabled() && $this->query('static', '') !== '1' && $this->scope === null) {
            \FloCMS\Reactive\Reactive::register('flo-admin-resource', \FloCMS\Admin\Reactive\ResourceComponent::class);
            return $this->panel($class::getPluralLabel(), \FloCMS\Reactive\Reactive::mount('flo-admin-resource', 'admin-resource', [
                'resourceSlug' => $class::getSlug(), 'instanceKey' => 'admin-resource',
            ]), $class::getSlug());
        }
        $table = $this->table();
        $filterInput = $this->request->queryValue('filter', []);
        if (!is_array($filterInput)) { throw new HttpException(400); }
        $filters = new \FloCMS\Admin\Table\FilterSet($table, json_encode((object) $filterInput, JSON_THROW_ON_ERROR));
        $page = max(1, (int) $this->query('page', '1'));
        $search = $this->query('q', '');
        $sort = $this->query('sort', '') ?: null;
        $dir = $this->query('dir', '') ?: null;
        $perPage = $table->getPerPage() ?? Admin::config()->perPage();

        // The Columns button: save the user's choice, then show the list without its parameters.
        $visibility = new \FloCMS\Admin\Table\ColumnVisibility($table, $this->scope?->columnsKey() ?? $class::getSlug());
        if ($this->isPost()) {
            if (($this->request->input('_columns') ?? '') !== 'apply') { throw new HttpException(400); }
            if (($this->request->input('_columns_reset') ?? '') === '1') {
                $visibility->reset();
            } else {
                $chosen = $this->request->input('columns', []);
                $visibility->save(array_values(array_filter(is_array($chosen) ? $chosen : [], 'is_string')));
            }
            // Back to the same list (search, filters, sort, page); only this resource's own list URL.
            $back = (string) ($this->request->input('_back') ?? '');
            $list = $this->scope !== null ? $this->listUrl() : Admin::url($class::getSlug());
            $this->redirect($back === $list || str_starts_with($back, $list . '?') ? $back : $list);
        }
        // Sorting by a column the user hid falls back to the default sort.
        if ($sort !== null && $visibility->isHidden($sort)) {
            $sort = null;
            $dir = null;
        }

        $result = $this->repository()->paginate($table, $search, $sort, $dir, $page, $perPage, AdminLang::language(), $filters);

        // Past the last page (e.g. after deleting its last row): go to the last one
        $lastPage = (int) $result['pagination']['last_page'];
        if ($page > 1 && $lastPage > 0 && $page > $lastPage) {
            $this->redirect(Admin::url($class::getSlug(), ['static' => Admin::config()->reactiveEnabled() ? 1 : null, 'parent' => $this->scope?->token(), 'filter' => $filters->active(), 'q' => $search, 'sort' => $sort, 'dir' => $dir, 'page' => $lastPage]));
        }

        $view = new PageView($class, $this->resource);
        $html = $view->index(
            $table,
            $result,
            $search,
            $this->can('create') && $this->resource->canCreate(),
            $this->can('update'),
            $this->can('delete'),
            fn (object $r): bool => $this->resource->canEdit($r),
            fn (object $r): bool => $this->resource->canDelete($r), $filters
        );

        return $this->panel($this->scope !== null ? AdminLang::get('relations.list_title', ['label' => $class::getPluralLabel(), 'parent' => $this->scope->parentTitle()]) : $class::getPluralLabel(), $html, $this->activeSlug());
    }

    public function admin_create(): string|\FloCMS\Core\Http\Response
    {
        $this->enterParent();
        $this->builtin('page', 'create', 'create')->authorizeRequest($this->resource, null);
        if (!$this->resource->canCreate()) {
            throw new HttpException(403);
        }

        return $this->handleForm(null);
    }

    public function admin_edit(): string|\FloCMS\Core\Http\Response
    {
        $this->enterParent();
        $record = $this->findRecordOr404();

        $this->builtin('row', 'edit', 'edit')->authorizeRequest($this->resource, $record);
        if (!$this->resource->canEdit($record)) {
            throw new HttpException(403);
        }
        if ($this->request->isMethod('GET')) {
            \FloCMS\Admin\Search\RecentRecords::remember($this->resourceClass::getSlug(), $this->recordId(), \FloCMS\Admin\Actions\ActionExecutor::label($this->resource, $record));
        }

        return $this->handleForm($record);
    }

    public function admin_delete(): string
    {
        if (!$this->isPost()) {
            header('Allow: POST');
            throw new HttpException(405);
        }

        $class = $this->resourceClass;
        $this->enterParent();
        $record = $this->findRecordOr404();

        $this->builtin('row', 'delete', 'delete')->authorizeRequest($this->resource, $record);
        if (!$this->resource->canDelete($record)) {
            throw new HttpException(403);
        }

        $batch = new \FloCMS\Admin\Uploads\UploadBatch();
        $batch->deleting($this->resource, $record);
        $label = \FloCMS\Admin\Actions\ActionExecutor::label($this->resource, $record);
        $back = $this->safeBackUrl((string) ($this->request->input('_back') ?? ''));
        try {
            $this->repository()->delete($this->recordId());
        } catch (\FloCMS\Core\ValidationException $error) {
            // Still used by other records (a foreign key): nothing was deleted.
            Session::setFlash(implode(' ', array_map(static fn ($m): string => implode(' ', (array) $m), $error->getErrors())), 'danger');
            $this->redirect($back ?? $this->listUrl());
        }
        $batch->commit();
        if (\FloCMS\Admin\Trash\Trash::enabledFor($this->resource)) {
            \FloCMS\Admin\Trash\Trash::notifyDeleted($this->resource, 1, [$label]);
        } else {
            Session::setFlash(AdminLang::get('flash.deleted', ['label' => $class::getLabel()]), 'success');
        }

        $this->redirect($back ?? $this->listUrl());
    }

    /**
     * This resource's trash: GET /admin/<slug>/trash lists its deleted records with
     * the list's columns; POST restores, deletes for good or empties it.
     */
    public function admin_trash(): string|\FloCMS\Core\Http\Response
    {
        if (count($this->params) > 0 || !\FloCMS\Admin\Trash\Trash::enabledFor($this->resourceClass)) { throw new HttpException(404); }
        $page = new TrashPage($this->resourceClass);
        if ($this->isPost()) {
            $this->redirect($page->handle($this->request->all()));
        }
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET, POST'); throw new HttpException(405); }
        return $this->panel($page->title(), $page->render($_GET), $this->resourceClass::getSlug());
    }

    /** GET /admin/<slug>/history/<id>: the record's History as a page (the drawer without JavaScript). */
    public function admin_history(): string|\FloCMS\Core\Http\Response
    {
        if (!\FloCMS\Admin\Activity\Activity::enabled() || count($this->params) !== 1) { throw new HttpException(404); }
        $record = $this->findRecordOr404();
        if (!$this->resource->canEdit($record)) { throw new HttpException(403); }
        $label = \FloCMS\Admin\Actions\ActionExecutor::label($this->resource, $record);
        $html = \FloCMS\Admin\Activity\ActivityView::history($label, \FloCMS\Admin\Activity\Activity::forRecord($this->resourceClass::getSlug(), $this->recordId()), false, $this->resourceClass::getSlug());
        return $this->panel(AdminLang::get('activity.history_of', ['title' => $label]), $html, $this->resourceClass::getSlug());
    }

    /** Standard fallback for every declared action, also used by async navigation. */
    public function admin_bulk(): string|\FloCMS\Core\Http\Response
    {
        if (!$this->request->isMethod('GET', 'POST')) { header('Allow: GET, POST'); throw new HttpException(405); }
        if (count($this->params) > 1) { throw new HttpException(400); }
        $this->enterParent();
        $name = $this->params[0] ?? $this->request->queryValue('action', '');
        if (!is_string($name)) { throw new HttpException(400); }
        $action = (new \FloCMS\Admin\Actions\ActionRegistry($this->resource))->find('bulk', $name);
        $executor = new \FloCMS\Admin\Actions\BulkExecutor($this->resource, $this->repository());
        $ids = \FloCMS\Admin\Actions\BulkExecutor::ids($this->isPost() ? $this->request->input('_ids') : $this->request->queryValue('ids', []));
        $form = $action->getForm($this->resource); $errors = [];
        if ($this->isPost()) {
            $token = $this->request->input('_operation');
            if (!is_string($token)) { throw new HttpException(400); }
            $executor->authorize($action, $ids, $token, $this->request->input('_cancel') === null);
            if ($this->request->input('_cancel') !== null) { \FloCMS\Admin\Actions\OperationStore::consume($token); $this->redirect($this->listUrl(true)); }
            $state = \FloCMS\Admin\Actions\ActionForm::safe($form, $this->request->all());
            try {
                if ($this->request->input('_refresh') !== null) {
                    $executor->preview($action, $ids, $token);
                    $state = \FloCMS\Admin\Actions\ActionForm::state($action, $form, $this->request->all(), null); $form->refreshState($state);
                } else { $executor->execute($action, $ids, $token, $this->request->all(), $state); $this->redirect($this->listUrl(true)); }
            }
            catch (\FloCMS\Core\ValidationException $error) { foreach ($error->getErrors() as $field => $messages) { $errors[$field] = implode(' ', (array) $messages); } }
        } else {
            try { $opened = $executor->open($action, $ids); }
            catch (\FloCMS\Core\ValidationException) { throw new HttpException(404); }
            $token = $opened['operation']; $state = $opened['state'];
        }
        $safe = \FloCMS\Admin\Actions\ActionForm::safe($form, $state);
        foreach ($form->fields() as $fieldName => $field) {
            if (!$field->isWritable()) { $safe[$fieldName] = $field->stateFromRecord(null, Languages::default()); }
        }
        return $this->panel($action->getTitle($this->resource), \FloCMS\Core\TemplateEngine::renderFile(PageView::viewPath('bulk'), [
            't' => PageView::translator(), 'title' => $action->getTitle($this->resource),
            'description' => \FloCMS\Admin\Actions\BulkExecutor::confirmText($this->resource, $action, count($ids)),
            'fields' => (new FormRenderer($safe, $errors, Languages::all(), Languages::default()))->render($form),
            'errors' => $errors, 'ids' => json_encode($ids, JSON_THROW_ON_ERROR), 'operation' => $token, 'csrf' => \FloCMS\Core\Csrf::field(),
            'actionUrl' => Admin::url($this->resource::getSlug() . '/bulk/' . $name, ['parent' => $this->scope?->token()]), 'submitLabel' => $action->getLabel($this->resource), 'submitColor' => $action->getColor(),
        ]), $this->resource::getSlug());
    }

    /** Standard fallback for every declared action, also used by async navigation. */
    public function admin_action(): string|\FloCMS\Core\Http\Response
    {
        if (!$this->request->isMethod('GET', 'POST')) {
            header('Allow: GET, POST'); throw new HttpException(405);
        }
        $params = is_array($this->params) ? $this->params : [];
        if (count($params) < 2 || count($params) > 3) { throw new HttpException(400); }
        [$placement, $name] = $params;
        $id = $params[2] ?? '';
        $this->enterParent();
        \FloCMS\Admin\Actions\ActionExecutor::validContext($placement, $id);
        $action = (new \FloCMS\Admin\Actions\ActionRegistry($this->resource))->find($placement, $name);
        $executor = new \FloCMS\Admin\Actions\ActionExecutor($this->resource, $this->repository());
        $form = $action->getForm($this->resource);
        $errors = []; $state = []; $record = null;
        if ($this->isPost()) {
            $token = $this->request->input('_operation');
            if (!is_string($token)) { throw new HttpException(400); }
            \FloCMS\Admin\Actions\OperationStore::get($token, $this->resource::getSlug(), $placement, $name, $id);
            if ($this->request->input('_cancel') !== null) {
                \FloCMS\Admin\Actions\OperationStore::consume($token);
                $this->redirect($this->listUrl());
            }
            $state = \FloCMS\Admin\Actions\ActionForm::safe($form, $this->request->all());
            try {
                if ($this->request->input('_refresh') !== null) {
                    Auth::authorize($this->resource::permission('view'));
                    $record = $executor->record($placement, $id); $action->authorizeRequest($this->resource, $record);
                    $state = in_array($action->getKind(), ['create', 'edit'], true)
                        ? (new \FloCMS\Admin\Form\FormSubmission($this->resource, $this->repository()))->state($form, $this->request->all(), $record)
                        : \FloCMS\Admin\Actions\ActionForm::state($action, $form, $this->request->all(), $record);
                    $form->refreshState($state, $record);
                } else {
                    $executor->execute($action, $placement, $id, $token, $this->request->all(), $state);
                    $this->redirect($this->listUrl());
                }
            } catch (\FloCMS\Core\ValidationException $error) {
                foreach ($error->getErrors() as $field => $messages) { $errors[$field] = implode(' ', (array) $messages); }
                $record = $id !== '' ? $this->repository()->find($id) : null;
            }
        } else {
            try { $opened = $executor->open($action, $placement, $id); }
            catch (\FloCMS\Core\ValidationException) { throw new HttpException(404); }
            $token = $opened['operation']; $state = $opened['state']; $record = $opened['record'];
        }
        $safe = \FloCMS\Admin\Actions\ActionForm::safe($form, $state);
        foreach ($form->fields() as $fieldName => $field) {
            if (!$field->isWritable()) { $safe[$fieldName] = $field->stateFromRecord($record, Languages::default()); }
        }
        $title = $action->getTitle($this->resource);
        return $this->panel($title, \FloCMS\Core\TemplateEngine::renderFile(PageView::viewPath('action'), [
            't' => PageView::translator(), 'title' => $title, 'description' => $action->getDescription($this->resource),
            'fields' => (new FormRenderer($safe, $errors, Languages::all(), Languages::default(), $action->getKind() === 'edit', fileUrl: \FloCMS\Admin\Uploads\Uploads::url($this->resource, $record),
                mediaUrl: in_array($action->getKind(), ['create', 'edit'], true) ? Admin::url($this->resource::getSlug() . '/media') : null,
                lookupUrl: in_array($action->getKind(), ['create', 'edit'], true) ? Admin::url($this->resource::getSlug() . '/lookup') : null,
                recordId: $id !== '' ? $id : null, ownTable: $this->resource::getTable()))->render($form),
            'errors' => $errors, 'operation' => $token, 'csrf' => \FloCMS\Core\Csrf::field(),
            'actionUrl' => Admin::url($this->resource::getSlug() . '/action/' . $placement . '/' . $name . ($id !== '' ? '/' . $id : ''), ['parent' => $this->scope?->token()]),
            'submitLabel' => $action->getSubmitLabel($this->resource), 'submitColor' => $action->getColor(),
        ]), $this->resource::getSlug());
    }

    /**
     * GET /admin/<slug>/lookup/<field>?q=…: related records for a relation field
     * of this resource's form (needs create or update), or with ?for=filter for
     * a relation filter of its list (needs view). Only declared relations; the
     * related table, title and scope come from PHP, never from the request.
     * Answers [{"id": "7", "label": "Erbil"}], at most 20.
     */
    public function admin_lookup(): \FloCMS\Core\Http\Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { throw new HttpException(405); }
        $name = (string) ($this->params[0] ?? '');
        $term = $this->request->queryValue('q', '');
        $parent = $this->request->queryValue('parent', '');
        $except = $this->request->queryValue('record', '');
        foreach ([$term, $parent, $except] as $value) { if (!is_string($value) || strlen($value) > 400) { throw new HttpException(400); } }
        $db = \FloCMS\Core\App::db() ?? throw new HttpException(503);
        $where = [];

        if ($this->request->queryValue('for', '') === 'filter') {
            $filter = $this->table()->getFilters()[$name] ?? null;
            if (!$filter instanceof \FloCMS\Admin\Table\Filters\RelationFilter) { throw new HttpException(404); }
            $source = $filter->relation();
            $except = '';
        } else {
            if (!$this->can('create') && !$this->can('update')) { throw new HttpException(403); }
            $form = $this->form();
            $field = $form->field($name);
            if (!($field instanceof \FloCMS\Admin\Form\Fields\BelongsTo || $field instanceof \FloCMS\Admin\Form\Fields\BelongsToMany) || !$field->isWritable()) { throw new HttpException(404); }
            if (($dependsOn = $field->getDependsOn()) !== null) {
                // The parent value must itself be a valid choice of the parent field.
                $parent = trim($parent);
                $state = [$dependsOn => $parent];
                $parentField = $parent === '' ? null : $form->evaluate($state)->field($dependsOn);
                if ($parentField === null || $parentField->typeError($parent) !== null) {
                    return \FloCMS\Core\Http\Response::json(['results' => []])->header('Cache-Control', 'no-store');
                }
                $where = $field->conditionsFor($parent);
            }
            $source = $field->relation();
            // A record is never offered as its own parent
            $except = $source->table() === $this->resourceClass::getTable() ? trim($except) : '';
        }

        $results = $source->search($db, $term, \FloCMS\Admin\Relations\RelationSource::LIMIT, $where, $except !== '' ? $except : null);
        return \FloCMS\Core\Http\Response::json(['results' => $results])->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    /** Any key a table can hold (the locked set decides which are valid): no control characters, at most 191. */
    private const REORDER_KEY = '/^[^\x00-\x1F\x7F]{1,191}\z/u';

    /**
     * Reorder mode: GET shows the filtered rows in their saved order with drag
     * handles (↑/↓ buttons without JavaScript); POST saves a new order (JSON
     * for scripts) or moves one row a place (forms).
     */
    public function admin_reorder(): string|\FloCMS\Core\Http\Response
    {
        if (!$this->request->isMethod('GET', 'HEAD', 'POST')) { header('Allow: GET, POST'); throw new HttpException(405); }
        if (count($this->params) > 0) { throw new HttpException(404); }
        $class = $this->resourceClass;
        $table = $this->table();
        if (!$table->isReorderable()) { throw new HttpException(404); }
        if (!$this->resource->canReorder()) { throw new HttpException(403); }
        $order = $this->repository()->order($table);

        if ($this->isPost()) {
            $json = $this->request->input('_filters', '{}');
            if (!is_string($json)) { throw new HttpException(400); }
            $filters = new \FloCMS\Admin\Table\FilterSet($table, $json === '' ? '{}' : $json);
            if ($filters->errors !== []) { throw new HttpException(400); }
            $here = Admin::url($class::getSlug() . '/reorder', ['filter' => $filters->active() ?: null]);
            $wantsJson = $this->request->acceptsJson();
            $up = $this->request->input('move_up');
            $down = $this->request->input('move_down');
            try {
                if ($up !== null || $down !== null) {
                    $id = $up ?? $down;
                    if (!is_string($id) || preg_match(self::REORDER_KEY, $id) !== 1) { throw new HttpException(400); }
                    $this->logReorder($order->move($filters, $id, $up !== null ? 'up' : 'down'));
                    if ($wantsJson) { return \FloCMS\Core\Http\Response::json(['ok' => true])->header('Cache-Control', 'no-store'); }
                    $this->redirect(Admin::url($class::getSlug() . '/reorder', ['filter' => $filters->active() ?: null, 'moved' => $id, 'step' => $up !== null ? 'up' : 'down']));
                }
                $ids = json_decode((string) (is_string($this->request->input('order')) ? $this->request->input('order') : ''), true);
                if (!is_array($ids) || !array_is_list($ids) || count($ids) > $table->getReorderLimit()) { throw new HttpException(400); }
                foreach ($ids as $index => $id) {
                    if (!is_string($id) && !is_int($id)) { throw new HttpException(400); }
                    $ids[$index] = (string) $id;
                    if (preg_match(self::REORDER_KEY, $ids[$index]) !== 1) { throw new HttpException(400); }
                }
                if (count(array_unique($ids)) !== count($ids)) { throw new HttpException(400); }
                $this->logReorder($order->reorder($filters, $ids));
            } catch (\FloCMS\Core\ValidationException $error) {
                $message = implode(' ', array_map(static fn ($m): string => implode(' ', (array) $m), $error->getErrors()));
                Session::setFlash($message, 'warning');
                if ($wantsJson) { return \FloCMS\Core\Http\Response::json(['ok' => false, 'message' => $message, 'reload' => true], 409)->header('Cache-Control', 'no-store'); }
                $this->redirect($here);
            }
            if ($wantsJson) { return \FloCMS\Core\Http\Response::json(['ok' => true])->header('Cache-Control', 'no-store'); }
            $this->redirect($here);
        }

        $filterInput = $this->request->queryValue('filter', []);
        if (!is_array($filterInput)) { throw new HttpException(400); }
        $filters = new \FloCMS\Admin\Table\FilterSet($table, json_encode((object) $filterInput, JSON_THROW_ON_ERROR));
        $moved = $this->query('moved', '');
        $step = $this->query('step', '');
        $html = (new PageView($class))->reorder($table, $filters, $filters->errors === [] ? $order->records($filters) : ['total' => 0, 'data' => []],
            $moved, in_array($step, ['up', 'down'], true) ? $step : '');

        return $this->panel(AdminLang::get('reorder.title', ['label' => $class::getPluralLabel()]), $html, $class::getSlug());
    }

    /** One activity entry per saved order: "Reordered 12 FAQs". */
    private function logReorder(int $changed): void
    {
        if ($changed > 0) {
            \FloCMS\Admin\Activity\Activity::record('reordered', $this->resourceClass::getSlug(), meta: ['count' => $changed], db: $this->repository()->connection());
        }
    }

    /** Multipart transport for a declared CRUD action; never a generic upload API. */
    public function admin_upload(): \FloCMS\Core\Http\Response
    {
        if (!$this->isPost()) { throw new HttpException(405); }
        $this->enterParent();
        $placement = $this->request->input('_placement'); $name = $this->request->input('_name');
        $id = $this->request->input('_id'); $operation = $this->request->input('_operation');
        $fieldName = $this->request->input('_field'); $draft = $this->request->input('_draft');
        foreach ([$placement, $name, $id, $operation, $fieldName, $draft] as $value) { if (!is_string($value)) { throw new HttpException(400); } }
        if (strlen($draft) > 64000) { throw new HttpException(413); }
        \FloCMS\Admin\Actions\ActionExecutor::validContext($placement, $id);
        $entry = \FloCMS\Admin\Actions\OperationStore::get($operation, $this->resource::getSlug(), $placement, $name, $id);
        $action = (new \FloCMS\Admin\Actions\ActionRegistry($this->resource))->find($placement, $name);
        if (!in_array($action->getKind(), ['create', 'edit'], true)) { throw new HttpException(400); }
        $record = (new \FloCMS\Admin\Actions\ActionExecutor($this->resource, $this->repository()))->record($placement, $id);
        $action->authorizeRequest($this->resource, $record);
        if ($record !== null && !hash_equals($entry['fingerprint'], RecordRepository::fingerprint($record))) { throw new HttpException(409); }
        try { $input = json_decode($draft, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpException(400); }
        if (!is_array($input)) { throw new HttpException(400); }
        $form = $action->getForm($this->resource);
        $state = (new \FloCMS\Admin\Form\FormSubmission($this->resource, $this->repository()))->state($form, $input, $record);
        $field = $form->evaluate($state, $record)->field($fieldName);
        if (!$field instanceof \FloCMS\Admin\Form\Fields\FileUpload || !$field->isWritable() || $field->isOmitted()) { throw new HttpException(403); }
        try {
            $value = \FloCMS\Admin\Uploads\Uploads::stage(is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [], $field, \FloCMS\Admin\Uploads\Uploads::scope($this->resource, $record, $fieldName));
        } catch (\FloCMS\Core\HttpException|\FloCMS\Uploader\Exceptions\UploadException) {
            return \FloCMS\Core\Http\Response::json(['error' => AdminLang::get('uploads.invalid')], 422)->header('Cache-Control', 'no-store');
        }
        return \FloCMS\Core\Http\Response::json(['value' => $value, 'url' => (\FloCMS\Admin\Uploads\Uploads::url($this->resource, $record))($field, $value)])->header('Cache-Control', 'no-store');
    }

    /**
     * Rich text media: POST /admin/<slug>/media/<field>/<step>, where step is
     * image (one request), or start, chunk, complete and abort for a video sent
     * in parts. Media is public once stored; only people who may create or edit
     * this resource can upload, and only into a RichEditor that allows it.
     */
    public function admin_media(): \FloCMS\Core\Http\Response
    {
        if (!$this->isPost()) { throw new HttpException(405); }
        if (count($this->params) !== 2) { throw new HttpException(404); }
        [$fieldName, $step] = $this->params;
        if (!$this->can('create') && !$this->can('update')) { throw new HttpException(403); }
        $field = $this->form()->fields()[$fieldName] ?? null;
        if (!$field instanceof \FloCMS\Admin\Form\Fields\RichEditor || $field->isDisabled()) { throw new HttpException(404); }
        $video = in_array($step, ['start', 'chunk', 'complete', 'abort'], true);
        if (($step === 'image' && !$field->allowsImages()) || ($video && !$field->allowsVideos()) || ($step !== 'image' && !$video)) { throw new HttpException(404); }
        $slug = $this->resource::getSlug();
        $media = \FloCMS\Admin\Uploads\EditorMedia::class;
        $json = static fn (array $data, int $status = 200): \FloCMS\Core\Http\Response => \FloCMS\Core\Http\Response::json($data, $status)->header('Cache-Control', 'no-store');
        $id = static function (mixed $value): string {
            if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) { throw new HttpException(400); }
            return $value;
        };
        $number = static function (mixed $value): int {
            $number = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
            if ($number === false) { throw new HttpException(400); }
            return $number;
        };
        $chunk = static function (): array {
            $file = $_FILES['chunk'] ?? null;
            if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) { throw new HttpException(400); }
            return $file;
        };
        try {
            return match ($step) {
                'image' => $json($media::image(is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [], $field, $slug)),
                'start' => $json($media::start($field, $slug, is_string($name = $this->request->input('name')) ? mb_substr($name, 0, 200) : '', $number($this->request->input('size')))),
                'chunk' => $json($media::append($field, $slug, $id($this->request->input('upload_id')), $number($this->request->input('offset')), $chunk())),
                'complete' => $json($media::complete($field, $slug, $id($this->request->input('upload_id')))),
                'abort' => (function () use ($media, $field, $slug, $id, $json) { $media::abort($field, $slug, $id($this->request->input('upload_id'))); return $json(['ok' => true]); })(),
            };
        } catch (\FloCMS\Uploader\Exceptions\ChunkOffsetException $e) {
            return $json(['error' => 'offset', 'received' => $e->expectedOffset()], 409);
        } catch (\FloCMS\Uploader\Exceptions\ValidationException) {
            return $json(['error' => AdminLang::get($step === 'image' ? 'editor.image_invalid' : 'editor.video_invalid')], 422);
        } catch (\FloCMS\Uploader\Exceptions\UploadException) {
            return $json(['error' => AdminLang::get('editor.upload_failed')], 500);
        }
    }

    /** Managed files are private; recheck the current record policy on every read. */
    public function admin_file(): \FloCMS\Core\Http\Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { throw new HttpException(405); }
        if (count($this->params) !== 2) { throw new HttpException(404); }
        [$id, $name] = $this->params;
        $pending = $id === 'pending';
        if ($pending) {
            $token = $this->request->queryValue('token', ''); if (!is_string($token)) { throw new HttpException(404); }
            $entry = \FloCMS\Admin\Uploads\Uploads::entry($token);
            [$slug, $id, $fieldName] = json_decode($entry['scope'], true, 64, JSON_THROW_ON_ERROR);
            if ($slug !== $this->resource::getSlug() || $fieldName !== $name) { throw new HttpException(404); }
        }
        $record = $id !== '' ? $this->repository()->find($id) : null;
        if ($id !== '' && $record === null) { throw new HttpException(404); }
        Auth::authorize($this->resource::permission($record === null ? 'create' : 'update'));
        if ($record === null ? !$this->resource->canCreate() : !$this->resource->canEdit($record)) { throw new HttpException(403); }
        $form = $this->form(); $state = [];
        foreach ($form->fields() as $n => $f) { $state[$n] = $f->stateFromRecord($record, Languages::default()); }
        $field = $form->evaluate($state, $record)->field($name);
        if (!$field instanceof \FloCMS\Admin\Form\Fields\FileUpload || (!$pending && !$field->isVisible())) { throw new HttpException(404); }
        $path = $pending ? $entry['path'] : (string) ($record->{$name} ?? '');
        if (!$pending && !\FloCMS\Admin\Uploads\Uploads::managed($path, $this->resource, $field)) { throw new HttpException(404); }
        $absolute = \FloCMS\Admin\Uploads\Uploads::storage()->path($path);
        if ($absolute === null || !is_file($absolute) || filesize($absolute) > 16777216) { throw new HttpException(404); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolute);
        $inline = $field instanceof \FloCMS\Admin\Form\Fields\ImageUpload && in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
        return \FloCMS\Core\Http\Response::make($this->request->isMethod('HEAD') ? '' : (string) file_get_contents($absolute))
            ->header('Content-Type', $inline ? $mime : 'application/octet-stream')
            ->header('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . basename($path) . '"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    private function builtin(string $placement, string $name, string $kind): \FloCMS\Admin\Actions\Action
    {
        $action = (new \FloCMS\Admin\Actions\ActionRegistry($this->resource))->find($placement, $name);
        if ($action->getKind() !== $kind) { throw new HttpException(404); }
        return $action;
    }

    /* ---------------------------------------------------------------
     * Create and edit
     * ------------------------------------------------------------- */

    private function handleForm(?object $record): string|\FloCMS\Core\Http\Response
    {
        $class = $this->resourceClass;
        $form = $this->form();
        $languages = Languages::all();
        $default = Languages::default();
        $isEdit = $record !== null;
        $errors = [];

        if ($this->isPost()) {
            $submission = new \FloCMS\Admin\Form\FormSubmission($this->resource, $this->repository());
            $input = \FloCMS\Admin\Uploads\Uploads::receive($form, $this->request->all(), $_FILES['_uploads'] ?? [], $this->resource, $record);
            $state = $submission->state($form, $input, $record);
            $refreshing = $this->request->input('_refresh') !== null;
            if ($refreshing) { $form->refreshState($state, $record); }
            $errors = $refreshing ? [] : $submission->errors($form, $state, $record);

            if (!$refreshing && $errors === []) {
                try {
                    $id = $submission->save($form, $state, $record);
                } catch (\FloCMS\Core\ValidationException $error) {
                    // Refused inside the save (a chosen media item was deleted meanwhile): nothing was written.
                    foreach ($error->getErrors() as $field => $messages) { $errors[(string) $field] = implode(' ', (array) $messages); }
                }
            }
            if (!$refreshing && $errors === []) {
                Session::setFlash(AdminLang::get($isEdit ? 'flash.updated' : 'flash.created', ['label' => $class::getLabel()]), 'success');

                // Save and close → back to the list it came from; Save and create
                // another → a new form; Save (the default, also Enter) → stay on the record.
                $back = $this->backUrl();
                $returnTo = $back !== Admin::url($class::getSlug()) ? $back : null;
                $parent = $this->scope?->token();
                if ($this->request->input('_another') !== null && $this->can('create') && $this->resource->canCreate()) {
                    $this->redirect(Admin::url($class::getSlug() . '/create', ['parent' => $parent, 'back' => $returnTo]));
                }
                $saved = $id !== '' ? $this->repository()->find($id) : null;
                $stay = $this->request->input('_close') === null && $this->can('update') && $saved !== null && $this->resource->canEdit($saved);
                $this->redirect($stay ? Admin::url($class::getSlug() . '/edit/' . $id, ['parent' => $parent, 'back' => $returnTo]) : $back);
            }

            if (!$refreshing) {
                $where = $form->errorSummary($errors);
                Session::setFlash($where === '' ? AdminLang::get('flash.fix_errors') : AdminLang::get('flash.fix_errors_in', ['fields' => $where]), 'danger');
            }
        } else {
            $state = [];
            foreach ($form->fields() as $name => $field) {
                $state[$name] = $field->stateFromRecord($record, $default);
            }
        }

        $renderer = new FormRenderer($state, $errors, $languages, $default, $isEdit, fileUrl: \FloCMS\Admin\Uploads\Uploads::url($this->resource, $record), mediaUrl: Admin::url($this->resource::getSlug() . '/media'),
            lookupUrl: Admin::url($this->resource::getSlug() . '/lookup'), recordId: $isEdit ? $this->recordId() : null, ownTable: $class::getTable());
        $view = new PageView($class);
        $title = $isEdit
            ? AdminLang::get('form.edit_title', ['label' => $class::getLabel()])
            : AdminLang::get('form.create_title', ['label' => $class::getLabel()]);

        $deleteUrl = $isEdit && $this->can('delete') && $this->resource->canDelete($record)
            ? Admin::url($class::getSlug() . '/delete/' . $this->recordId(), ['parent' => $this->scope?->token()])
            : null;

        $back = $this->backUrl();
        $returnTo = $back !== $this->defaultBackUrl() ? $back : null;
        $action = Admin::url($class::getSlug() . ($isEdit ? '/edit/' . $this->recordId() : '/create'), ['parent' => $this->scope?->token(), 'back' => $returnTo]);
        $history = null;
        if ($isEdit && \FloCMS\Admin\Activity\Activity::enabled()) {
            $label = \FloCMS\Admin\Actions\ActionExecutor::label($this->resource, $record);
            $history = [
                'url' => Admin::url($class::getSlug() . '/history/' . $this->recordId()),
                'html' => \FloCMS\Admin\Activity\ActivityView::history($label, \FloCMS\Admin\Activity\Activity::forRecord($class::getSlug(), $this->recordId()), true, $class::getSlug()),
                'title' => $label,
            ];
        }
        // Related records (0.22): below the form once the record exists; the create page says they come after saving.
        $managers = $this->scope !== null ? '' : ($isEdit ? \FloCMS\Admin\Relations\ManagersView::render($this->resource, $this->recordId()) : \FloCMS\Admin\Relations\ManagersView::pending($this->resource));
        $deleteText = $deleteUrl !== null ? \FloCMS\Admin\Relations\ManagersView::deleteCount($this->resource, $this->recordId()) : null;
        $html = $view->form($title, $action, $renderer->render($form), $deleteUrl, $isEdit, $this->can('update'), $errors !== [], $back,
            !$isEdit && $this->can('create') && $this->resource->canCreate(), $this->isPost(), $form->asideComponents() !== [], $history, $managers, $deleteText,
            $this->scope !== null ? ['url' => $this->scope->parentUrl(), 'title' => $this->scope->parentTitle()]
                : ($isEdit && !$class::showsInNavigation() ? \FloCMS\Admin\Relations\ManagersView::parentOf($this->resource, $record) : null));

        return $this->panel($title, $html, $this->activeSlug());
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private function table(): Table
    {
        $table = $this->resource->table(new Table());
        // Inside a parent: the manager's columns, page size and sort, never the key column or its filter.
        if ($this->scope !== null) { $table = $this->scope->adjustTable($table); }
        $translatable = $this->form()->translatableNames();
        foreach ($table->getColumns() as $name => $column) {
            $column->detectTranslatable(in_array($name, $translatable, true));
        }

        return $table;
    }

    private function form(): Form
    {
        if ($this->form !== null) { return $this->form; }
        $form = $this->resource->form(new Form());
        return $this->form = $this->scope !== null ? $this->scope->adjustForm($form) : $form;
    }

    private function repository(): RecordRepository
    {
        if ($this->repository === null) {
            $db = App::db();
            if ($db === null) {
                throw new \RuntimeException('flocms-admin needs a database: set DB_NAME, DB_USERNAME and DB_PASSWORD in .env.');
            }
            $this->repository = new RecordRepository($this->resource, $db, Translations::store());
        }

        return $this->repository;
    }

    private function recordId(): string
    {
        $params = is_array($this->params) ? $this->params : [];

        return (string) ($params[0] ?? '');
    }

    private function findRecordOr404(): object
    {
        $id = $this->recordId();
        if ($id === '' || preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $id) !== 1) {
            throw new HttpException(404);
        }

        return $this->repository()->find($id) ?? throw new HttpException(404);
    }

    private function can(string $ability): bool
    {
        return Auth::can($this->resourceClass::permission($ability));
    }

    private function query(string $key, string $default): string
    {
        $value = $this->request->queryValue($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** Where Cancel and Save and close return to: the list with its search, filters and page. */
    private function backUrl(): string
    {
        $value = $this->isPost() ? $this->request->input('_back') : $this->request->queryValue('back', '');
        if ($this->isPost() && !is_string($value)) { $value = $this->request->queryValue('back', ''); }
        return $this->safeBackUrl(is_string($value) ? $value : '') ?? $this->defaultBackUrl();
    }

    /** The list, or inside a parent its edit page. */
    private function defaultBackUrl(): string
    {
        return $this->scope !== null ? $this->scope->parentUrl() : Admin::url($this->resourceClass::getSlug());
    }

    /** Only same-site admin list URLs (and inside a parent, its edit page) are accepted as a place to go back to. */
    private function safeBackUrl(string $url): ?string
    {
        foreach (array_filter([Admin::url($this->resourceClass::getSlug()), $this->scope?->parentUrl()]) as $base) {
            if ($url !== '' && ($url === $base || str_starts_with($url, $base . '?'))) { return $url; }
        }
        return null;
    }

    /** The list after an action: the static list limited to the parent inside one. */
    private function listUrl(bool $static = false): string
    {
        return $this->scope !== null
            ? Admin::url($this->resourceClass::getSlug(), ['static' => Admin::config()->reactiveEnabled() ? 1 : null, 'parent' => $this->scope->token()])
            : Admin::url($this->resourceClass::getSlug(), ['static' => $static ? 1 : null]);
    }

    /** The sidebar entry to mark: the parent's inside one, nothing for a resource without its own entry. */
    private function activeSlug(): ?string
    {
        if ($this->scope !== null) { return $this->scope->parentSlug(); }
        return $this->resourceClass::showsInNavigation() ? $this->resourceClass::getSlug() : null;
    }

    /**
     * ?parent=properties:5:units limits the page to the units of property 5
     * (the relation manager without JavaScript), after checking that the user
     * may edit that property. Called first by each action.
     */
    private function enterParent(): void
    {
        $token = $this->request->queryValue('parent', '');
        if (!is_string($token) || strlen($token) > 200) { throw new HttpException(400); }
        if ($token === '' || $this->scope !== null) { return; }
        $this->scope = \FloCMS\Admin\Relations\ParentScope::fromToken($token, $this->resourceClass::getSlug());
        $this->resource = $this->resource->withinParent($this->scope);
        $this->repository = null;
        $this->form = null;
    }

}
