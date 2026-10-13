<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Admin\Validation\FormValidator;
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
        ];
    }

    /* ---------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------- */

    public function admin_index(): string
    {
        $class = $this->resourceClass;
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

    public function admin_create(): string
    {
        if (!$this->resource->canCreate()) {
            throw new HttpException(403);
        }

        return $this->handleForm(null);
    }

    public function admin_edit(): string
    {
        $record = $this->findRecordOr404();

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

        if (!$this->resource->canDelete($record)) {
            throw new HttpException(403);
        }

        $this->repository()->delete($this->recordId());
        Session::setFlash(AdminLang::get('flash.deleted', ['label' => $class::getLabel()]), 'success');

        $back = $this->safeBackUrl((string) ($this->request->input('_back') ?? ''));
        $this->redirect($back ?? Admin::url($class::getSlug()));
    }

    /* ---------------------------------------------------------------
     * Create and edit
     * ------------------------------------------------------------- */

    private function handleForm(?object $record): string
    {
        $class = $this->resourceClass;
        $form = $this->form();
        $languages = Languages::all();
        $default = Languages::default();
        $isEdit = $record !== null;
        $errors = [];

        if ($this->isPost()) {
            $input = $this->request->all();
            $state = [];
            foreach ($form->fields() as $name => $field) {
                $state[$name] = $field->isWritable()
                    ? $field->stateFromInput($input, $languages)
                    : $field->stateFromRecord($record, $default);
            }
            foreach ($form->fields() as $field) {
                $state = $field->prepare($state, $default);
            }

            $state = $this->resource->normalizeState($state, $record);

            $skip = [];
            foreach ($form->writableFields() as $name => $field) {
                if ($field->skipsWrite($state[$name], $isEdit)) {
                    $skip[] = $name;
                }
            }

            $exceptId = $isEdit ? $this->recordId() : null;
            $validator = new FormValidator($languages, $default, function (Field $field, string $value, ?string $language = null) use ($exceptId): bool {
                return $this->repository()->exists($field->getName(), $value, $exceptId, $language);
            }, $isEdit);
            $errors = $validator->validate($form, $state, $skip);
            if ($errors === []) {
                $errors = $this->resource->validate($state, $record);
            }

            if ($errors === []) {
                $id = $this->repository()->transaction(fn (): string => $this->save($form, $state, $skip, $record));
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

    /**
     * Write the record and return its id.
     *
     * @param array<string, string|array<string, string>> $state
     * @param list<string> $skip
     */
    private function save(Form $form, array $state, array $skip, ?object $record): string
    {
        $class = $this->resourceClass;
        $data = [];
        foreach ($form->writableFields() as $name => $field) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            $value = $state[$name];
            if ($record !== null && $field->isTranslatable()) {
                // Only configured languages are editable. Retain all other stored keys.
                $stored = Translations::store()->decode($record->{$name} ?? null, Languages::default());
                $editable = array_fill_keys(Languages::all(), true);
                $value = array_replace(
                    $stored,
                    array_fill_keys(Languages::all(), ''),
                    array_intersect_key(is_array($value) ? $value : [], $editable)
                );
            }
            $data[$name] = $field->dehydrate($value);
        }

        // Never from input
        $pk = $class::getPrimaryKey();
        if ($record !== null) {
            unset($data[$pk]);
        }
        unset($data['created_at'], $data['updated_at']);

        $data = $this->resource->beforeSave($data, $record);

        if ($class::usesTimestamps()) {
            $now = date('Y-m-d H:i:s');
            if ($record === null) {
                $data['created_at'] = $now;
            }
            $data['updated_at'] = $now;
        }

        $repository = $this->repository();
        if ($record === null) {
            $id = $repository->insert($data);
        } else {
            $id = $this->recordId();
            $repository->update($id, $data);
        }

        $saved = $id !== '' ? $repository->find($id) : null;
        if ($saved !== null) {
            $this->resource->afterSave($saved);
        }

        return $id;
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
