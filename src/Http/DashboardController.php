<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Session;
use FloCMS\Core\TemplateEngine;
use Throwable;

/**
 * The panel's home page, /admin/dashboard: one card per resource the user
 * may view, with its number of records. Any user who passed FloCMS's admin
 * login gate may open it; each card follows its resource's view permission.
 */
final class DashboardController extends PanelController
{
    protected array $actionPermissions = ['admin_index' => null];

    public function admin_index(): string
    {
        $registry = Admin::registry();
        $resources = $registry->all();
        if ($registry->find('accounts') !== null) {
            $resources['accounts'] = $registry->find('accounts');
        }

        $db = App::db();
        $cards = [];
        foreach ($resources as $slug => $class) {
            /** @var class-string<Resource> $class */
            if (!Auth::can($class::permission('view'))) {
                continue;
            }

            $count = null;
            if ($db !== null) {
                try {
                    $count = (new RecordRepository(new $class(), $db, Translations::store()))->newQuery()->count();
                } catch (Throwable) {
                    // A missing table must not break the dashboard: admin:doctor reports it
                    $count = null;
                }
            }

            $cards[] = [
                'label' => $class::getPluralLabel(),
                'url' => Admin::url($slug),
                'icon' => Icons::svg($class::getIcon()),
                'count' => $count === null ? '–' : number_format($count),
            ];
        }

        $name = (string) (Session::get('fullname') ?: Session::get('email') ?: '');
        $html = TemplateEngine::renderFile(PageView::viewPath('dashboard'), [
            't' => PageView::translator(),
            'greeting' => $name === '' ? AdminLang::get('dashboard.greeting_anonymous') : AdminLang::get('dashboard.greeting', ['name' => $name]),
            'cards' => $cards,
        ]);

        return $this->panel(AdminLang::get('nav.dashboard'), $html, 'dashboard');
    }
}
