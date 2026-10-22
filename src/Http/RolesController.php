<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Roles\PermissionCatalogue;
use FloCMS\Admin\Roles\Role;
use FloCMS\Admin\Roles\RoleEditor;
use FloCMS\Admin\Roles\Roles;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\Auth;
use FloCMS\Core\HttpException;
use FloCMS\Core\TemplateEngine;

/**
 * Site → Roles (0.21): /admin/roles (the list), /admin/roles/create,
 * /admin/roles/edit/<number>, /admin/roles/reset/<number> and
 * /admin/roles/delete/<number> (a confirmation page without JavaScript; a
 * dialog with it). Every page needs roles.manage; RoleEditor enforces the rules.
 */
final class RolesController extends PanelController
{
    protected array $actionPermissions = ['admin_index' => null, 'admin_create' => null, 'admin_edit' => null, 'admin_reset' => null, 'admin_delete' => null, 'admin_two_factor' => null];

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        if (($notice = $this->guard()) !== null) { return $notice; }
        $counts = Roles::userCounts();
        $rows = [];
        foreach (Roles::all() as $role) {
            $rows[] = $this->row($role, $counts[$role->number] ?? 0);
        }
        $html = TemplateEngine::renderFile(PageView::viewPath('roles'), [
            't' => PageView::translator(),
            'title' => AdminLang::get('roles.title'),
            'rows' => $rows,
            'canCreate' => Roles::canCreate(),
            'createUrl' => Admin::url('roles/create'),
            'plusIcon' => Icons::svg('plus'),
            'lockIcon' => Icons::svg('lock', 'fa-icon fa-icon-sm'),
            'checkIcon' => Icons::svg('check', 'fa-icon fa-icon-sm'),
            'infoIcon' => Icons::svg('info', 'fa-icon fa-icon-sm'),
            'trashIcon' => Icons::svg('trash'),
            'downIcon' => Icons::svg('chevron-down', 'fa-icon fa-icon-sm'),
            'csrf' => \FloCMS\Core\Csrf::field(),
            'countsKnown' => $counts !== null,
        ]);
        return $this->panel(AdminLang::get('roles.title'), $html, 'roles');
    }

    public function admin_create(): string|\FloCMS\Core\Http\Response
    {
        if (($notice = $this->guard()) !== null) { return $notice; }
        if (!Roles::canCreate()) { throw new HttpException(403); }
        return $this->editor(null);
    }

    public function admin_edit(): string|\FloCMS\Core\Http\Response
    {
        if (($notice = $this->guard()) !== null) { return $notice; }
        return $this->editor($this->role());
    }

    public function admin_reset(): string
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $this->guardOrFail();
        $role = $this->role();
        $error = RoleEditor::reset($role);
        if ($error !== null) {
            Notification::make()->title($error)->danger()->send();
        } else {
            Notification::make()->title(AdminLang::get('roles.reset_done'))->success()->send();
        }
        $this->redirect(Admin::url('roles/edit/' . $role->number));
    }

    /** Require two-factor sign-in for a role, or stop requiring it (0.35). POST /admin/roles/two_factor/<number>. */
    public function admin_two_factor(): string
    {
        if (!$this->isPost()) { header('Allow: POST'); throw new HttpException(405); }
        $this->guardOrFail();
        $role = $this->role();
        if (!\FloCMS\Admin\Auth\TwoFactor::available()) { throw new HttpException(409); }
        if (!self::canRequireTwoFactor($role)) { throw new HttpException(403); }
        $required = $this->request->input('two_factor') === '1';
        \FloCMS\Admin\Auth\TwoFactor::setRequired($role->number, $required);
        \FloCMS\Admin\Activity\Activity::record('role_two_factor', 'roles', (string) $role->number, $role->label(), meta: ['required' => $required ? 1 : 0]);
        Notification::make()->title(AdminLang::get($required ? 'roles.two_factor_now_required' : 'roles.two_factor_now_optional', ['role' => $role->label()]))->success()->send();
        $this->redirect(Admin::url('roles/edit/' . $role->number));
    }

    /** Roles up to your own level: requiring a step can't lock anyone out, so your own role (and the top role, if it's yours) counts. */
    private static function canRequireTwoFactor(Role $role): bool
    {
        $own = Roles::ownLevel();
        return $own !== null && $role->level <= $own && Auth::can(Roles::PERMISSION);
    }

    /** @return array{required: bool, canChange: bool, url: string, missing: int, total: int, config: bool}|null */
    private static function twoFactorData(Role $role): ?array
    {
        if (!\FloCMS\Admin\Auth\TwoFactor::available()) { return null; }
        $required = \FloCMS\Admin\Auth\TwoFactor::isRequired($role->number);
        $coverage = \FloCMS\Admin\Auth\TwoFactor::roleCoverage($role->number);
        return [
            'required' => $required, 'canChange' => self::canRequireTwoFactor($role), 'url' => Admin::url('roles/two_factor/' . $role->number),
            'missing' => max(0, $coverage['total'] - $coverage['on']), 'total' => $coverage['total'],
            'config' => in_array($role->number, Admin::config()->twoFactor()['required_roles'], true),
        ];
    }

    public function admin_delete(): string|\FloCMS\Core\Http\Response
    {
        $this->guardOrFail();
        $role = $this->role();
        if (!Roles::canDelete($role)) { throw new HttpException(403); }
        if ($this->isPost()) {
            $move = $this->request->input('move_to');
            $error = RoleEditor::delete($role, is_scalar($move) && is_numeric($move) ? (int) $move : null);
            if ($error === null) {
                Notification::make()->title(AdminLang::get('roles.deleted'))->success()->send();
                $this->redirect(Admin::url('roles'));
            }
            Notification::make()->title($error)->danger()->send();
            $this->redirect(Admin::url('roles/delete/' . $role->number));
        }
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET, POST'); throw new HttpException(405); }
        $html = TemplateEngine::renderFile(PageView::viewPath('role-delete'), self::deleteData($role, Roles::userCounts()[$role->number] ?? 0) + [
            't' => PageView::translator(), 'csrf' => \FloCMS\Core\Csrf::field(), 'cancelUrl' => Admin::url('roles/edit/' . $role->number), 'page' => true,
        ]);
        return $this->panel(AdminLang::get('roles.delete_role'), $html, 'roles');
    }

    /* ------------------------------------------------------------- */

    private function editor(?Role $role): string|\FloCMS\Core\Http\Response
    {
        $creating = $role === null;
        $editable = $creating ? Roles::canCreate() : Roles::canEdit($role);
        $current = $role ?? RoleEditor::blank();
        $state = RoleEditor::state($current);
        $permissions = $current->permissions;
        $errors = [];

        if ($this->isPost()) {
            if (!$editable) { throw new HttpException(403); }
            $result = RoleEditor::save($role, $this->request->all());
            if ($result['role'] !== null) {
                Notification::make()->title(AdminLang::get($creating ? 'roles.created' : 'roles.saved'))->success()->send();
                $this->redirect(Admin::url('roles/edit/' . $result['role']->number));
            }
            $state = $result['state'];
            $permissions = $result['permissions'];
            $errors = $result['errors'];
            Notification::make()->title($errors['_form'] ?? AdminLang::get('flash.fix_errors'))->danger()->send();
        }

        $renderer = new FormRenderer($state, array_diff_key($errors, ['_form' => true]), Languages::all(), Languages::default(), !$creating);
        $form = RoleEditor::form();
        if (!$editable) { foreach ($form->fields() as $field) { $field->disabled(); } }

        $title = $creating ? AdminLang::get('roles.new') : $current->label();
        $count = $creating ? 0 : (Roles::userCounts()[$current->number] ?? 0);
        $groups = $this->groups($permissions, $editable);
        $html = TemplateEngine::renderFile(PageView::viewPath('role'), [
            't' => PageView::translator(),
            'title' => $title,
            'creating' => $creating,
            'action' => $creating ? Admin::url('roles/create') : Admin::url('roles/edit/' . $current->number),
            'csrf' => \FloCMS\Core\Csrf::field(),
            'fields' => $renderer->render($form),
            'groups' => $groups,
            'others' => $this->others($permissions, $editable),
            'editable' => $editable,
            'dirty' => $errors !== [],
            'formError' => $errors['_form'] ?? null,
            'locked' => !$creating && Roles::isLocked($current),
            'badge' => $creating ? null : $this->badge($current),
            'note' => $creating ? null : ($current->changed() ? AdminLang::get('roles.changed_note') : ($current->fromConfig() ? AdminLang::get('roles.config_note') : AdminLang::get('roles.custom_note', ['number' => $current->number]))),
            'canReset' => !$creating && Roles::canReset($current),
            'resetUrl' => Admin::url('roles/reset/' . $current->number),
            'resetTitle' => AdminLang::get('roles.reset_title', ['role' => $current->label()]),
            'canDelete' => !$creating && Roles::canDelete($current),
            'delete' => !$creating && Roles::canDelete($current) ? self::deleteData($current, $count) : null,
            'granted' => self::grantedCount($permissions),
            'users' => $count,
            'holders' => $creating ? [] : Roles::holders($current->number),
            'more' => max(0, $count - 5),
            'usersUrl' => PanelView::pageUrls()['users'],
            'twoFactor' => $creating ? null : self::twoFactorData($current),
            'zeroWarning' => !$creating && $current->number === 0 && $current->adminAccess ? AdminLang::get('roles.access_zero', ['number' => 0]) : null,
            'lockIcon' => Icons::svg('lock', 'fa-icon fa-icon-sm'),
            'infoIcon' => Icons::svg('info', 'fa-icon fa-icon-sm'),
            'xIcon' => Icons::svg('x', 'fa-icon fa-icon-sm'),
            'trashIcon' => Icons::svg('trash'),
            'downIcon' => Icons::svg('chevron-down', 'fa-icon fa-icon-sm'),
            'rightIcon' => Icons::svg('chevron-right', 'fa-icon fa-icon-sm fa-flip'),
        ]);

        return $this->panel($title, $html, 'roles');
    }

    /** The groups of the grid with what this role has and what the current user may change. */
    private function groups(array $permissions, bool $editable): array
    {
        $role = new Role(-1, '', 0, false, $permissions, Role::SOURCE_CUSTOM);
        $groups = [];
        foreach (PermissionCatalogue::groups() as $group) {
            $all = $group['wildcard'] !== null && in_array($group['wildcard'], $permissions, true);
            $wildcardHeld = $group['wildcard'] !== null && Roles::holds($group['wildcard']);
            $total = 0;
            $granted = 0;
            $cell = function (string $name, string $label) use ($role, $permissions, $editable, &$total, &$granted): array {
                $total++;
                $has = $role->grants($name);
                if ($has) { $granted++; }
                $exact = in_array($name, $permissions, true);
                $held = Roles::holds($name);
                return [
                    'name' => $name, 'label' => $label,
                    'checked' => $has,
                    // Granted by a wider pattern ("All content", "*"): shown ticked, changed with that switch.
                    'covered' => $has && !$exact,
                    'disabled' => !$editable || !$held || ($has && !$exact),
                    'notHeld' => $editable && !$held,
                    // Fixed whatever a switch does (not editable, or not held): the script leaves it alone.
                    'fixed' => !$editable || !$held,
                    // Without JavaScript a covered box is not sent (disabled): a hidden copy keeps the
                    // permission if its switch is turned off, so nothing is lost silently.
                    'keep' => $has && !$exact && $editable && $held,
                ];
            };
            $rows = [];
            foreach ($group['rows'] as $row) {
                $cells = [];
                foreach ($row['cells'] as $c) { $cells[] = $cell($c['name'], $row['label'] . ': ' . $c['label']) + ['ability' => $c['ability'], 'short' => $c['label']]; }
                $rows[] = ['label' => $row['label'], 'sub' => $row['sub'], 'cells' => $cells];
            }
            $items = [];
            foreach ($group['items'] as $item) { $items[] = $cell($item['name'], $item['label']) + ['title' => $item['label'], 'sub' => $item['sub']]; }
            $groups[] = [
                'key' => $group['key'], 'title' => $group['title'], 'help' => $group['help'], 'columns' => $group['columns'],
                'wildcard' => $group['wildcard'], 'wildcardLabel' => $group['wildcardLabel'], 'wildcardHelp' => $group['wildcardHelp'],
                'all' => $all, 'allDisabled' => !$editable || !$wildcardHeld,
                'covered' => AdminLang::get('roles.covered', ['switch' => $group['wildcardLabel']]),
                'rows' => $rows, 'items' => $items,
                'count' => AdminLang::get('roles.of', ['count' => $granted, 'total' => $total]),
                'everything' => in_array('*', $permissions, true),
            ];
        }
        return $groups;
    }

    /** @return list<array{name: string, disabled: bool}> patterns the grid doesn't show */
    private function others(array $permissions, bool $editable): array
    {
        $known = PermissionCatalogue::names() + PermissionCatalogue::wildcards();
        $others = [];
        foreach ($permissions as $pattern) {
            if (!isset($known[$pattern])) { $others[] = ['name' => $pattern, 'disabled' => !$editable || !Roles::holds($pattern)]; }
        }
        return $others;
    }

    /** @return array<string, mixed> */
    private function row(Role $role, int $users): array
    {
        $locked = Roles::isLocked($role);
        $count = count($role->permissions);
        return [
            'number' => $role->number,
            'name' => $role->label(),
            'level' => $role->level,
            'access' => $role->adminAccess,
            'users' => $users,
            'permissions' => $role->holdsEverything() ? AdminLang::get('roles.everything')
                : ($count === 0 ? AdminLang::get('roles.none') : AdminLang::get($count === 1 ? 'roles.count_one' : 'roles.count_many', ['count' => $count])),
            'badge' => $this->badge($role),
            'icon' => Icons::svg($locked ? 'crown' : ($role->adminAccess ? 'shield' : 'user')),
            'locked' => $locked,
            'canEdit' => Roles::canEdit($role),
            'canReset' => Roles::canReset($role),
            'canDelete' => Roles::canDelete($role),
            'editUrl' => Admin::url('roles/edit/' . $role->number),
            'resetUrl' => Admin::url('roles/reset/' . $role->number),
            'resetTitle' => AdminLang::get('roles.reset_title', ['role' => $role->label()]),
            'deleteUrl' => Admin::url('roles/delete/' . $role->number),
            'delete' => Roles::canDelete($role) ? self::deleteData($role, $users) : null,
        ];
    }

    /** @return array{label: string, class: string} */
    private function badge(Role $role): array
    {
        return match ($role->source) {
            Role::SOURCE_CHANGED => ['label' => AdminLang::get('roles.source.changed'), 'class' => 'fa-badge-yellow'],
            Role::SOURCE_CUSTOM => ['label' => AdminLang::get('roles.source.custom'), 'class' => 'fa-badge-purple'],
            default => ['label' => AdminLang::get('roles.source.config'), 'class' => 'fa-badge-gray'],
        };
    }

    /** What the delete dialog (and its page) shows. */
    private static function deleteData(Role $role, int $users): array
    {
        // Lowest level first.
        $candidates = array_filter(Roles::all(), static fn (Role $other): bool => $other->number !== $role->number && Roles::canMoveUsersTo($other));
        uasort($candidates, static fn (Role $a, Role $b): int => [$a->level, $a->number] <=> [$b->level, $b->number]);
        // Preselected: the highest level at or below the deleted role's, a config role first.
        $chosen = null;
        $best = null;
        foreach ($candidates as $other) {
            $rank = [$other->level, $other->fromConfig() ? 1 : 0, -$other->number];
            if ($other->level <= $role->level && ($best === null || $rank > $best)) { $best = $rank; $chosen = $other->number; }
        }
        $chosen ??= array_key_first($candidates);
        $targets = [];
        foreach ($candidates as $other) { $targets[] = ['number' => $other->number, 'label' => $other->label(), 'selected' => $other->number === $chosen]; }
        return [
            'id' => 'fa-role-delete-' . $role->number,
            'url' => Admin::url('roles/delete/' . $role->number),
            'title' => AdminLang::get('roles.delete_title', ['role' => $role->label()]),
            'users' => $users,
            'usersText' => $users === 1 ? AdminLang::get('roles.delete_users_one') : AdminLang::get('roles.delete_users_many', ['count' => $users]),
            'targets' => $targets,
        ];
    }

    private static function grantedCount(array $permissions): string
    {
        if (in_array('*', $permissions, true)) { return '*'; }
        $role = new Role(-1, '', 0, false, $permissions, Role::SOURCE_CUSTOM);
        $names = array_keys(PermissionCatalogue::names());
        $known = PermissionCatalogue::names() + PermissionCatalogue::wildcards();
        $count = count(array_filter($names, static fn (string $name): bool => $role->grants($name)));
        $count += count(array_filter($permissions, static fn (string $p): bool => !isset($known[$p])));
        return (string) $count;
    }

    private function role(): Role
    {
        $number = (string) ($this->params[0] ?? '');
        if (preg_match('/^\d{1,3}$/D', $number) !== 1) { throw new HttpException(404); }
        return Roles::find((int) $number) ?? throw new HttpException(404);
    }

    /** Null when the page can be used; a notice page when the table or core is missing; 403 without roles.manage. */
    private function guard(): string|\FloCMS\Core\Http\Response|null
    {
        $status = Roles::status();
        if ($status === 'off') { throw new HttpException(404); }
        if (!Auth::can(Roles::PERMISSION)) { throw new HttpException(403); }
        if ($status === 'ready') { return null; }
        $title = AdminLang::get('roles.title');
        $html = '<div class="fa-page-head"><div><h1 class="fa-page-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1></div></div>'
            . '<div class="fa-alert fa-alert-warning" role="status">' . htmlspecialchars(AdminLang::get($status === 'missing' ? 'roles.not_ready' : 'roles.unsupported'), ENT_QUOTES, 'UTF-8') . '</div>';
        return $this->panel($title, $html, 'roles');
    }

    private function guardOrFail(): void
    {
        $status = Roles::status();
        if ($status === 'off') { throw new HttpException(404); }
        if (!Auth::can(Roles::PERMISSION)) { throw new HttpException(403); }
        if ($status !== 'ready') { throw new HttpException(409); }
    }
}
