<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormSubmission;
use FloCMS\Admin\Pages\PagePreview;
use FloCMS\Admin\Pages\Pages;
use FloCMS\Admin\Pages\PagesResource;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Translation\EditingLanguage;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\Auth;
use FloCMS\Core\HttpException;

/**
 * Preview in the page editor (0.30): POST /admin/page-preview[?page=<id>] with the
 * form as it is, saved or not. The values are kept for 30 minutes behind a
 * random link (/<lang>/flo-preview/<token>), which this answers with.
 */
final class PagePreviewController extends PanelController
{
    protected array $actionPermissions = ['admin_index' => null];

    public function admin_index(): never
    {
        if (!Pages::enabled()) { throw new HttpException(404); }
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $resource = new PagesResource();
        $page = $this->request->queryValue('page', '');
        $id = is_string($page) && ctype_digit($page) ? (int) $page : 0;
        if (!Auth::can(PagesResource::permission($id > 0 ? 'update' : 'create'))) { throw new HttpException(403); }
        $db = \FloCMS\Core\App::db() ?? throw new HttpException(503);
        $repository = new RecordRepository($resource, $db, \FloCMS\Admin\Translation\Translations::store());
        $record = $id > 0 ? $repository->find((string) $id) : null;
        if ($id > 0 && ($record === null || !$resource->canEdit($record))) { throw new HttpException(404); }
        $form = $resource->form(new Form());
        $submission = new FormSubmission($resource, $repository);
        $state = $submission->state($form, $this->request->all(), $record);
        $values = $submission->values($form, $state, $record);
        $row = (object) ($record !== null ? (array) $record : ['id' => 0, 'is_home' => 0, 'parent_id' => null]);
        foreach ($values as $column => $value) { $row->{$column} = $value; }
        $editing = EditingLanguage::resolve($this->request->input('_editing'), $record !== null);
        $lang = in_array($editing, Languages::all(), true) ? $editing : Languages::default();
        $this->redirect(PagePreview::url(PagePreview::store($row, $lang), $lang));
    }
}
