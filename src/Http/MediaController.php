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
            'admin_upload' => MediaLibrary::permission('create'),
            'admin_video' => MediaLibrary::permission('create'),
            'admin_replace' => MediaLibrary::permission('update'),
            'admin_delete' => MediaLibrary::permission('delete'),
        ];
    }

    public function admin_index(): string|Response
    {
        $store = MediaLibrary::store();
        $term = $this->text('q', 100);
        $kind = $this->kind((string) $this->request->queryValue('kind', ''));
        $unused = $this->request->queryValue('unused', '') === '1';
        $page = max(1, (int) $this->text('page', 6));
        $result = $store->search($term, $kind, $unused, $page, self::PER_PAGE);
        $counts = $store->usageCounts(array_map(static fn (MediaItem $item): int => $item->id, $result['items']));
        $state = ['q' => $term !== '' ? $term : null, 'kind' => $kind, 'unused' => $unused ? '1' : null];
        $items = array_map(fn (MediaItem $item): array => $item->toArray() + [
            'editUrl' => Admin::url('media/edit/' . $item->id), 'used' => ($counts[$item->id] ?? 0) > 0,
        ], $result['items']);

        $title = AdminLang::get('media.title');
        $html = TemplateEngine::renderFile(PageView::viewPath('media-index'), [
            't' => PageView::translator(), 'title' => $title, 'items' => $items, 'total' => $result['total'],
            'page' => $result['page'], 'pages' => $result['pages'], 'term' => $term, 'kind' => $kind, 'unused' => $unused,
            'canUpload' => MediaLibrary::can('create'), 'canDelete' => MediaLibrary::can('delete'),
            'listUrl' => Admin::url('media'), 'uploadUrl' => Admin::url('media/upload'), 'videoUrl' => Admin::url('media/video'),
            'deleteUrl' => Admin::url('media/delete'), 'csrf' => Csrf::field(), 'csrfToken' => Csrf::token(),
            'pageNumbers' => PageView::pageNumbers($result['page'], $result['pages']),
            'pageUrl' => static fn (int $number): string => Admin::url('media', $state + ['page' => $number > 1 ? $number : null]),
            'hasFilters' => $term !== '' || $kind !== null || $unused,
            'accept' => 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm',
            'limits' => AdminLang::get('media.limits', ['image' => self::size(MediaLibrary::maxImageBytes()), 'video' => self::size(MediaLibrary::maxVideoBytes())]),
            'maxImage' => MediaLibrary::maxImageBytes(), 'maxVideo' => MediaLibrary::maxVideoBytes(), 'chunk' => MediaFiles::CHUNK_BYTES,
            'searchIcon' => Icons::svg('search'), 'uploadIcon' => Icons::svg('upload'), 'videoIcon' => Icons::svg('video'), 'removeIcon' => Icons::svg('x'),
            'prevIcon' => Icons::svg('chevron-left', 'fa-icon fa-flip-rtl'), 'nextIcon' => Icons::svg('chevron-right', 'fa-icon fa-flip-rtl'),
        ]);
        return $this->panel($title, $html, 'media');
    }

    /** JSON for the picker: one page of items (images, videos or both), newest first. */
    public function admin_lookup(): Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { throw new HttpException(405); }
        $store = MediaLibrary::store();
        $result = $store->search($this->text('q', 100), $this->kind((string) $this->request->queryValue('kind', '')), false, max(1, (int) $this->text('page', 6)), 30);
        return Response::json([
            'items' => array_map(static fn (MediaItem $item): array => $item->toArray(), $result['items']),
            'page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'],
        ])->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * POST files[] (or file): images, and videos that fit PHP's upload limit
     * (the page's script sends larger videos in parts through admin_video).
     */
    public function admin_upload(): string|Response
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $files = self::files($_FILES['files'] ?? $_FILES['file'] ?? null);
        if ($files === []) { return $this->uploaded([], [AdminLang::get('media.no_file')]); }
        if (count($files) > 50) { throw new HttpException(413); }
        $items = [];
        $errors = [];
        foreach ($files as $file) {
            try {
                $items[] = $this->storeFile($file);
            } catch (\FloCMS\Uploader\Exceptions\ValidationException $error) {
                $errors[] = self::fileName($file) . ': ' . $error->getMessage();
            }
        }
        return $this->uploaded($items, $errors);
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
                    $item = MediaLibrary::add(MediaFiles::video($result, $name), self::userId());
                    return $json(['item' => $item->toArray() + ['editUrl' => Admin::url('media/edit/' . $item->id)]]);
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

    /** POST a new file into an item: same id, title, alt text and picker usages. */
    public function admin_replace(): string|Response
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $store = MediaLibrary::store();
        $item = $this->item($store);
        $files = self::files($_FILES['file'] ?? null);
        if (count($files) !== 1) {
            Session::setFlash(AdminLang::get('media.no_file'), 'danger');
            $this->redirect(Admin::url('media/edit/' . $item->id));
        }
        if ($store->usedInText($item->id) !== []) {
            Session::setFlash(AdminLang::get('media.replace_in_text'), 'danger');
            $this->redirect(Admin::url('media/edit/' . $item->id));
        }
        try {
            $file = $item->isImage() ? MediaFiles::image($files[0], MediaLibrary::maxImageBytes()) : $this->storeVideo($files[0]);
        } catch (\FloCMS\Uploader\Exceptions\ValidationException $error) {
            Session::setFlash($error->getMessage(), 'danger');
            $this->redirect(Admin::url('media/edit/' . $item->id));
        }
        try {
            $old = $store->connection()->transaction(fn (): array => $store->replace($item->id, $file));
        } catch (\Throwable $error) {
            MediaFiles::delete(array_filter([$file['path'], $file['thumb_path']]));
            if (!$error instanceof ValidationException) { throw $error; }
            Session::setFlash(implode(' ', array_map(static fn ($m): string => implode(' ', (array) $m), $error->getErrors())), 'danger');
            $this->redirect(Admin::url('media/edit/' . $item->id));
        }
        MediaFiles::delete($old);
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
        if ($deleted === 0 && $kept === 0) { Session::setFlash(AdminLang::get('media.missing'), 'warning'); $this->redirect(Admin::url('media')); }
        // One message (the session keeps one): what was deleted, and what was kept because it is used.
        $messages = array_filter([$deleted > 0 ? AdminLang::get('media.deleted', ['count' => (string) $deleted]) : null, $kept > 0 ? AdminLang::get('media.kept_in_use', ['count' => (string) $kept]) : null]);
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
            'accept' => $item->isImage() ? 'image/jpeg,image/png,image/gif,image/webp' : 'video/mp4,video/webm',
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

    /** Store an uploaded image or video as a new item. @return array<string, mixed> The item for scripts */
    private function storeFile(array $file): array
    {
        $mime = self::mime($file);
        $data = str_starts_with($mime, 'video/') ? $this->storeVideo($file) : MediaFiles::image($file, MediaLibrary::maxImageBytes());
        return MediaLibrary::add($data, self::userId())->toArray();
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

    private function uploaded(array $items, array $errors): string|Response
    {
        foreach ($items as $index => $item) { $items[$index]['editUrl'] = Admin::url('media/edit/' . $item['id']); }
        if ($this->request->acceptsJson()) {
            return Response::json(['items' => $items, 'errors' => $errors], $items === [] && $errors !== [] ? 422 : 200)->header('Cache-Control', 'no-store');
        }
        $messages = array_merge($items !== [] ? [AdminLang::get('media.uploaded_count', ['count' => (string) count($items)])] : [], $errors);
        Session::setFlash(implode(' ', $messages), $errors === [] ? 'success' : ($items !== [] ? 'warning' : 'danger'));
        $this->redirect(Admin::url('media'));
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
        $text = match (true) {
            $bytes >= 1048576 => rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . "\u{00A0}MB",
            $bytes >= 1024 => max(1, (int) round($bytes / 1024)) . "\u{00A0}KB",
            default => $bytes . "\u{00A0}B",
        };
        return "\u{2066}" . $text . "\u{2069}";
    }
}
