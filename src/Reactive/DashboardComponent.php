<?php
declare(strict_types=1);
namespace FloCMS\Admin\Reactive;

use FloCMS\Admin\{Admin, Widgets\WidgetRegistry, Widgets\Dashboard};
use FloCMS\Core\{Session, HttpException};
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Component, Attributes\State, Attributes\Action};

final class DashboardComponent extends Component
{
    #[State(writable: false)] public string $instanceKey = 'admin-dashboard';
    public function authorize(Request $request): void
    {
        if (!Admin::config()->isInstalled() || !Admin::config()->reactiveEnabled() || !WidgetRegistry::fromConfig(Admin::config())->configured()) { throw new HttpException(404); }
        if (!Session::get('isloggedin') || !Session::get('admin_access')) { throw new HttpException(403); }
    }
    #[Action] public function refresh(): void {}
    public function render(): string { return Dashboard::render(true); }
}
