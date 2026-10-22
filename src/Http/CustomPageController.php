<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Actions\Action;
use FloCMS\Admin\Actions\ActionForm;
use FloCMS\Admin\Actions\OperationStore;
use FloCMS\Admin\Admin;
use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\PanelPages\PageFilters;
use FloCMS\Admin\PanelPages\PageForm;
use FloCMS\Admin\PanelPages\PageTable;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Log;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\App;
use FloCMS\Core\HttpException;
use FloCMS\Core\Session;
use FloCMS\Core\TemplateEngine;
use FloCMS\Core\ValidationException;

/**
 * /admin/<slug> for the site's own panel pages (0.36): the page, its header
 * actions (/action/<name>), its table's CSV (/csv) and its declared routes
 * (/route/<name>). Every address needs the page's permission and canAccess().
 */
final class CustomPageController extends PanelController
{
    private AdminPage $page;

    public function __construct($data = [])
    {
        parent::__construct(is_array($data) ? $data : []);
        $class = Admin::panelPages()->find((string) App::getRouter()->getController()) ?? throw new HttpException(404);
        $this->page = new $class();
        $this->actionPermissions = ['*' => $class::permission()];
    }

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        $this->authorizePage();
        $filters = $this->filters();
        $table = $this->guard(fn (): ?PageTable => PageTable::of($this->page));

        if ($this->isPost()) {
            if (($this->request->input('_columns') ?? '') === 'apply' && $table !== null) {
                $this->saveColumns($table);
            }
            if (($this->request->input('_page_form') ?? '') !== '1' || !$this->page->hasForm()) { throw new HttpException(400); }
            return $this->savePage($filters, $table);
        }
        return $this->render($filters, $table);
    }

    /** The table's rows as a CSV file (the filters, search and sort of the page). */
    public function admin_csv(): \FloCMS\Core\Http\Response
    {
        $this->authorizePage();
        if (!$this->request->isMethod('GET', 'HEAD') || count($this->params) > 0) { throw new HttpException(405); }
        $table = PageTable::of($this->page) ?? throw new HttpException(404);
        if (!$table->table()->isExportable() || !Admin::config()->export()['enabled']) { throw new HttpException(404); }
        $this->filters();
        $search = $this->query('q');
        $sort = $this->query('sort') ?: null;
        $dir = $this->query('dir') ?: null;
        $name = $this->page::getSlug() . '-' . date('Y-m-d') . '.csv';
        $headers = ImportPage::csvHeaders($name);
        $response = \FloCMS\Core\Http\Response::make('');
        foreach ($headers as $header => $value) { $response = $response->header($header, $value); }
        if ($this->request->isMethod('HEAD')) { return $response; }
        @set_time_limit(300);
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        foreach ($headers as $header => $value) { header($header . ': ' . $value); }
        $writer = new \FloCMS\Admin\ImportExport\CsvWriter();
        try {
            $rows = $table->csv($writer, $search, $sort, $dir);
            \FloCMS\Admin\Activity\Activity::record('exported', \FloCMS\Admin\PanelPages\CustomPages::ACTIVITY, $this->page::getSlug(), strip_tags($this->page::getLabel()), [], ['rows' => $rows]);
        } catch (\Throwable $error) {
            Log::error('CSV of the page ' . $this->page::getSlug() . ' stopped: ' . $error->getMessage());
            $writer->row([AdminLang::get('export.stopped')]);
        }
        return $response;
    }

    /** A header action: its page (form or confirmation) on GET, run on POST. */
    public function admin_action(): string|\FloCMS\Core\Http\Response
    {
        $this->authorizePage();
        if (!$this->request->isMethod('GET', 'POST')) { header('Allow: GET, POST'); throw new HttpException(405); }
        if (count($this->params) !== 1) { throw new HttpException(404); }
        $name = (string) $this->params[0];
        $action = $this->findAction($name);
        if (!$action->allowedOnPage($this->page)) { throw new HttpException(403); }
        $filters = $this->filters();
        $form = $action->getPageForm();
        $slug = $this->page::getSlug();
        $back = Admin::url($slug, ['filter' => $filters->active() ?: null]);
        $operationSlug = 'page:' . $slug;
        $errors = [];

        if ($this->isPost()) {
            $token = $this->request->input('_operation');
            if (!is_string($token)) { throw new HttpException(400); }
            OperationStore::get($token, $operationSlug, 'page', $name, '');
            if ($this->request->input('_cancel') !== null) {
                OperationStore::consume($token);
                $this->redirect($back);
            }
            $state = ActionForm::state($action, $form, $this->request->all(), null);
            if ($this->request->input('_refresh') !== null || $this->request->input('_repeater') !== null) {
                if ($this->request->input('_repeater') !== null) { $form->evaluate($state)->applyRepeaterOperation($state, $this->request->input('_repeater')); }
                $form->refreshState($state, null);
            } else {
                try {
                    $errors = ActionForm::errors($action, $form, $state, null);
                    if ($errors !== []) { throw new ValidationException($errors); }
                    $result = \FloCMS\Admin\Notifications\Notifications::capture(fn (): mixed => $action->invokeOnPage(ActionForm::dehydrate($form, $state), $this->page));
                    OperationStore::consume($token);
                    $this->afterAction($action, $result, $back);
                } catch (ValidationException $error) {
                    $errors = [];
                    foreach ($error->getErrors() as $field => $messages) { $errors[(string) $field] = implode(' ', (array) $messages); }
                }
            }
        } else {
            $token = OperationStore::issue($operationSlug, 'page', $name, '', '');
            $state = $action->initialState($form, null);
        }

        $renderer = new FormRenderer(ActionForm::safe($form, $state) + $state, $errors, Languages::all(), Languages::default());
        $title = $action->getPageTitle();
        return $this->panel($title, TemplateEngine::renderFile(PageView::viewPath('action'), [
            't' => PageView::translator(), 'title' => $title, 'description' => $action->getDescription(),
            'fields' => $renderer->render($form), 'translation' => null,
            'errors' => $errors, 'operation' => $token, 'csrf' => \FloCMS\Core\Csrf::field(),
            'actionUrl' => Admin::url($slug . '/action/' . $name, ['filter' => $filters->active() ?: null]),
            'submitLabel' => $action->getPageLabel(), 'submitColor' => $action->getColor(),
        ]), $slug);
    }

    /** A route the page declares in $routes: /admin/<slug>/route/<name>. */
    public function admin_route(): string|\FloCMS\Core\Http\Response
    {
        $this->authorizePage();
        if (count($this->params) < 1) { throw new HttpException(404); }
        $name = (string) $this->params[0];
        $method = $this->page::routes()[$name] ?? null;
        if (!is_string($method) || $this->page::configurationErrors() !== []) { throw new HttpException(404); }
        $this->filters();
        $result = $this->page->{$method}(...array_slice(array_map('strval', $this->params), 1));
        if ($result instanceof \FloCMS\Core\Http\Response) { return $result; }
        if (is_array($result)) { return \FloCMS\Core\Http\Response::json($result)->header('Cache-Control', 'no-store'); }
        if (is_string($result)) { return $this->panel($this->page::getLabel(), $result, $this->page::getSlug()); }
        $this->redirect(Admin::url($this->page::getSlug()));
    }

    /* ---------------------------------------------------------------- */

    private function render(PageFilters $filters, ?PageTable $table, ?array $formState = null, array $formErrors = []): string|\FloCMS\Core\Http\Response
    {
        $page = $this->page;
        $slug = $page::getSlug();
        $search = $this->query('q');
        $sort = $this->query('sort') ?: null;
        $dir = $this->query('dir') ?: null;
        $state = ['filter' => $filters->active() ?: null, 'q' => $search !== '' ? $search : null, 'sort' => $sort, 'dir' => $dir];

        $tableHtml = '';
        if ($table !== null) {
            $tableHtml = $this->guard(function () use ($table, $search, $sort, $dir, $state, $slug): string {
                $result = $table->paginate($search, $sort, $dir, max(1, (int) $this->query('page', '1')));
                $last = (int) $result['pagination']['last_page'];
                $current = (int) $result['pagination']['cur_page'];
                if ($current > 1 && $last > 0 && $current > $last) { $this->redirect(Admin::url($slug, $state + ['page' => $last > 1 ? $last : null])); }
                return $table->render($result, $search, array_replace($state, ['sort' => $result['sort'] === $sort ? $sort : null]));
            }) ?? $this->unavailable();
        }

        $form = null;
        if ($page->hasForm()) {
            $form = $this->guard(function () use ($formState, $formErrors): array {
                $pageForm = new PageForm($this->page);
                $state = $formState ?? $pageForm->initialState();
                $renderer = new FormRenderer($state, $formErrors, Languages::all(), Languages::default(), true);
                return ['fields' => $renderer->render($pageForm->form()), 'errors' => $formErrors, 'dirty' => $formState !== null,
                    'submit' => $this->page::getSubmitLabel()];
            });
            if ($form === null) { $form = ['fields' => $this->unavailable(), 'errors' => [], 'dirty' => false, 'submit' => AdminLang::get('actions.save')]; }
        }

        $widgets = $this->guard(fn (): string => $this->widgets($filters->values())) ?? $this->unavailable();
        $view = $this->guard(fn (): string => $this->view($filters->values())) ?? $this->unavailable();
        $actions = $this->guard(fn (): string => $this->actions($filters)) ?? '';
        $columns = 2;
        try { $columns = Admin::config()->widgetColumns(); } catch (\InvalidArgumentException) {}

        $html = TemplateEngine::renderFile(PageView::viewPath('panel-page'), [
            't' => PageView::translator(),
            'slug' => $slug,
            'url' => Admin::url($slug),
            'width' => $page::getWidth(),
            'group' => $page::getGroup(),
            'title' => $page::getLabel(),
            'description' => $page::getDescription(),
            'actions' => $actions,
            'filters' => $filters->exists() ? ['fields' => $filters->render(), 'active' => $filters->active() !== [], 'q' => $search, 'sort' => $sort, 'dir' => $dir] : null,
            'widgets' => $widgets,
            'widgetColumns' => $columns,
            'view' => $view,
            'table' => $tableHtml,
            'tableLabel' => $page::getLabel(),
            'form' => $form,
            'csrf' => \FloCMS\Core\Csrf::field(),
            'empty' => $widgets === '' && $view === '' && $table === null && $form === null && $actions === '' && !$filters->exists(),
            'emptyHint' => 'admin/Pages/' . (new \ReflectionClass($page))->getShortName() . '.php',
        ]);
        return $this->panel($page::getLabel(), $html, $slug);
    }

    private function savePage(PageFilters $filters, ?PageTable $table): string|\FloCMS\Core\Http\Response
    {
        $pageForm = new PageForm($this->page);
        $input = $this->request->all();
        if ($this->request->input('_repeater') !== null) {
            return $this->render($filters, $table, $pageForm->operate($input, $this->request->input('_repeater')));
        }
        $result = \FloCMS\Admin\Notifications\Notifications::capture(fn (): array => $pageForm->submit($input));
        if ($result['saved']) {
            if (!\FloCMS\Admin\Notifications\Notifications::sentDuringCapture()) {
                Session::setFlash($result['message'] ?? AdminLang::get('pages_api.saved'), 'success');
            }
            $this->redirect(Admin::url($this->page::getSlug(), ['filter' => $filters->active() ?: null]));
        }
        if (!isset($result['errors']['_form'])) { Session::setFlash(AdminLang::get('flash.fix_errors'), 'danger'); }
        return $this->render($filters, $table, $result['state'], $result['errors']);
    }

    private function saveColumns(PageTable $table): never
    {
        $visibility = $table->visibility();
        if (($this->request->input('_columns_reset') ?? '') === '1') {
            $visibility->reset();
        } else {
            $chosen = $this->request->input('columns', []);
            $visibility->save(array_values(array_filter(is_array($chosen) ? $chosen : [], 'is_string')));
        }
        $list = Admin::url($this->page::getSlug());
        $back = (string) ($this->request->input('_back') ?? '');
        $this->redirect($back === $list || str_starts_with($back, $list . '?') ? $back : $list);
    }

    private function afterAction(Action $action, mixed $result, string $back): never
    {
        if ($result instanceof \FloCMS\Core\Http\Response) {
            $result->send();
            exit;
        }
        if (!\FloCMS\Admin\Notifications\Notifications::sentDuringCapture()) {
            Session::setFlash(is_string($result) && trim($result) !== '' ? $result : AdminLang::get('pages_api.action_done', ['action' => strip_tags($action->getPageLabel())]), 'success');
        }
        \FloCMS\Admin\Activity\Activity::record('action', \FloCMS\Admin\PanelPages\CustomPages::ACTIVITY, $this->page::getSlug(), strip_tags($this->page::getLabel()),
            meta: ['action' => mb_substr(strip_tags($action->getPageLabel()), 0, 120)]);
        $this->redirect($back);
    }

    /** The widgets the person may see, with the page's filters. */
    private function widgets(array $filters): string
    {
        $widgets = [];
        foreach ($this->page->widgets() as $class) {
            if (!is_string($class) || !is_subclass_of($class, \FloCMS\Admin\Widgets\Widget::class) || !(new \ReflectionClass($class))->isInstantiable()) {
                throw new \LogicException('widgets() of ' . $this->page::class . ' must list widget classes (got ' . (is_string($class) ? $class : get_debug_type($class)) . ').');
            }
            $widget = new $class();
            $errors = $widget->configurationErrors();
            if ($errors !== []) { throw new \LogicException($class . ': ' . implode('; ', $errors)); }
            $widgets[] = $widget->usePageFilters($filters);
        }
        try { $columns = Admin::config()->widgetColumns(); } catch (\InvalidArgumentException) { $columns = 2; }
        return \FloCMS\Admin\Widgets\Dashboard::items($widgets, $columns, 'fa-page-widget-');
    }

    /** The page's own view (views/<name>.html), with data() and the filters. */
    private function view(array $filters): string
    {
        $name = $this->page->view();
        if ($name === null || $name === '') { return ''; }
        $path = self::viewFile($name) ?? throw new \LogicException('The view "' . $name . '" of ' . $this->page::class . ' was not found: expected views/' . $name . '.html');
        $data = $this->page->data($filters);
        if (!is_array($data)) { throw new \LogicException('data() of ' . $this->page::class . ' must return an array.'); }
        return TemplateEngine::renderFile($path, $data + ['filters' => $filters, 'page' => $this->page, 't' => PageView::translator()]);
    }

    /** The file of a page's view name ('admin/sales-report'), or null. */
    public static function viewFile(string $name): ?string
    {
        if (preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $name) !== 1) { return null; }
        $views = defined('VIEWS_PATH') ? (string) constant('VIEWS_PATH') : Admin::root() . DIRECTORY_SEPARATOR . 'views';
        $path = rtrim($views, '/\\') . '/' . $name . '.html';
        return is_file($path) ? $path : null;
    }

    /** The header's buttons: each opens its action's page, or runs at once (POST). */
    private function actions(PageFilters $filters): string
    {
        $html = '';
        $slug = $this->page::getSlug();
        $query = ['filter' => $filters->active() ?: null];
        foreach ($this->page->actions() as $action) {
            if (!$action instanceof Action || $action->getKind() !== 'custom') {
                throw new \LogicException('actions() of ' . $this->page::class . ' must return Action::make(...) objects.');
            }
            $name = $action->getName();
            $label = Str::e($action->getPageLabel());
            $icon = $action->getIcon() !== null ? Icons::svg($action->getIcon()) : '';
            $class = 'fa-btn' . ($action->getColor() === 'default' ? '' : ' fa-btn-' . $action->getColor());
            if (($reason = $action->reasonDisabledOnPage($this->page)) !== null) {
                $html .= '<button type="button" class="fa-btn" disabled title="' . Str::e($reason) . '" data-admin-action="' . Str::e($name) . '">' . $icon . '<span>' . $label . '</span></button>';
                continue;
            }
            if (!$action->allowedOnPage($this->page)) { continue; }
            if (($url = $action->getUrl(null)) !== null) {
                $html .= '<a class="' . $class . '" data-admin-action="' . Str::e($name) . '" href="' . Str::e($url) . '">' . $icon . '<span>' . $label . '</span></a>';
                continue;
            }
            $actionUrl = Admin::url($slug . '/action/' . $name, $query);
            if ($action->runsAtOnce()) {
                // No form and no confirmation: one POST, with the operation issued here.
                $token = OperationStore::issue('page:' . $slug, 'page', $name, '', '');
                $html .= '<form method="post" class="fa-inline" action="' . Str::e($actionUrl) . '">' . \FloCMS\Core\Csrf::field()
                    . '<input type="hidden" name="_operation" value="' . Str::e($token) . '"><button type="submit" class="' . $class . '" data-admin-action="' . Str::e($name) . '">' . $icon . '<span>' . $label . '</span></button></form>';
                continue;
            }
            $html .= '<a class="' . $class . '" data-admin-action="' . Str::e($name) . '" href="' . Str::e($actionUrl) . '">' . $icon . '<span>' . $label . '</span></a>';
        }
        return $html;
    }

    private function findAction(string $name): Action
    {
        foreach ($this->page->actions() as $action) {
            if ($action instanceof Action && $action->getName() === $name && $action->getKind() === 'custom') { return $action; }
        }
        throw new HttpException(404);
    }

    private function filters(): PageFilters
    {
        $input = $this->request->queryValue('filter', []);
        if (!is_array($input)) { throw new HttpException(400); }
        $filters = new PageFilters($this->page, $input);
        $this->page->useFilterValues($filters->values());
        return $filters;
    }

    private function authorizePage(): void
    {
        if (!$this->page->allowed()) { throw new HttpException(403); }
    }

    private function query(string $key, string $default = ''): string
    {
        $value = $this->request->queryValue($key, $default);
        return is_string($value) ? mb_substr(trim($value), 0, 200) : $default;
    }

    /**
     * Run one part of the page; a part that fails is logged and shown as
     * unavailable (null) instead of breaking the whole page.
     *
     * @template T
     * @param \Closure(): T $part
     * @return T|null
     */
    private function guard(\Closure $part): mixed
    {
        try {
            return $part();
        } catch (HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            Log::error('Admin page ' . $this->page::class . ': ' . $error->getMessage());
            if (\FloCMS\Core\Env::get('APP_DEBUG') === true) { throw $error; }
            return null;
        }
    }

    private function unavailable(): string
    {
        return '<p class="fa-card fa-blank" role="status">' . Str::e(AdminLang::get('pages_api.unavailable')) . '</p>';
    }
}
