<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Settings\Settings;
use FloCMS\Admin\Settings\SettingsEditor;
use FloCMS\Admin\Settings\SettingsPage;
use FloCMS\Admin\Settings\SettingsUploads;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\Auth;
use FloCMS\Core\HttpException;
use FloCMS\Core\Session;
use FloCMS\Core\TemplateEngine;

/**
 * /admin/settings: the settings pages, with a vertical list of pages and one
 * form per page (/admin/settings/edit/<page>). Each page needs its permission
 * (default settings.manage; the panel page settings.panel).
 */
final class SettingsController extends PanelController
{
    protected array $actionPermissions = ['admin_index' => null, 'admin_edit' => null];

    /** The first page the user may open, or an empty state when the site declares none. */
    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        $pages = self::allowedPages();
        if ($pages !== []) {
            $this->redirect(Admin::url('settings/edit/' . array_key_first($pages)));
        }
        if (Admin::settings()->all() !== []) {
            throw new HttpException(403);
        }
        $title = AdminLang::get('settings.title');
        $html = TemplateEngine::renderFile(PageView::viewPath('settings-empty'), [
            't' => PageView::translator(),
            'title' => $title,
            'file' => 'admin/settings.php',
            'problems' => Admin::settings()->problems(),
        ]);
        return $this->panel($title, $html, 'settings');
    }

    public function admin_edit(): string|\FloCMS\Core\Http\Response
    {
        $name = (string) ($this->params[0] ?? '');
        $page = Admin::settings()->find($name) ?? throw new HttpException(404);
        Auth::authorize($page->getPermission());

        $editor = new SettingsEditor($page, Settings::store());
        $errors = [];
        $editing = $this->editingLanguage((string) ($this->request->queryValue('editing') ?? ''));

        if ($this->isPost()) {
            $editing = $this->editingLanguage((string) ($this->request->input('_editing') ?? ''));
            if (!$editor->ready()) {
                Session::setFlash(AdminLang::get('settings.not_ready'), 'danger');
                $this->redirect(Admin::url('settings/edit/' . $name));
            }
            $result = $editor->submit($this->request->all(), is_array($_FILES['_uploads'] ?? null) ? $_FILES['_uploads'] : []);
            if ($result['saved']) {
                Session::setFlash(AdminLang::get('settings.saved'), 'success');
                $this->redirect(Admin::url('settings/edit/' . $name, ['editing' => $editing === Languages::default() ? null : $editing]));
            }
            $state = $result['state'];
            $errors = $result['errors'];
            $editing = \FloCMS\Admin\Translation\EditingLanguage::forErrors($errors, $editing);
            Session::setFlash(AdminLang::get('flash.fix_errors'), 'danger');
        } else {
            $state = $editor->initialState();
        }

        $switch = !$page->usesBadges() && count(Languages::all()) > 1;
        $renderer = new FormRenderer($state, $errors, Languages::all(), Languages::default(), true,
            languageSwitch: !$page->usesBadges(), editing: $editing,
            uploadControl: static fn (\FloCMS\Admin\Form\Fields\FileUpload $field, string $id, string $inputName, string $value, ?string $fallback, array $attributes): string
                => SettingsUploads::control($field, $id, $inputName, $value, $fallback, $attributes));

        $title = $page->getLabel();
        $html = TemplateEngine::renderFile(PageView::viewPath('settings'), [
            't' => PageView::translator(),
            'title' => $title,
            'description' => $page->getDescription(),
            'action' => Admin::url('settings/edit/' . $name),
            'fields' => $fields = $renderer->render($editor->form()),
            'csrf' => \FloCMS\Core\Csrf::field(),
            'pages' => $this->navigation($name),
            'switch' => $switch,
            // The settings page keeps its select (0.23 adds the counts and Next missing).
            'switchHtml' => $switch ? \FloCMS\Admin\Form\LanguageSwitch::render('fa-settings-switch', Languages::all(), Languages::default(), $editing, $renderer->languageStats(),
                style: 'select', selectAttributes: ['data-settings-language' => '']) : '',
            'languages' => array_map(static fn (string $code): array => ['code' => $code, 'name' => Languages::name($code), 'dir' => Languages::direction($code)], Languages::all()),
            'editing' => $editing,
            'defaultLang' => Languages::default(),
            'defaultName' => Languages::name(Languages::default()),
            'ready' => $editor->ready(),
            'dirty' => $errors !== [],
            'pagesLabel' => AdminLang::get('settings.pages'),
            'notice' => null,
        ]);

        return $this->panel($title, $html, 'settings');
    }

    /**
     * Pages the current user may open, in order.
     *
     * @return array<string, SettingsPage>
     */
    public static function allowedPages(): array
    {
        return array_filter(Admin::settings()->all(), static fn (SettingsPage $page): bool => Auth::can($page->getPermission()));
    }

    /** @return list<array{label: string, description: ?string, url: string, icon: string, active: bool}> */
    private function navigation(string $current): array
    {
        $items = [];
        foreach (self::allowedPages() as $name => $page) {
            $items[] = [
                'name' => $name,
                'label' => $page->getLabel(),
                'description' => $page->getDescription(),
                'url' => Admin::url('settings/edit/' . $name),
                'icon' => Icons::svg($page->getIcon()),
                'active' => $name === $current,
            ];
        }
        return $items;
    }

    private function editingLanguage(string $lang): string
    {
        $lang = strtolower(trim($lang));
        return in_array($lang, Languages::all(), true) ? $lang : Languages::default();
    }
}
