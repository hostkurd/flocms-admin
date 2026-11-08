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
use FloCMS\Core\TemplateEngine;
use Throwable;

/**
 * The panel's home page: explicitly configured widgets, or resource-count
 * cards when widget configuration is empty. Any user who passed FloCMS's admin
 * login gate may open it; each card follows its resource's view permission.
 */
final class DashboardController extends PanelController
{
    protected array $actionPermissions = ['admin_index' => null];

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        $registry = Admin::registry();
        $resources = $registry->all();
        if ($registry->find('accounts') !== null) {
            $resources['accounts'] = $registry->find('accounts');
        }

        $widgetRegistry = \FloCMS\Admin\Widgets\WidgetRegistry::fromConfig(Admin::config());
        $widgetHtml = null;
        if ($widgetRegistry->configured()) {
            // The period buttons (0.42): ?period=7 when it is one of the configured ones.
            $period = \FloCMS\Admin\Widgets\Period::fromRequest($_GET['period'] ?? null);
            $widgetHtml = Admin::config()->reactiveEnabled()
                ? \FloCMS\Reactive\Reactive::mount('flo-admin-dashboard', 'admin-dashboard', ['period' => $period->days()])
                : \FloCMS\Admin\Widgets\Dashboard::render(false, $period);
        }
        $db = App::db();
        $cards = [];
        foreach ($widgetHtml === null ? $resources : [] as $slug => $class) {
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

        $html = TemplateEngine::renderFile(PageView::viewPath('dashboard'), [
            't' => PageView::translator(),
            // Widgets draw their own header (with the period buttons); the cards get the greeting and quick buttons (0.42).
            'head' => $widgetHtml === null ? \FloCMS\Admin\Widgets\Dashboard::head(null) : '',
            'cards' => $cards, 'widgetHtml' => $widgetHtml,
        ]);

        return $this->panel(AdminLang::get('nav.dashboard'), $html, 'dashboard');
    }
}
