<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Media\MediaFiles;
use FloCMS\Admin\Media\MediaItem;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Media\MediaStore;
use FloCMS\Admin\Media\MediaUsage;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\Csrf;
use FloCMS\Core\HttpException;
use FloCMS\Core\Http\Response;
use FloCMS\Core\Session;
use FloCMS\Core\TemplateEngine;
use FloCMS\Core\ValidationException;

/**
 * /admin/media: the media library. A grid with search and filters, uploads
 * (several files; videos in parts), a details page per item (title, alt text
 * per language, where it is used, replace, delete), and the JSON the picker
 * and the rich editor ask (/admin/media/lookup).
 *
 * Permissions: <prefix>.media.view, .create (upload), .update (details, replace), .delete.
 */
final class MediaController extends PanelController
{
    public const PER_PAGE = 40;
    /** What the library takes. */
    public const ACCEPT = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm'];

    protected array $actionPermissions = [];

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->actionPermissions = [
            // Any action not listed below needs at least view.
            '*' => MediaLibrary::permission('view'),
            'admin_index' => MediaLibrary::permission('view'),
            'admin_lookup' => MediaLibrary::permission('view'),
            'admin_edit' => MediaLibrary::permission('view'),
            'admin_details' => MediaLibrary::permission('view'),
            'admin_upload' => MediaLibrary::permission('create'),
            'admin_video' => MediaLibrary::permission('create'),
            'admin_pending' => MediaLibrary::permission('create'),
            'admin_replace' => MediaLibrary::permission('update'),
            'admin_save' => MediaLibrary::permission('update'),
            'admin_move' => MediaLibrary::permission('update'),
            'admin_folders' => MediaLibrary::permission('update'),
            'admin_delete' => MediaLibrary::permission('delete'),
        ];
    }

    public function admin_index(): string|Response
    {
        $store = MediaLibrary::store();
        $folders = MediaLibrary::folders();
        $query = $this->query();
        $page = max(1, (int) $this->text('page', 6));
        $result = $store->search($query['q'], $query['kind'], $query['status'] === 'unused', $page, self::PER_PAGE, $this->filters($query));
        $counts = $store->usageCounts(array_map(static fn (MediaItem $item): int => $item->id, $result['items']));
        $items = array_map(fn (MediaItem $item): array => $item->toArray() + [
            'editUrl' => Admin::url('media/edit/' . $item->id), 'used' => $counts[$item->id] ?? 0,
            'size' => self::size($item->bytes), 'dims' => $item->width !== null && $item->height !== null ? "\u{2066}" . $item->width . "\u{00A0}\u{00D7}\u{00A0}" . $item->height . "\u{2069}" : '',
        ], $result['items']);
        $state = array_filter(['q' => $query['q'], 'kind' => $query['kind'], 'status' => $query['status'], 'sort' => $query['sort'] !== 'newest' ? $query['sort'] : null,
            'folder' => $query['folder'] !== null ? (string) $query['folder'] : null, 'scope' => $query['scope'] === 'folder' ? 'folder' : null], static fn ($v): bool => $v !== null && $v !== '');
        $tree = $folders->tree();
        $folderCounts = $folders->counts();
        $stats = $store->stats();
        $current = is_int($query['folder']) ? $folders->find($query['folder']) : null;

        $title = AdminLang::get('media.title');
        $html = TemplateEngine::renderFile(PageView::viewPath('media-index'), [
            't' => PageView::translator(), 'title' => $title, 'items' => $items, 'total' => $result['total'],
            'page' => $result['page'], 'pages' => $result['pages'], 'query' => $query, 'state' => $state,
            'tree' => $tree, 'folderCounts' => $folderCounts, 'currentFolder' => $current, 'folderLabel' => is_int($query['folder']) ? $folders->label($query['folder']) : '',
            'stats' => $stats + ['size' => self::size($stats['bytes'])],
            'canUpload' => MediaLibrary::can('create'), 'canDelete' => MediaLibrary::can('delete'), 'canUpdate' => MediaLibrary::can('update'),
            'listUrl' => Admin::url('media'), 'uploadUrl' => Admin::url('media/upload'), 'moveUrl' => Admin::url('media/move'),
            'foldersUrl' => Admin::url('media/folders'), 'deleteUrl' => Admin::url('media/delete'), 'csrf' => Csrf::field(),
            'pageNumbers' => PageView::pageNumbers($result['page'], $result['pages']),
            'pageUrl' => static fn (int $number): string => Admin::url('media', $state + ['page' => $number > 1 ? $number : null]),
            'folderUrl' => static fn (string|int|null $folder): string => Admin::url('media', array_filter(['folder' => $folder === null ? null : (string) $folder, 'sort' => $state['sort'] ?? null])),
            'currentUrl' => Admin::url('media', $state + ['page' => $page > 1 ? $page : null]),
            'hasFilters' => $query['q'] !== '' || $query['kind'] !== null || $query['status'] !== null,
            'clearUrl' => Admin::url('media', array_filter(['folder' => $state['folder'] ?? null])),
            'browser' => self::browserConfig($query),
            'upload' => (string) \FloCMS\Admin\Ui\FileDrop::make('files[]')->id('fa-media-files')->multiple()->large()->accept(self::ACCEPT)
                ->inputAttributes(['data-media-files' => true])->hint(AdminLang::get('media.limits', ['image' => self::size(MediaLibrary::maxImageBytes()), 'video' => self::size(MediaLibrary::maxVideoBytes())])),
            'searchIcon' => Icons::svg('search'), 'uploadIcon' => Icons::svg('upload'), 'videoIcon' => Icons::svg('video'),
            'folderIcon' => self::folderIcon(), 'allIcon' => Icons::svg('image'),
            'prevIcon' => Icons::svg('chevron-left', 'fa-icon fa-flip-rtl'), 'nextIcon' => Icons::svg('chevron-right', 'fa-icon fa-flip-rtl'),
        ]);
        return $this->panel($title, $html, 'media');
    }

    /**
     * JSON for the media browser (the Media page and the picker): one page of items,
     * and with tree=1 the folders, with stats=1 the header's numbers.
     */
    public function admin_lookup(): Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { throw new HttpException(405); }
        $store = MediaLibrary::store();
        $query = $this->query();
        $per = max(1, min(60, (int) ($this->text('per', 3) ?: 30)));
        $result = $store->search($query['q'], $query['kind'], $query['status'] === 'unused', max(1, (int) $this->text('page', 6)), $per, $this->filters($query));
        $counts = $store->usageCounts(array_map(static fn (MediaItem $item): int => $item->id, $result['items']));
        $data = [
            'items' => array_map(static fn (MediaItem $item): array => $item->toArray() + ['used' => $counts[$item->id] ?? 0], $result['items']),
            'page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'],
        ];
        if ($this->request->queryValue('tree', '') === '1') { $data['folders'] = self::folderData(); }
        if ($this->request->queryValue('stats', '') === '1') { $data['stats'] = $store->stats(); }
        return self::json($data);
    }

    /** JSON details of one item for the side panel: where it is used, who uploaded it, its folder. */
    public function admin_details(): Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { throw new HttpException(405); }
        $store = MediaLibrary::store();
        return self::json(['item' => $this->detailsData($store, $this->item($store))]);
    }

    /**
     * POST the details of one item (title, alt[lang], and when sent: folder, focal_x/focal_y).
     * JSON for the side panel and the batch editor; a plain form goes back to the details page.
     */
    public function admin_save(): string|Response
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $store = MediaLibrary::store();
        $item = $this->item($store);
        $form = self::form();
        $input = $this->request->all();
        $state = [];
        foreach ($form->fields() as $name => $field) { $state[$name] = $field->stateFromInput($input, Languages::all()); }
        $errors = (new \FloCMS\Admin\Validation\FormValidator(Languages::all(), Languages::default(), null, true))->validate($form, $state);
        $folder = null;
        if (array_key_exists('folder', $input)) {
            $folder = $this->folderParam($input['folder']);
            if ($folder === false) { $errors['folder'] = AdminLang::get('media.folder_missing'); }
        }
        if ($errors !== []) {
            if ($this->request->acceptsJson()) { return self::json(['errors' => $errors], 422); }
            Session::setFlash(implode(' ', $errors), 'danger');
            $this->redirect(Admin::url('media/edit/' . $item->id));
        }
        $store->connection()->transaction(function () use ($store, $item, $state, $input, $folder): void {
            $store->updateDetails($item->id, (string) $state['title'], is_array($state['alt']) ? $state['alt'] : []);
            if (array_key_exists('folder', $input)) { $store->moveToFolder([$item->id], $folder === false ? null : $folder); }
            if (array_key_exists('focal_x', $input) || array_key_exists('focal_y', $input)) {
                $x = $input['focal_x'] ?? ''; $y = $input['focal_y'] ?? '';
                $valid = static fn (mixed $v): bool => is_string($v) && preg_match('/^(100|[1-9]?[0-9])$/D', $v) === 1;
                $store->setFocal($item->id, $valid($x) && $valid($y) ? (int) $x : null, $valid($x) && $valid($y) ? (int) $y : null);
            }
        });
        if ($this->request->acceptsJson()) {
            return self::json(['item' => $this->detailsData($store, $store->find($item->id) ?? $item), 'message' => AdminLang::get('media.saved')]);
        }
        Session::setFlash(AdminLang::get('media.saved'), 'success');
        $this->redirect(Admin::url('media/edit/' . $item->id));
    }

    /** POST ids[] and folder (an id, or empty for Unfiled): move items. */
    public function admin_move(): string|Response
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $ids = MediaStore::ids(is_array($this->request->input('ids')) ? array_values($this->request->input('ids')) : []);
        if ($ids === [] || count($ids) > 500) { throw new HttpException(400); }
        $folder = $this->folderParam($this->request->input('folder'));
        if ($folder === false) {
            if ($this->request->acceptsJson()) { return self::json(['errors' => ['folder' => AdminLang::get('media.folder_missing')]], 422); }
            Session::setFlash(AdminLang::get('media.folder_missing'), 'danger');
            $this->redirect($this->back());
        }
        $moved = MediaLibrary::store()->moveToFolder($ids, $folder);
        $label = $folder === null ? AdminLang::get('media.unfiled') : MediaLibrary::folders()->label($folder);
        $message = AdminLang::get('media.moved_to_folder', ['count' => (string) $moved, 'folder' => $label]);
        if ($this->request->acceptsJson()) { return self::json(['moved' => $moved, 'message' => $message, 'folders' => self::folderData()]); }
        Session::setFlash($message, 'success');
        $this->redirect($this->back());
    }

    /** POST /admin/media/folders/<create|rename|move|delete>[/<id>] with name and parent. */
    public function admin_folders(): string|Response
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $folders = MediaLibrary::folders();
        $step = (string) ($this->params[0] ?? '');
        $id = isset($this->params[1]) && ctype_digit((string) $this->params[1]) ? (int) $this->params[1] : null;
        $name = $this->request->input('name');
        $name = is_string($name) ? $name : '';
        $parent = $this->folderParam($this->request->input('parent'));
        $target = null;
        // ValidationException lives in Validator.php, which the autoloader finds only by that name.
        class_exists(\FloCMS\Core\Validator::class);
        try {
            if ($parent === false) { throw new ValidationException(['parent' => AdminLang::get('media.folder_missing')]); }
            switch ($step) {
                case 'create':
                    $target = $folders->create($name, $parent);
                    $message = AdminLang::get('media.folder_created', ['name' => $folders->label($target)]);
                    break;
                case 'rename':
                    if ($id === null) { throw new HttpException(404); }
                    $folders->rename($id, $name);
                    $target = $id;
                    $message = AdminLang::get('media.folder_renamed');
                    break;
                case 'move':
                    if ($id === null) { throw new HttpException(404); }
                    $folders->move($id, $parent);
                    $target = $id;
                    $message = AdminLang::get('media.folder_moved', ['name' => $folders->label($id)]);
                    break;
                case 'delete':
                    if ($id === null) { throw new HttpException(404); }
                    $parentOf = $folders->find($id)['parent'] ?? null;
                    $folders->delete($id);
                    $target = $parentOf;
                    $message = AdminLang::get('media.folder_deleted');
                    break;
                default:
                    throw new HttpException(404);
            }
        } catch (ValidationException $error) {
            $errors = array_map(static fn ($m): string => implode(' ', (array) $m), $error->getErrors());
            if ($this->request->acceptsJson()) { return self::json(['errors' => $errors], 422); }
            Session::setFlash(implode(' ', $errors), 'danger');
            $this->redirect($this->back());
        }
        if ($this->request->acceptsJson()) { return self::json(['id' => $target, 'message' => $message, 'folders' => self::folderData()]); }
        Session::setFlash($message, 'success');
        $this->redirect(Admin::url('media', $target !== null ? ['folder' => (string) $target] : []));
    }

    /**
     * POST files[] (or file): images, and videos that fit PHP's upload limit
     * (the page's script sends larger videos in parts through admin_video).
     *
     * folder: where new items go. duplicates (0.18), for a file whose content is already
     * in the library: 'ask' (JSON default: held, answered in "duplicates" with a token
     * for admin_pending), 'use' (answer the existing item; fields and the editor) or
     * 'keep' (add it anyway; forms without JavaScript).
     */
    public function admin_upload(): string|Response
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $files = self::files($_FILES['files'] ?? $_FILES['file'] ?? null);
        if ($files === []) { return $this->uploaded([], [AdminLang::get('media.no_file')]); }
        if (count($files) > 50) { throw new HttpException(413); }
        $folder = $this->uploadFolder();
        if ($folder === false) { return $this->uploaded([], [AdminLang::get('media.folder_missing')]); }
        $mode = $this->duplicateMode();
        $items = [];
        $errors = [];
        $duplicates = [];
        foreach ($files as $file) {
            try {
                $mime = self::mime($file);
                $data = str_starts_with($mime, 'video/') ? $this->storeVideo($file) : MediaFiles::image($file, MediaLibrary::maxImageBytes());
                $result = $this->added($data, $folder, $mode);
                if (isset($result['duplicate'])) { $duplicates[] = $result['duplicate']; } else { $items[] = $result['item']; }
            } catch (\FloCMS\Uploader\Exceptions\ValidationException $error) {
                $errors[] = self::fileName($file) . ': ' . $error->getMessage();
            }
        }
        return $this->uploaded($items, $errors, $duplicates);
    }

    /** POST token and action keep|discard: the answer to a duplicate held by an upload. */
    public function admin_pending(): Response
    {
        if (!$this->isPost()) { throw new HttpException(405); }
        $token = $this->request->input('token');
        $action = $this->request->input('action');
        if (!is_string($token) || !in_array($action, ['keep', 'discard'], true)) { throw new HttpException(400); }
        if ($action === 'discard') {
            return self::json(['ok' => \FloCMS\Admin\Media\MediaPending::discard($token)]);
        }
        $pending = \FloCMS\Admin\Media\MediaPending::take($token) ?? throw new HttpException(404);
        $folder = is_int($pending['folder']) && MediaLibrary::folders()->find($pending['folder']) !== null ? $pending['folder'] : null;
        $item = MediaLibrary::add($pending['file'], self::userId(), '', $folder);
        return self::json(['item' => $item->toArray() + ['used' => 0, 'editUrl' => Admin::url('media/edit/' . $item->id)]]);
    }

    /** Videos in parts: POST /admin/media/video/<start|chunk|complete|abort>. */
    public function admin_video(): Response
    {
        if (!$this->isPost()) { throw new HttpException(405); }
        $step = (string) ($this->params[0] ?? '');
        $chunks = MediaFiles::chunks(MediaLibrary::maxVideoBytes());
        $id = $this->request->input('upload_id');
        $json = static fn (array $data, int $status = 200): Response => Response::json($data, $status)->header('Cache-Control', 'no-store');
        try {
            switch ($step) {
                case 'start':
                    $name = $this->request->input('name');
                    $bytes = $this->request->input('bytes');
                    if (!is_string($name) || !is_scalar($bytes) || !ctype_digit((string) $bytes)) { throw new HttpException(400); }
                    $status = $chunks->start($name, (int) $bytes, MediaFiles::owner());
                    Session::set('media_upload_' . $status['upload_id'], mb_substr($name, 0, 190));
                    return $json(['upload_id' => $status['upload_id'], 'chunk_size' => MediaFiles::CHUNK_BYTES, 'received' => $status['received_bytes']]);
                case 'chunk':
                    $offset = $this->request->input('offset');
                    if (!is_string($id) || !is_scalar($offset) || !ctype_digit((string) $offset)) { throw new HttpException(400); }
                    $chunk = $_FILES['chunk'] ?? null;
                    if (!is_array($chunk)) { throw new HttpException(400); }
                    $status = $chunks->append($id, (int) $offset, $chunk, MediaFiles::owner());
                    return $json(['received' => $status['received_bytes'], 'complete' => $status['complete']]);
                case 'complete':
                    if (!is_string($id)) { throw new HttpException(400); }
                    $result = $chunks->complete($id, MediaFiles::owner());
                    $name = (string) Session::get('media_upload_' . $id, '');
                    Session::delete('media_upload_' . $id);
                    $folder = $this->uploadFolder();
                    $added = $this->added(MediaFiles::video($result, $name), $folder === false ? null : $folder, $this->duplicateMode());
                    return $json(isset($added['duplicate']) ? ['duplicate' => $added['duplicate']] : ['item' => $added['item']]);
                case 'abort':
                    if (!is_string($id)) { throw new HttpException(400); }
                    $chunks->abort($id, MediaFiles::owner());
                    return $json(['ok' => true]);
            }
        } catch (\FloCMS\Uploader\Exceptions\ChunkOffsetException $error) {
            // A part arrived twice or was lost: the browser goes on from what the server has.
            return $json(['error' => 'offset', 'received' => $error->expectedOffset()], 409);
        } catch (\FloCMS\Uploader\Exceptions\ValidationException $error) {
            return $json(['error' => $error->getMessage()], 422);
        } catch (\FloCMS\Uploader\Exceptions\UploadException) {
            // Storage failed (a full disk, a folder that can't be written): a JSON answer, not an error page.
            return $json(['error' => AdminLang::get('media.failed')], 500);
        }
        throw new HttpException(404);
    }

    /** Details of one item: preview, title and alt text, where it is used, replace and delete. */
    public function admin_edit(): string|Response
    {
        $store = MediaLibrary::store();
        $item = $this->item($store);
        $form = self::form();
        $errors = [];
        if ($this->isPost()) {
            if (!MediaLibrary::can('update')) { throw new HttpException(403); }
            $state = [];
            foreach ($form->fields() as $name => $field) { $state[$name] = $field->stateFromInput($this->request->all(), Languages::all()); }
            $errors = (new \FloCMS\Admin\Validation\FormValidator(Languages::all(), Languages::default(), null, true))->validate($form, $state);
            if ($errors === []) {
                $store->updateDetails($item->id, (string) $state['title'], is_array($state['alt']) ? $state['alt'] : []);
                Session::setFlash(AdminLang::get('media.saved'), 'success');
                $this->redirect(Admin::url('media/edit/' . $item->id));
            }
            Session::setFlash(AdminLang::get('flash.fix_errors'), 'danger');
        } else {
            $state = ['title' => $item->title, 'alt' => $item->altValues() + array_fill_keys(Languages::all(), '')];
        }
        return $this->detailsPage($item, $form, $state, $errors);
    }

    /** POST a new file into an item: same id, title, alt text and picker usages (JSON for the side panel). */
    public function admin_replace(): string|Response
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $store = MediaLibrary::store();
        $item = $this->item($store);
        $json = $this->request->acceptsJson();
        $refuse = function (string $message) use ($json, $item): Response {
            if ($json) { return self::json(['error' => $message], 422); }
            Session::setFlash($message, 'danger');
            $this->redirect(Admin::url('media/edit/' . $item->id));
        };
        $files = self::files($_FILES['file'] ?? null);
        if (count($files) !== 1) { return $refuse(AdminLang::get('media.no_file')); }
        if ($store->usedInText($item->id) !== []) { return $refuse(AdminLang::get('media.replace_in_text')); }
        try {
            $file = $item->isImage() ? MediaFiles::image($files[0], MediaLibrary::maxImageBytes()) : $this->storeVideo($files[0]);
        } catch (\FloCMS\Uploader\Exceptions\ValidationException $error) {
            return $refuse($error->getMessage());
        }
        try {
            $old = $store->connection()->transaction(fn (): array => $store->replace($item->id, $file));
        } catch (\Throwable $error) {
            MediaFiles::delete(array_values(array_filter([$file['path'], $file['thumb_path']], 'is_string')));
            if (!$error instanceof ValidationException) { throw $error; }
            return $refuse(implode(' ', array_map(static fn ($m): string => implode(' ', (array) $m), $error->getErrors())));
        }
        MediaFiles::delete($old);
        if ($json) { return self::json(['item' => $this->detailsData($store, $store->find($item->id) ?? $item), 'message' => AdminLang::get('media.replaced')]); }
        Session::setFlash(AdminLang::get('media.replaced'), 'success');
        $this->redirect(Admin::url('media/edit/' . $item->id));
    }

    /** POST: delete one item (/media/delete/<id>) or the chosen ones (ids[]); items in use are kept. */
    public function admin_delete(): string|Response
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $store = MediaLibrary::store();
        $ids = isset($this->params[0]) ? MediaStore::ids([(string) $this->params[0]]) : MediaStore::ids(is_array($this->request->input('ids')) ? array_values($this->request->input('ids')) : []);
        if ($ids === [] || count($ids) > 200) { throw new HttpException(400); }
        $deleted = 0;
        $kept = 0;
        foreach ($ids as $id) {
            try {
                $files = $store->connection()->transaction(fn (): array => $store->delete($id));
                MediaFiles::delete($files);
                $deleted++;
            } catch (ValidationException) {
                // Already gone (another editor deleted it): nothing to keep or report as used.
                if ($store->find($id) !== null) { $kept++; }
            }
        }
        // One message (the session keeps one): what was deleted, and what was kept because it is used.
        $messages = array_filter([$deleted > 0 ? AdminLang::get('media.deleted', ['count' => (string) $deleted]) : null, $kept > 0 ? AdminLang::get('media.kept_in_use', ['count' => (string) $kept]) : null]);
        if ($this->request->acceptsJson()) {
            return self::json(['deleted' => $deleted, 'kept' => $kept, 'message' => $messages !== [] ? implode(' ', $messages) : AdminLang::get('media.missing'), 'folders' => self::folderData()]);
        }
        if ($deleted === 0 && $kept === 0) { Session::setFlash(AdminLang::get('media.missing'), 'warning'); $this->redirect(Admin::url('media')); }
        Session::setFlash(implode(' ', $messages), $kept === 0 ? 'success' : ($deleted > 0 ? 'warning' : 'danger'));
        $back = (string) ($this->request->input('_back') ?? '');
        $list = Admin::url('media');
        $this->redirect($back === $list || str_starts_with($back, $list . '?') ? $back : (isset($this->params[0]) && $kept > 0 ? Admin::url('media/edit/' . $ids[0]) : $list));
    }

    private function detailsPage(MediaItem $item, Form $form, array $state, array $errors): string|Response
    {
        $store = MediaLibrary::store();
        $usage = array_map(static fn (array $row): array => MediaUsage::describe($row), $store->usage($item->id));
        $inText = $store->usedInText($item->id) !== [];
        $canUpdate = MediaLibrary::can('update');
        $title = $item->label();
        $html = TemplateEngine::renderFile(PageView::viewPath('media-edit'), [
            't' => PageView::translator(), 'title' => $title, 'item' => $item, 'listUrl' => Admin::url('media'),
            'action' => Admin::url('media/edit/' . $item->id), 'replaceUrl' => Admin::url('media/replace/' . $item->id),
            'deleteUrl' => Admin::url('media/delete/' . $item->id), 'csrf' => Csrf::field(),
            'fields' => (new FormRenderer($state, $errors, Languages::all(), Languages::default(), true))->render($form),
            'canUpdate' => $canUpdate, 'canDelete' => MediaLibrary::can('delete'), 'usage' => $usage, 'inText' => $inText,
            'size' => self::size($item->bytes), 'uploader' => self::userName($item->uploadedBy), 'absoluteUrl' => self::absolute($item->url()),
            'saveUrl' => Admin::url('media/save/' . $item->id), 'tree' => MediaLibrary::folders()->tree(),
            'replaceDrop' => (string) \FloCMS\Admin\Ui\FileDrop::make('file')->id('fa-media-replace')
                ->accept($item->isImage() ? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'] : ['video/mp4', 'video/webm'])
                ->maxBytes($item->isImage() ? MediaLibrary::maxImageBytes() : MediaLibrary::maxVideoBytes())->inputAttributes(['required' => true]),
            'videoIcon' => Icons::svg('video'), 'hasErrors' => $errors !== [],
        ]);
        return $this->panel($title, $html, 'media');
    }

    private static function form(): Form
    {
        return (new Form())->schema([
            TextInput::make('title')->label(AdminLang::get('media.item_title'))->rules('max:190')->help(AdminLang::get('media.title_help')),
            TextInput::make('alt')->label(AdminLang::get('media.alt'))->translatable()->rules('max:250')->help(AdminLang::get('media.alt_help')),
        ]);
    }

    /**
     * A stored file becomes an item, unless its content is already in the library:
     * then it is held ('ask'), dropped for the existing item ('use') or added anyway ('keep').
     *
     * @param array<string, mixed> $data From MediaFiles::image() or video()
     * @return array{item?: array<string, mixed>, duplicate?: array<string, mixed>}
     */
    private function added(array $data, ?int $folder, string $mode): array
    {
        $existing = $mode !== 'keep' && is_string($data['hash'] ?? null) ? MediaLibrary::store()->findByHash($data['hash']) : null;
        if ($existing !== null && $existing->kind === $data['kind']) {
            $existingData = $existing->toArray() + ['used' => 0, 'editUrl' => Admin::url('media/edit/' . $existing->id)];
            if ($mode === 'use') {
                MediaFiles::delete(array_values(array_filter([$data['path'] ?? null, $data['thumb_path'] ?? null], 'is_string')));
                return ['item' => $existingData + ['reused' => true]];
            }
            $token = \FloCMS\Admin\Media\MediaPending::hold($data, $folder);
            return ['duplicate' => ['token' => $token, 'name' => (string) ($data['file_name'] ?? ''), 'existing' => $existingData,
                'message' => AdminLang::get('media.duplicate', ['name' => (string) ($data['file_name'] ?? ''), 'existing' => $existing->label()])]];
        }
        $item = MediaLibrary::add($data, self::userId(), '', $folder);
        return ['item' => $item->toArray() + ['used' => 0, 'editUrl' => Admin::url('media/edit/' . $item->id)]];
    }

    /** Where an upload goes: folder (an id), or folder_path (a field's ->folder(), made when missing). */
    private function uploadFolder(): int|false|null
    {
        $folder = $this->folderParam($this->request->input('folder'));
        $path = $this->request->input('folder_path');
        if ($folder !== null || !is_string($path) || trim($path) === '') { return $folder; }
        try {
            return MediaLibrary::folders()->ensure(mb_substr($path, 0, 255));
        } catch (ValidationException) {
            return null;
        }
    }

    private function duplicateMode(): string
    {
        $mode = $this->request->input('duplicates');
        if (in_array($mode, ['ask', 'use', 'keep'], true)) { return $mode; }
        // A plain form can't ask: the file is added, and the flash says so.
        return $this->request->acceptsJson() ? 'ask' : 'keep';
    }

    /** A video sent in one request (no JavaScript, or a small file). */
    private function storeVideo(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \FloCMS\Uploader\Exceptions\ValidationException(AdminLang::get('media.not_arrived'));
        }
        $result = \FloCMS\Uploader\Uploader::video(MediaFiles::config())->onDisk('media')->directory(MediaFiles::DIRECTORY)->useDatePath()
            ->maxBytes(MediaLibrary::maxVideoBytes())->upload($file);
        return MediaFiles::video($result, (string) ($file['name'] ?? ''));
    }

    /** @param list<array<string, mixed>> $duplicates */
    private function uploaded(array $items, array $errors, array $duplicates = []): string|Response
    {
        foreach ($items as $index => $item) { $items[$index]['editUrl'] = Admin::url('media/edit/' . $item['id']); }
        if ($this->request->acceptsJson()) {
            return Response::json(['items' => $items, 'errors' => $errors, 'duplicates' => $duplicates], $items === [] && $duplicates === [] && $errors !== [] ? 422 : 200)->header('Cache-Control', 'no-store');
        }
        $messages = array_merge($items !== [] ? [AdminLang::get('media.uploaded_count', ['count' => (string) count($items)])] : [], $errors);
        Session::setFlash(implode(' ', $messages), $errors === [] ? 'success' : ($items !== [] ? 'warning' : 'danger'));
        $this->redirect($this->back());
    }

    /**
     * The browser's query from the address: q, kind, status (unused | no_alt), sort,
     * folder (an id or 'unfiled'), scope ('folder': a search stays in the folder).
     *
     * @return array{q: string, kind: ?string, status: ?string, sort: string, folder: int|string|null, scope: string}
     */
    private function query(): array
    {
        $status = (string) $this->request->queryValue('status', '');
        if ($this->request->queryValue('unused', '') === '1') { $status = 'unused'; }
        $sort = (string) $this->request->queryValue('sort', '');
        $folder = (string) $this->request->queryValue('folder', '');
        $folderValue = null;
        if ($folder === 'unfiled') {
            $folderValue = 'unfiled';
        } elseif (ctype_digit($folder) && MediaLibrary::folders()->find((int) $folder) !== null) {
            $folderValue = (int) $folder;
        }
        return [
            'q' => $this->text('q', 100),
            'kind' => $this->kind((string) $this->request->queryValue('kind', '')),
            'status' => in_array($status, ['unused', 'no_alt'], true) ? $status : null,
            'sort' => in_array($sort, MediaStore::SORTS, true) ? $sort : 'newest',
            'folder' => $folderValue,
            'scope' => $this->request->queryValue('scope', '') === 'folder' ? 'folder' : 'all',
        ];
    }

    /**
     * Store filters for a query: a search looks everywhere unless scope is 'folder'.
     *
     * @param array{q: string, kind: ?string, status: ?string, sort: string, folder: int|string|null, scope: string} $query
     * @return array{folder?: int|string|null, no_alt?: bool, sort?: string}
     */
    private function filters(array $query): array
    {
        $folder = $query['q'] !== '' && $query['scope'] !== 'folder' ? null : $query['folder'];
        return ['folder' => $folder, 'no_alt' => $query['status'] === 'no_alt', 'sort' => $query['sort']];
    }

    /** A posted folder: null for none/Unfiled, the id when it exists, false when it does not. */
    private function folderParam(mixed $value): int|false|null
    {
        if ($value === null || $value === '' || $value === 'unfiled') { return null; }
        if (!is_string($value) || !ctype_digit($value)) { return false; }
        return MediaLibrary::folders()->find((int) $value) !== null ? (int) $value : false;
    }

    /** The page to go back to after a plain form: a Media page address only. */
    private function back(): string
    {
        $back = (string) ($this->request->input('_back') ?? '');
        $list = Admin::url('media');
        return $back === $list || str_starts_with($back, $list . '?') ? $back : $list;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status)->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    /** @return array{tree: list<array<string, mixed>>, counts: object} */
    private static function folderData(): array
    {
        $folders = MediaLibrary::folders();
        return ['tree' => $folders->tree(), 'counts' => (object) $folders->counts()];
    }

    /** @return array<string, mixed> The side panel's view of an item. */
    private function detailsData(MediaStore $store, MediaItem $item): array
    {
        $usage = array_map(static fn (array $row): array => MediaUsage::describe($row), $store->usage($item->id));
        $inText = $store->usedInText($item->id) !== [];
        return $item->toArray() + [
            'used' => count($usage), 'usage' => $usage, 'inText' => $inText,
            'folderLabel' => MediaLibrary::folders()->label($item->folderId),
            'uploader' => self::userName($item->uploadedBy), 'size' => self::size($item->bytes),
            'dims' => $item->width !== null && $item->height !== null ? "\u{2066}" . $item->width . "\u{00A0}\u{00D7}\u{00A0}" . $item->height . "\u{2069}" : '',
            'date' => $item->createdAt !== null ? substr($item->createdAt, 0, 10) : '',
            'absoluteUrl' => self::absolute($item->url()), 'editUrl' => Admin::url('media/edit/' . $item->id),
            'saveUrl' => Admin::url('media/save/' . $item->id), 'replaceUrl' => Admin::url('media/replace/' . $item->id),
            'canReplace' => MediaLibrary::can('update') && !$inText,
            'canDelete' => MediaLibrary::can('delete') && $usage === [],
        ];
    }

    /**
     * What the Media page's browser starts from (its folders and texts come from the panel's
     * media settings, data-admin-media).
     *
     * @param array{q: string, kind: ?string, status: ?string, sort: string, folder: int|string|null, scope: string} $query
     */
    private static function browserConfig(array $query): string
    {
        return (string) json_encode(['query' => $query, 'perPage' => self::PER_PAGE], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function folderIcon(): string
    {
        return '<svg class="fa-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
    }

    private function item(MediaStore $store): MediaItem
    {
        $ids = MediaStore::ids([(string) ($this->params[0] ?? '')]);
        return ($ids !== [] ? $store->find($ids[0]) : null) ?? throw new HttpException(404);
    }

    private function text(string $key, int $max): string
    {
        $value = $this->request->queryValue($key, '');
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    private function kind(string $kind): ?string
    {
        return in_array($kind, MediaStore::KINDS, true) ? $kind : null;
    }

    /**
     * $_FILES entries as a list (files[] or a single file).
     *
     * @return list<array<string, mixed>>
     */
    private static function files(mixed $entry): array
    {
        if (!is_array($entry) || !isset($entry['name'])) { return []; }
        if (!is_array($entry['name'])) { return ((int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE)) === UPLOAD_ERR_NO_FILE ? [] : [$entry]; }
        $files = [];
        foreach (array_keys($entry['name']) as $index) {
            $file = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) { $file[$key] = $entry[$key][$index] ?? null; }
            if (is_array($file['name']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) { continue; }
            $files[] = $file;
        }
        return $files;
    }

    private static function mime(array $file): string
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) { return ''; }
        return (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '');
    }

    private static function fileName(array $file): string
    {
        return mb_substr(basename(str_replace('\\', '/', (string) ($file['name'] ?? ''))), 0, 100);
    }

    private static function userId(): ?int
    {
        $id = Session::get('user_id');
        return is_numeric($id) ? (int) $id : null;
    }

    private static function userName(?int $id): ?string
    {
        if ($id === null) { return null; }
        try {
            $row = MediaLibrary::store()->connection()->table('users')->where('id', '=', $id)->first();
            $name = trim((string) ($row->full_name ?? ''));
            return $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function absolute(string $url): string
    {
        if (preg_match('~^https?://~i', $url) === 1) { return $url; }
        $base = defined('SITE_URI') ? (string) constant('SITE_URI') : '';
        $origin = preg_match('~^https?://[^/]+~i', $base, $match) === 1 ? $match[0] : '';
        return $origin . $url;
    }

    /** "1.5 MB", kept in one left-to-right piece (LRI … PDI) so Arabic and Kurdish text doesn't show "MB 1.5". */
    public static function size(int $bytes): string
    {
        return \FloCMS\Admin\Ui\FileDrop::size($bytes);
    }
}
