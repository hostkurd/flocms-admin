<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Trash\Trash;
use FloCMS\Core\HttpException;

/**
 * Site → Trash (/admin/trash): every record deleted in the panel that the user
 * may restore or delete for good, and POST /admin/trash/undo/<batch>, the Undo
 * button of the toast after a delete.
 *
 * Only the admin login gate is checked by FloCMS; each action checks the
 * resource permissions of the records it touches (see TrashPage).
 */
final class TrashController extends PanelController
{
    protected array $actionPermissions = ['admin_index' => null, 'admin_undo' => null];

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        if (!Trash::enabled()) { throw new HttpException(404); }
        if (TrashPage::allowedResources() === []) { throw new HttpException(403); }
        $page = new TrashPage();
        if ($this->isPost()) {
            $this->redirect($page->handle($this->request->all()));
        }
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET, POST'); throw new HttpException(405); }
        return $this->panel($page->title(), $page->render($_GET), 'trash');
    }

    /** Undo: put back the records the current user deleted in one request. */
    public function admin_undo(): string
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        if (!Trash::enabled()) { throw new HttpException(404); }
        $batch = (string) ($this->params[0] ?? '');
        $result = Trash::undo($batch);
        $restored = count($result['restored']);
        if ($restored > 0) {
            $first = $result['restored'][0];
            $class = $first->resourceClass();
            Notification::make()->title($restored === 1
                ? AdminLang::get('trash.restored', ['label' => $class !== null ? $class::getLabel() : $first->resource])
                : AdminLang::get('trash.restored_many', ['count' => $restored]))->success()->send();
        }
        foreach (array_slice($result['refused'], 0, 5) as $message) {
            Notification::make()->title(AdminLang::get('trash.undo_failed'))->body($message)->danger()->send();
        }
        if ($restored === 0 && $result['refused'] === []) {
            Notification::make()->title(AdminLang::get('trash.undo_expired'))->warning()->send();
        }
        $this->redirect($this->back());
    }

    /** Back to the panel page the toast was on (only an admin URL of this site). */
    private function back(): string
    {
        $back = $this->request->input('_back');
        $base = Admin::url('');
        if (is_string($back) && $back !== '') {
            $path = (string) parse_url($back, PHP_URL_PATH);
            $query = parse_url($back, PHP_URL_QUERY);
            $basePath = (string) parse_url($base, PHP_URL_PATH);
            $query = is_string($query) && preg_match('/^[^\x00-\x20\x7F"<>\\\\]*$/D', $query) === 1 ? $query : null;
            if (($path === $basePath || str_starts_with($path, $basePath . '/')) && !str_contains($path, '..') && preg_match('~^[A-Za-z0-9/_\-.%]*$~D', $path) === 1) {
                return (preg_match('~^https?://[^/]+~i', $base, $origin) === 1 ? $origin[0] : '') . $path . (is_string($query) && $query !== '' ? '?' . $query : '');
            }
        }
        return Admin::url('dashboard');
    }
}
