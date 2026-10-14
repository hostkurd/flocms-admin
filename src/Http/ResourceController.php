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
        ];
    }

    /* ---------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------- */

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        $class = $this->resourceClass;
        if (Admin::config()->reactiveEnabled() && $this->query('static', '') !== '1') {
            \FloCMS\Reactive\Reactive::register('flo-admin-resource', \FloCMS\Admin\Reactive\ResourceComponent::class);
            return $this->panel($class::getPluralLabel(), \FloCMS\Reactive\Reactive::mount('flo-admin-resource', 'admin-resource', [
                'resourceSlug' => $class::getSlug(), 'instanceKey' => 'admin-resource',
            ]), $class::getSlug());
        }
        $table = $this->table();
        $page = max(1, (int) $this->query('page', '1'));
        $search = $this->query('q', '');
        $sort = $this->query('sort', '') ?: null;
        $dir = $this->query('dir', '') ?: null;
        $perPage = $table->getPerPage() ?? Admin::config()->perPage();

        $result = $this->repository()->paginate($table, $search, $sort, $dir, $page, $perPage, AdminLang::language());

        // Past the last page (e.g. after deleting its last row): go to the last one
        $lastPage = (int) $result['pagination']['last_page'];
        if ($page > 1 && $lastPage > 0 && $page > $lastPage) {
            $this->redirect(Admin::url($class::getSlug(), ['q' => $search, 'sort' => $sort, 'dir' => $dir, 'page' => $lastPage]));
        }

        $view = new PageView($class);
        $html = $view->index(
            $table,
            $result,
            $search,
            $this->can('create') && $this->resource->canCreate(),
            $this->can('update'),
            $this->can('delete'),
            fn (object $r): bool => $this->resource->canEdit($r),
            fn (object $r): bool => $this->resource->canDelete($r)
        );

        return $this->panel($class::getPluralLabel(), $html, $class::getSlug());
    }

    public function admin_create(): string|\FloCMS\Core\Http\Response
    {
        $this->builtin('page', 'create', 'create')->authorizeRequest($this->resource, null);
        if (!$this->resource->canCreate()) {
            throw new HttpException(403);
        }

        return $this->handleForm(null);
    }

    public function admin_edit(): string|\FloCMS\Core\Http\Response
    {
        $record = $this->findRecordOr404();

        $this->builtin('row', 'edit', 'edit')->authorizeRequest($this->resource, $record);
        if (!$this->resource->canEdit($record)) {
            throw new HttpException(403);
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
        $record = $this->findRecordOr404();

        $this->builtin('row', 'delete', 'delete')->authorizeRequest($this->resource, $record);
        if (!$this->resource->canDelete($record)) {
            throw new HttpException(403);
        }

        $this->repository()->delete($this->recordId());
        Session::setFlash(AdminLang::get('flash.deleted', ['label' => $class::getLabel()]), 'success');

        $back = $this->safeBackUrl((string) ($this->request->input('_back') ?? ''));
        $this->redirect($back ?? Admin::url($class::getSlug()));
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
                $this->redirect(Admin::url($this->resource::getSlug()));
            }
            $state = \FloCMS\Admin\Actions\ActionForm::safe($form, $this->request->all());
            try {
                $executor->execute($action, $placement, $id, $token, $this->request->all(), $state);
                $this->redirect(Admin::url($this->resource::getSlug()));
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
            't' => PageView::translator(), 'title' => $title, 'description' => $action->getDescription(),
            'fields' => (new FormRenderer($safe, $errors, Languages::all(), Languages::default(), $action->getKind() === 'edit'))->render($form),
            'errors' => $errors, 'operation' => $token, 'csrf' => \FloCMS\Core\Csrf::field(),
            'actionUrl' => Admin::url($this->resource::getSlug() . '/action/' . $placement . '/' . $name . ($id !== '' ? '/' . $id : '')),
            'submitLabel' => $action->getSubmitLabel($this->resource), 'submitColor' => $action->getColor(),
        ]), $this->resource::getSlug());
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
            $state = $submission->state($form, $this->request->all(), $record);
            $errors = $submission->errors($form, $state, $record);

            if ($errors === []) {
                $id = $submission->save($form, $state, $record);
                Session::setFlash(AdminLang::get($isEdit ? 'flash.updated' : 'flash.created', ['label' => $class::getLabel()]), 'success');

                $continue = $this->request->input('_continue') !== null && $this->can('update');
                $saved = $id !== '' ? $this->repository()->find($id) : null;
                $continue = $continue && $saved !== null && $this->resource->canEdit($saved);
                $this->redirect($continue && $id !== '' ? Admin::url($class::getSlug() . '/edit/' . $id) : Admin::url($class::getSlug()));
            }

            Session::setFlash(AdminLang::get('flash.fix_errors'), 'danger');
        } else {
            $state = [];
            foreach ($form->fields() as $name => $field) {
                $state[$name] = $field->stateFromRecord($record, $default);
            }
        }

        $renderer = new FormRenderer($state, $errors, $languages, $default, $isEdit);
        $view = new PageView($class);
        $title = $isEdit
            ? AdminLang::get('form.edit_title', ['label' => $class::getLabel()])
            : AdminLang::get('form.create_title', ['label' => $class::getLabel()]);

        $deleteUrl = $isEdit && $this->can('delete') && $this->resource->canDelete($record)
            ? Admin::url($class::getSlug() . '/delete/' . $this->recordId())
            : null;

        $action = $isEdit ? Admin::url($class::getSlug() . '/edit/' . $this->recordId()) : Admin::url($class::getSlug() . '/create');
        $html = $view->form($title, $action, $renderer->render($form), $deleteUrl, $isEdit, $this->can('update'), $errors !== []);

        return $this->panel($title, $html, $class::getSlug());
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private function table(): Table
    {
        $table = $this->resource->table(new Table());
        $translatable = $this->form()->translatableNames();
        foreach ($table->getColumns() as $name => $column) {
            $column->detectTranslatable(in_array($name, $translatable, true));
        }

        return $table;
    }

    private function form(): Form
    {
        return $this->form ??= $this->resource->form(new Form());
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

    /** Only same-site admin list URLs are accepted as a place to go back to. */
    private function safeBackUrl(string $url): ?string
    {
        $base = Admin::url($this->resourceClass::getSlug());

        return $url !== '' && ($url === $base || str_starts_with($url, $base . '?')) ? $url : null;
    }
}
