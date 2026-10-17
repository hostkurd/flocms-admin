<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

use FloCMS\Admin\Form\Fields\Hidden;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\EditorIcons;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Languages;

/**
 * Turns a Form and its state into HTML. A translatable field shows its
 * default-language input and a language badge; the badge opens a <dialog>
 * with one input per language. The dialog sits inside the <form>, so every
 * language is submitted whether or not the dialog was opened, and the page
 * works without JavaScript (external CSS shows inputs before enhancement).
 */
final class FormRenderer
{
    /**
     * @param array<string, string|array<string, string>> $state
     * @param array<string, string> $errors keyed "title" or "title.ar"
     * @param list<string> $languages default language first
     */
    public function __construct(
        private array $state,
        private readonly array $errors,
        private readonly array $languages,
        private readonly string $defaultLang,
        private readonly bool $isEdit = false,
        // Retained for constructor compatibility; all forms use language badges.
        bool $inlineTranslations = false,
        private readonly string $prefix = '',
        private readonly bool $reactive = false,
        private readonly ?\Closure $fileUrl = null,
        // Rich text media endpoint (".../admin/<slug>/media"); null hides uploads.
        private readonly ?string $mediaUrl = null,
        // Identifies one editing session (a dialog's operation) so Reactive keeps
        // a rich editor's own DOM across updates of that session.
        private readonly ?string $editorKey = null,
        // Settings pages: translatable fields show one input per language, and a
        // page-level "Editing: language" switch shows the chosen one (JavaScript).
        private readonly bool $languageSwitch = false,
        // Settings pages: renders a (public) upload control instead of the resource one:
        // fn (FileUpload $field, string $id, string $name, string $value, ?string $fallback, array $attributes): string
        private readonly ?\Closure $uploadControl = null,
        // Relation fields: ".../admin/<slug>/lookup" for searching related records, and
        // the record being edited (a record is never offered as its own parent).
        private readonly ?string $lookupUrl = null,
        private readonly ?string $recordId = null,
        // The table of the resource being edited: a relation to the same table never offers this record.
        private readonly ?string $ownTable = null
    ) {
    }

    private ?Form $resolvedForm = null;

    public function render(Form $form): string
    {
        $resolved = $form->evaluate($this->state);
        $this->resolvedForm = $resolved;
        $html = $this->nodes($form->components(), $resolved, $form->getColumns(), $form->liveFields(), 'layout');
        if ($form->asideComponents() !== []) {
            // On the full page the aside is a sidebar; in a dialog it follows the main fields.
            $aside = $this->nodes($form->asideComponents(), $resolved, 1, $form->liveFields(), 'aside');
            $html = '<div class="fa-form-layout"><div class="fa-form-main">' . $html . '</div><div class="fa-form-aside">' . $aside . '</div></div>';
        }
        if ($form->liveFields() !== []) {
            $html .= $this->reactive ? '<button type="button" data-rx-action="refreshForm" data-admin-form-refresh hidden></button>'
                : '<button class="fa-btn" type="submit" name="_refresh" value="1" data-admin-form-refresh>' . Str::e(AdminLang::get('form.update_fields')) . '</button>';
        }
        return $html;
    }

    private function nodes(array $nodes, Form $form, int $columns, array $live, string $path): string
    {
        $html = '';
        foreach ($nodes as $index => $node) {
            if ($node instanceof Field) {
                $field = $form->field($node->getName());
                if (!$field->isVisible()) { continue; }
                if ($field instanceof Hidden) { $html .= $field->renderControl($this->prefix . 'f-' . $field->getName(), $field->getName(), $this->stringState($field)); continue; }
                $rendered = $this->field($field, $columns);
                if (in_array($field->getName(), $live, true)) { $rendered = str_replace('data-field=', 'data-admin-live data-field=', $rendered); }
                $html .= $rendered; continue;
            }
            $id = $this->prefix . $path . '-' . $index . '-' . $node->getName();
            $label = Str::e($node->getLabel());
            if ($node instanceof \FloCMS\Admin\Form\Layout\Tabs) {
                $buttons = ''; $panels = ''; $names = [];
                foreach ($node->children() as $tabIndex => $tab) {
                    if (isset($names[$tab->getName()])) { throw new \LogicException('Tab names must be unique in a group.'); }
                    $names[$tab->getName()] = true;
                    $tabId = $id . '-' . $tabIndex;
                    $buttons .= '<button type="button" id="' . Str::e($tabId . '-tab') . '" data-admin-tab="' . Str::e($tabId) . '">' . Str::e($tab->getLabel()) . '</button>';
                    $panels .= '<section id="' . Str::e($tabId) . '" data-admin-tab-panel aria-labelledby="' . Str::e($tabId . '-title') . '"><h3 class="fa-tab-heading" id="' . Str::e($tabId . '-title') . '">' . Str::e($tab->getLabel()) . '</h3>' . $this->nodes($tab->children(), $form, $tab->getColumns(), $live, $tabId) . '</section>';
                }
                $html .= '<div class="fa-tabs fa-span-full" id="' . Str::e($id) . '" data-admin-tabs><div class="fa-tab-list" data-admin-tab-list aria-label="' . $label . '" hidden>' . $buttons . '</div>' . $panels . '</div>';
            } else {
                $title = $node instanceof \FloCMS\Admin\Form\Layout\Grid ? '' : '<h3 class="fa-section-title" id="' . Str::e($id . '-title') . '">' . $label . '</h3>';
                $description = $node->getDescription() === null ? '' : '<p class="fa-help">' . Str::e($node->getDescription()) . '</p>';
                $body = $this->nodes($node->children(), $form, $node->getColumns(), $live, $id);
                if ($node instanceof \FloCMS\Admin\Form\Layout\Section && $node->isCollapsible()) {
                    // A native <details>: works without JavaScript; a section with an error opens.
                    $open = !$node->isCollapsed() || $this->hasErrors($node);
                    $html .= '<details class="fa-form-section fa-span-full is-collapsible" id="' . Str::e($id) . '"' . ($open ? ' open' : '') . '>'
                        . '<summary class="fa-section-summary"><span class="fa-section-heading"><span class="fa-section-title" id="' . Str::e($id . '-title') . '">' . $label . '</span>' . $description . '</span>'
                        . Icons::svg('chevron-down', 'fa-icon fa-section-chevron') . '</summary>' . $body . '</details>';
                } else {
                    $html .= '<section class="fa-form-section fa-span-full"' . ($title !== '' ? ' aria-labelledby="' . Str::e($id . '-title') . '"' : '') . '>' . $title . $description . $body . '</section>';
                }
            }
        }
        return '<div class="fa-form-grid fa-grid-' . $columns . '">' . $html . '</div>';
    }

    private function hasErrors(\FloCMS\Admin\Form\Layout\Layout $layout): bool
    {
        foreach ($layout->children() as $child) {
            if ($child instanceof \FloCMS\Admin\Form\Layout\Layout ? $this->hasErrors($child) : $this->fieldHasError($child->getName())) { return true; }
        }
        return false;
    }

    private function fieldHasError(string $name): bool
    {
        foreach (array_keys($this->errors) as $key) {
            if ($key === $name || str_starts_with((string) $key, $name . '.')) { return true; }
        }
        return false;
    }

    public function field(Field $field, int $columns = 2): string
    {
        $name = $field->getName();
        $id = $this->prefix . 'f-' . $name;
        $error = $this->errors[$name] ?? null;
        $langErrors = $field->isTranslatable() ? $this->languageErrors($name) : [];

        $classes = ['fa-field'];
        $span = $field->getColumnSpan();
        if ($span === 'full') { $classes[] = 'fa-span-full'; }
        elseif ($span > 1) { $classes[] = 'fa-span-' . min($span, $columns); }
        if ($error !== null || $langErrors !== []) {
            $classes[] = 'has-error';
        }

        $label = Str::e($field->getLabel());
        if ($field->isRequired() || ($field->isRequiredOnCreate() && !$this->isEdit)) {
            $label .= ' <span class="fa-required" aria-hidden="true">*</span>';
        }

        $isUpload = $field instanceof Fields\FileUpload;
        if ($isUpload && $field->isTranslatable() && $this->uploadControl === null) {
            throw new \LogicException('Translatable uploads ("' . $name . '") are supported on settings pages only.');
        }
        $panes = $field->isTranslatable() && !$field instanceof Fields\RichEditor && ($this->languageSwitch || $isUpload);
        if ($this->languageSwitch && !$field->isTranslatable()) { $classes[] = 'is-shared'; }

        $html = '<div class="' . implode(' ', $classes) . '" data-field="' . Str::e($name) . '"' . ($this->languageSwitch && !$field->isTranslatable() ? ' data-same-all' : '') . ($panes ? ' data-translatable' : '') . '>';
        $html .= '<label class="fa-label" for="' . Str::e($id) . '">' . $label . ($panes && count($this->languages) > 1 ? $this->labelDots($field) : '') . '</label>';
        if ($this->languageSwitch && !$field->isTranslatable()) {
            $html .= '<p class="fa-same-note" hidden>' . Str::e(AdminLang::get('settings.same_all_languages')) . '</p>';
        }
        if ($panes) {
            $html .= $this->panes($field, $id, $langErrors);
        } elseif ($isUpload && $this->uploadControl !== null) {
            $html .= ($this->uploadControl)($field, $id, $name, $this->stringState($field), null, $this->describedBy($field, $id));
        } elseif ($field instanceof Fields\RichEditor) {
            $html .= $this->richEditor($field, $id, $langErrors);
        } elseif ($field->isTranslatable() && count($this->languages) > 1) {
            $html .= $this->translatable($field, $id, $langErrors);
        } elseif ($field->isTranslatable()) {
            $html .= $this->singleLanguage($field, $id);
        } else {
            $html .= $this->single($field, $id);
        }

        if (($help = $field->getHelp()) !== null) {
            $html .= '<p class="fa-help" id="' . Str::e($id) . '-help">' . Str::e($help) . '</p>';
        }
        if ($error !== null) {
            $html .= '<p class="fa-error" role="alert" id="' . Str::e($id) . '-error">' . Str::e($error) . '</p>';
        }

        return $html . '</div>';
    }

    private function single(Field $field, string $id): string
    {
        $attributes = [];
        if ($field instanceof Fields\BelongsTo || $field instanceof Fields\BelongsToMany) {
            $query = $this->recordId !== null && $this->recordId !== '' ? '?record=' . rawurlencode($this->recordId) : '';
            $attributes['data-lookup-url'] = $this->lookupUrl === null ? '' : rtrim($this->lookupUrl, '/') . '/' . rawurlencode($field->getName()) . $query;
            $relation = $field->hasRelation() ? $field->relation() : null;
            if ($relation !== null && $this->recordId !== null && $this->recordId !== '' && $relation->resourceClass() !== null && $this->ownTable === $relation->table()) {
                $attributes['data-relation-except'] = $this->recordId;
            }
            if (($parent = $field->getDependsOn()) !== null) {
                $attributes['data-relation-depends-label'] = $this->resolvedForm?->field($parent)?->getLabel() ?? $parent;
            }
        }
        if ($field instanceof \FloCMS\Admin\Form\Fields\FileUpload && $this->fileUrl !== null) { $attributes['data-upload-url'] = ($this->fileUrl)($field, $this->stringState($field)); }
        $describedBy = [];
        if ($field->getHelp() !== null) { $describedBy[] = $id . '-help'; }
        if (isset($this->errors[$field->getName()])) { $describedBy[] = $id . '-error'; }
        if ($describedBy !== []) { $attributes['aria-describedby'] = implode(' ', $describedBy); }
        if (isset($this->errors[$field->getName()])) {
            $attributes['aria-invalid'] = 'true';
        }

        return $field->renderControl($id, $field->getName(), $this->stringState($field), $attributes);
    }

    /**
     * A translatable field on a site with one language: a plain input, still
     * submitted as name[lang] so stored values keep their JSON shape and a
     * second language can be added later without migrating anything.
     */
    private function singleLanguage(Field $field, string $id): string
    {
        $name = $field->getName();
        $values = $this->state[$name] ?? [];
        $values = is_array($values) ? $values : [];
        $attributes = ['dir' => Languages::direction($this->defaultLang), 'lang' => $this->defaultLang];
        $describedBy = [];
        if ($field->getHelp() !== null) { $describedBy[] = $id . '-help'; }
        if (isset($this->errors[$name])) { $describedBy[] = $id . '-error'; $attributes['aria-invalid'] = 'true'; }
        if ($describedBy !== []) { $attributes['aria-describedby'] = implode(' ', $describedBy); }

        return $field->renderControl($id, $name . '[' . $this->defaultLang . ']', $values[$this->defaultLang] ?? '', $attributes);
    }

    /** @return array<string, string> aria attributes of a single control */
    private function describedBy(Field $field, string $id): array
    {
        $attributes = [];
        $describedBy = [];
        if ($field->getHelp() !== null) { $describedBy[] = $id . '-help'; }
        if (isset($this->errors[$field->getName()])) { $describedBy[] = $id . '-error'; $attributes['aria-invalid'] = 'true'; }
        if ($describedBy !== []) { $attributes['aria-describedby'] = implode(' ', $describedBy); }
        return $attributes;
    }

    /** Small filled/empty dots after a translatable label (one per language). */
    private function labelDots(Field $field): string
    {
        $values = $this->state[$field->getName()] ?? [];
        $values = is_array($values) ? $values : [];
        $dots = '';
        foreach ($this->languages as $lang) {
            $filled = ($values[$lang] ?? '') !== '';
            $state = $filled ? 'is-filled' : 'is-empty';
            if ($lang === $this->defaultLang ? isset($this->errors[$field->getName()]) : isset($this->errors[$field->getName() . '.' . $lang])) { $state .= ' is-invalid'; }
            $dots .= '<span class="fa-dot ' . $state . '" data-lang="' . Str::e($lang) . '" title="' . Str::e(Languages::name($lang)) . '"></span>';
        }
        return ' <span class="fa-dots fa-label-dots" aria-hidden="true">' . $dots . '</span>';
    }

    /**
     * One input per language, each in a pane marked with its language. On a
     * settings page the "Editing" switch shows the chosen language's pane;
     * without JavaScript every pane is shown with its language code.
     *
     * @param array<string, string> $langErrors
     */
    private function panes(Field $field, string $id, array $langErrors): string
    {
        $name = $field->getName();
        $values = $this->state[$name] ?? [];
        $values = is_array($values) ? $values : [];
        $default = $this->defaultLang;
        $languages = $this->languages === [] ? [$default] : $this->languages;
        $html = '<div class="fa-lang-panes" data-lang-panes>';
        foreach ($languages as $lang) {
            $isDefault = $lang === $default;
            $inputId = $isDefault ? $id : $id . '-' . $lang;
            $attributes = ['dir' => Languages::direction($lang), 'lang' => $lang];
            $describedBy = [];
            if ($isDefault && $field->getHelp() !== null) { $describedBy[] = $id . '-help'; }
            if ($isDefault && isset($this->errors[$name])) { $describedBy[] = $id . '-error'; $attributes['aria-invalid'] = 'true'; }
            if (!$isDefault && isset($langErrors[$lang])) { $describedBy[] = $inputId . '-error'; $attributes['aria-invalid'] = 'true'; }
            if ($describedBy !== []) { $attributes['aria-describedby'] = implode(' ', $describedBy); }
            $html .= '<div class="fa-lang-pane' . (!$isDefault && isset($langErrors[$lang]) ? ' has-error' : '') . '" data-lang="' . Str::e($lang) . '" data-input="' . Str::e($inputId) . '">';
            if (count($languages) > 1) {
                $html .= '<span class="fa-lang-pane-code"><code class="fa-lang-code' . ($isDefault ? ' is-default' : '') . '">' . Str::e($lang) . '</code> ' . Str::e(Languages::name($lang)) . '</span>';
            }
            $value = (string) ($values[$lang] ?? '');
            if ($field instanceof Fields\FileUpload) {
                $fallback = $isDefault ? null : (string) ($values[$default] ?? '');
                $html .= ($this->uploadControl)($field, $inputId, $name . '[' . $lang . ']', $value, $fallback === \FloCMS\Admin\Settings\SettingsUploads::PENDING ? null : $fallback, $attributes);
            } else {
                $html .= $field->renderControl($inputId, $name . '[' . $lang . ']', $value, $attributes);
            }
            if (!$isDefault && isset($langErrors[$lang])) {
                $html .= '<p class="fa-error" role="alert" id="' . Str::e($inputId . '-error') . '">' . Str::e($langErrors[$lang]) . '</p>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    /** @param array<string, string> $langErrors */
    private function translatable(Field $field, string $id, array $langErrors): string
    {
        $name = $field->getName();
        $values = $this->state[$name] ?? [];
        $values = is_array($values) ? $values : [];
        $default = $this->defaultLang;
        $dialogId = $this->prefix . 'd-' . $name;

        // Visible default-language input
        $mainAttributes = ['dir' => Languages::direction($default), 'lang' => $default];
        if (isset($this->errors[$name])) {
            $mainAttributes['aria-invalid'] = 'true';
        }
        $control = $field->renderControl($id, $name . '[' . $default . ']', $values[$default] ?? '', $mainAttributes);

        $missing = [];
        $dots = '';
        foreach ($this->languages as $lang) {
            $filled = ($values[$lang] ?? '') !== '';
            if (!$filled) {
                $missing[] = $lang;
            }
            if ($lang === $default) { continue; }
            // One dot per other language: filled, empty or invalid. The button's
            // label and title name the missing languages for every reader.
            $state = $filled ? 'is-filled' : 'is-empty';
            if (isset($langErrors[$lang])) {
                $state .= ' is-invalid';
            }
            $dots .= '<span class="fa-dot ' . $state . '" data-lang="' . Str::e($lang) . '"></span>';
        }
        $badge = '<span class="fa-code is-default">' . Str::e($default) . '</span><span class="fa-dots" aria-hidden="true">' . $dots . '</span>';

        $title = $missing === []
            ? AdminLang::get('translations.all_filled')
            : AdminLang::get('translations.missing', ['languages' => implode(', ', $missing)]);

        // The badge is part of the interface: it sits at the end of the page's
        // direction (left on Arabic pages, like every other control). The input
        // keeps the default language's own direction for the text typed in it.
        $html = '<div class="fa-input-row' . (count($this->languages) > 6 ? ' is-many' : '') . '">' . $control;
        $html .= '<button type="button" class="fa-lang-badge' . ($langErrors !== [] ? ' is-invalid' : '') . '"'
            . ' data-dialog="' . Str::e($dialogId) . '" aria-haspopup="dialog" aria-controls="' . Str::e($dialogId) . '"'
            . ' data-missing-text="' . Str::e(AdminLang::get('translations.missing', ['languages' => ''])) . '"'
            . ' data-complete-text="' . Str::e(AdminLang::get('translations.all_filled')) . '"'
            . ' data-open-text="' . Str::e(AdminLang::get('translations.open', ['field' => $field->getLabel()])) . '"'
            . ' title="' . Str::e($title) . '" aria-label="' . Str::e(AdminLang::get('translations.open', ['field' => $field->getLabel()]) . '. ' . $title) . '">'
            . $badge . '</button></div>';

        // The dialog: one input per language
        $html .= '<dialog class="fa-lang-dialog' . ($field->isLong() ? ' is-wide' : '') . '" id="' . Str::e($dialogId) . '"'
            . ' aria-labelledby="' . Str::e($dialogId) . '-title" data-main="' . Str::e($id) . '"'
            . ($langErrors !== [] ? ' data-open-on-load="1"' : '') . '>';
        $html .= '<div class="fa-dialog-head"><h2 class="fa-dialog-title" id="' . Str::e($dialogId) . '-title">'
            . Str::e($field->getLabel()) . '</h2></div><div class="fa-dialog-body">';

        foreach ($this->languages as $lang) {
            $inputId = $id . '-' . $lang;
            $isDefault = $lang === $default;
            $html .= '<div class="fa-lang-row' . ($isDefault ? ' fa-mirror-row' : '') . (isset($langErrors[$lang]) ? ' has-error' : '') . '">';
            $html .= '<label class="fa-lang-label" for="' . Str::e($inputId) . '">'
                . '<code class="fa-lang-code' . ($isDefault ? ' is-default' : '') . '">' . Str::e($lang) . '</code> '
                . '<span class="fa-lang-name">' . Str::e(Languages::name($lang)) . '</span>'
                . ($isDefault ? ' <small class="fa-lang-hint">' . Str::e(AdminLang::get('translations.default_language')) . '</small>' : '')
                . '</label>';

            $attributes = ['dir' => Languages::direction($lang), 'lang' => $lang];
            if ($field->isLong()) {
                $attributes['data-rows'] = '10';
            }
            if ($isDefault) {
                // Mirror of the visible input: no name, copied back on Save
                $attributes['data-mirror'] = $id;
                $html .= $field->renderControl($inputId, '', $values[$lang] ?? '', $attributes);
            } else {
                if (isset($langErrors[$lang])) {
                    $attributes['aria-invalid'] = 'true';
                }
                $html .= $field->renderControl($inputId, $name . '[' . $lang . ']', $values[$lang] ?? '', $attributes);
            }

            if (isset($langErrors[$lang])) {
                $html .= '<p class="fa-error" role="alert">' . Str::e($langErrors[$lang]) . '</p>';
            }
            $html .= '</div>';
        }

        $html .= '</div><div class="fa-dialog-foot">'
            . '<button type="button" class="fa-btn fa-btn-sm" data-dialog-cancel>' . Str::e(AdminLang::get('actions.cancel')) . '</button>'
            . '<button type="button" class="fa-btn fa-btn-primary fa-btn-sm" data-dialog-save>' . Str::e(AdminLang::get('actions.save')) . '</button>'
            . '</div></dialog>';

        return $html;
    }

    /**
     * A rich text field: toolbar, one panel per language behind small language
     * tabs (EN · AR · KU with filled/empty dots), and a plain HTML textarea per
     * language that the browser turns into the visual editor. Without
     * JavaScript every language's textarea is shown, one after the other.
     *
     * @param array<string, string> $langErrors
     */
    private function richEditor(Fields\RichEditor $field, string $id, array $langErrors): string
    {
        $name = $field->getName();
        $translatable = $field->isTranslatable();
        $languages = $translatable ? ($this->languages === [] ? [$this->defaultLang] : $this->languages) : [''];
        $values = $this->state[$name] ?? ($translatable ? [] : '');
        $tabbed = count($languages) > 1;
        $media = $this->mediaUrl === null ? '' : rtrim($this->mediaUrl, '/') . '/' . rawurlencode($name);
        // Videos can only be uploaded; without an upload address the button goes.
        $toolbar = array_values(array_filter($field->getToolbar(), static fn (string $b): bool => $b !== 'video' || $media !== ''));
        $config = [
            'toolbar' => $toolbar, 'images' => $field->allowsImages(), 'videos' => $field->allowsVideos() && $media !== '',
            'uploads' => $media !== '', 'embeds' => $field->getEmbeds(), 'media' => $media,
            // The media library's picker (0.17): choose an image uploaded before.
            'library' => $field->allowsImages() && \FloCMS\Admin\Media\MediaLibrary::enabled() && \FloCMS\Admin\Media\MediaLibrary::can('view'),
            'maxImage' => $field->getMaxImageBytes(), 'maxVideo' => $field->getMaxVideoBytes(), 'csrf' => \FloCMS\Core\Csrf::token(),
        ];
        $t = static fn (string $key, array $replace = []): string => AdminLang::get('editor.' . $key, $replace);
        $strings = [];
        foreach (['uploading', 'image_invalid', 'video_invalid', 'upload_failed', 'embed_invalid', 'link_invalid', 'too_large', 'progress',
            'youtube', 'vimeo', 'video_file', 'empty', 'filled', 'link_title', 'link_edit_title', 'image_title', 'image_edit_title', 'insert', 'save',
            'words', 'cancelled', 'uploaded', 'resize', 'width_now', 'align_left', 'align_center', 'align_right', 'align_justify'] as $key) { $strings[$key] = $t($key); }
        $strings['placeholder'] = $field->getPlaceholder() ?? $t('placeholder');

        $attributes = ' id="' . Str::e($id . '-rich') . '" data-admin-rich-editor data-script="' . Str::e(\FloCMS\Admin\Assets::editorUrl()) . '"'
            . ' data-config="' . Str::e(json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '"'
            . ' data-strings="' . Str::e(json_encode($strings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) . '"';
        if ($this->editorKey !== null && $this->editorKey !== '') {
            // The editor keeps its own DOM during one editing session. New errors for this
            // field rebuild it from the submitted text, so error marks and focus are right.
            $errorState = json_encode([$this->errors[$name] ?? null, $langErrors], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $attributes .= ' data-rx-ignore="' . Str::e(substr(hash('sha256', $this->editorKey . '|' . $this->prefix . $name . '|' . $errorState), 0, 16)) . '"';
        }
        $html = '<div class="fa-rich' . ($field->isDisabled() ? ' is-disabled' : '') . '"' . $attributes . '>';

        if ($tabbed) {
            $html .= '<div class="fa-rich-tabs" role="tablist" aria-label="' . Str::e($t('languages', ['field' => $field->getLabel()])) . '" data-rich-tabs hidden>';
            foreach ($languages as $index => $lang) {
                $filled = !\FloCMS\Admin\Support\RichText::isEmpty((string) (is_array($values) ? ($values[$lang] ?? '') : ''));
                $state = isset($langErrors[$lang]) || ($lang === $this->defaultLang && isset($this->errors[$name])) ? 'is-invalid' : ($filled ? 'is-filled' : 'is-empty');
                $html .= '<button type="button" role="tab" class="fa-rich-tab" id="' . Str::e($id . '-' . $lang . '-tab') . '" data-lang="' . Str::e($lang) . '"'
                    . ' aria-controls="' . Str::e($id . '-' . $lang . '-panel') . '" aria-selected="' . ($index === 0 ? 'true' : 'false') . '" tabindex="' . ($index === 0 ? '0' : '-1') . '">'
                    . '<span class="fa-code' . ($lang === $this->defaultLang ? ' is-default' : '') . '">' . Str::e($lang) . '</span>'
                    . '<span class="fa-rich-tab-name">' . Str::e(Languages::name($lang)) . '</span>'
                    . '<span class="fa-dot ' . $state . '" data-lang="' . Str::e($lang) . '"></span>'
                    . '<span class="fa-sr" data-rich-tab-state>' . Str::e($t($filled ? 'filled' : 'empty')) . '</span></button>';
            }
            $html .= '</div>';
        }

        // Toolbar, menus and dialogs are shown by the script once the editor runs.
        if (!$field->isDisabled()) {
            $keys = ['bold' => 'Ctrl+B', 'italic' => 'Ctrl+I', 'underline' => 'Ctrl+U', 'strike' => 'Ctrl+Shift+S', 'link' => 'Ctrl+K', 'undo' => 'Ctrl+Z', 'redo' => 'Ctrl+Shift+Z',
                'bulletList' => 'Ctrl+Shift+8', 'orderedList' => 'Ctrl+Shift+7', 'blockquote' => 'Ctrl+Shift+B', 'codeBlock' => 'Ctrl+Alt+C', 'heading2' => 'Ctrl+Alt+2', 'heading3' => 'Ctrl+Alt+3',
                'clearFormatting' => 'Ctrl+\\'];
            $labels = ['heading2' => 'heading2', 'heading3' => 'heading3', 'bold' => 'bold', 'italic' => 'italic', 'underline' => 'underline', 'strike' => 'strike', 'link' => 'link',
                'bulletList' => 'bullet_list', 'orderedList' => 'ordered_list', 'blockquote' => 'blockquote', 'codeBlock' => 'code_block', 'horizontalRule' => 'horizontal_rule',
                'image' => 'image', 'video' => 'video', 'embed' => 'embed', 'undo' => 'undo', 'redo' => 'redo', 'textAlign' => 'text_align', 'direction' => 'direction',
                'indent' => 'indent', 'outdent' => 'outdent', 'clearFormatting' => 'clear_formatting'];
            $groups = [['heading2', 'heading3'], ['bold', 'italic', 'underline', 'strike', 'link'], ['bulletList', 'orderedList', 'outdent', 'indent'],
                ['textAlign', 'direction'], ['blockquote', 'codeBlock', 'horizontalRule'], ['image', 'video', 'embed'], ['clearFormatting', 'undo', 'redo']];
            $caret = '<svg class="fa-rich-caret" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>';
            $html .= '<div class="fa-rich-toolbar" role="toolbar" aria-label="' . Str::e($t('toolbar', ['field' => $field->getLabel()])) . '" data-rich-toolbar hidden>';
            $first = true;
            $menus = '';
            foreach ($groups as $group) {
                $items = array_values(array_intersect($group, $toolbar));
                if ($items === []) { continue; }
                if (!$first) { $html .= '<span class="fa-rich-sep" aria-hidden="true"></span>'; }
                $first = false;
                $html .= '<span class="fa-rich-group">';
                foreach ($items as $command) {
                    $label = $t($labels[$command]);
                    $tip = $label . (isset($keys[$command]) ? ' (' . $keys[$command] . ')' : '');
                    $toggle = !in_array($command, ['image', 'video', 'embed', 'undo', 'redo', 'horizontalRule', 'textAlign', 'indent', 'outdent', 'clearFormatting'], true);
                    // With uploads, Image offers "Upload" and "From a link" in a small menu; alignment is a menu too.
                    $menu = ($command === 'image' && ($config['uploads'] || $config['library'])) || $command === 'textAlign' ? ($command === 'image' ? 'image' : 'align') : null;
                    $html .= '<button type="button" class="fa-rich-btn' . ($menu !== null ? ' has-menu' : '') . '" data-command="' . $command . '" title="' . Str::e($tip) . '" aria-label="' . Str::e($label) . '"'
                        . ($toggle ? ' aria-pressed="false"' : '') . ($menu !== null ? ' aria-haspopup="menu" aria-expanded="false" aria-controls="' . Str::e($id . '-' . $menu . '-menu') . '"' : '')
                        . (in_array($command, ['link', 'embed'], true) || ($command === 'image' && $menu === null) ? ' aria-haspopup="dialog"' : '') . ' tabindex="-1">'
                        . '<span class="fa-rich-btn-icon" data-rich-icon>' . EditorIcons::svg($command === 'textAlign' ? 'alignLeft' : $command) . '</span>' . ($menu !== null ? $caret : '') . '</button>';
                    if ($menu === 'image') {
                        $menus .= '<div class="fa-rich-menu" id="' . Str::e($id . '-image-menu') . '" role="menu" aria-label="' . Str::e($label) . '" data-rich-menu="image" hidden>'
                            . ($config['uploads'] ? '<button type="button" role="menuitem" class="fa-rich-menuitem" data-rich-action="image-upload" tabindex="-1">' . EditorIcons::svg('upload') . '<span><strong>' . Str::e($t('image_upload')) . '</strong><small>' . Str::e($t('image_limit', ['size' => $this->bytes($field->getMaxImageBytes())])) . '</small></span></button>' : '')
                            . ($config['library'] ? '<button type="button" role="menuitem" class="fa-rich-menuitem" data-rich-action="image-library" tabindex="-1">' . EditorIcons::svg('library') . '<span><strong>' . Str::e(AdminLang::get('media.choose_from_library')) . '</strong><small>' . Str::e(AdminLang::get('media.choose_from_library_help')) . '</small></span></button>' : '')
                            . '<button type="button" role="menuitem" class="fa-rich-menuitem" data-rich-action="image-link" tabindex="-1">' . EditorIcons::svg('fromLink') . '<span><strong>' . Str::e($t('image_from_link')) . '</strong><small>' . Str::e($t('image_from_link_help')) . '</small></span></button>'
                            . '</div>';
                    }
                    if ($menu === 'align') {
                        $menus .= '<div class="fa-rich-menu is-compact" id="' . Str::e($id . '-align-menu') . '" role="menu" aria-label="' . Str::e($label) . '" data-rich-menu="align" hidden>';
                        foreach (['left' => 'alignLeft', 'center' => 'alignCenter', 'right' => 'alignRight', 'justify' => 'alignJustify'] as $align => $icon) {
                            $menus .= '<button type="button" role="menuitemradio" aria-checked="false" class="fa-rich-menuitem" data-rich-action="align-' . $align . '" tabindex="-1">' . EditorIcons::svg($icon) . '<span><strong>' . Str::e($t('align_' . $align)) . '</strong></span></button>';
                        }
                        $menus .= '</div>';
                    }
                }
                $html .= '</span>';
            }
            $html .= $menus;
            $html .= '</div>' . $this->richDialogs($field, $id, $config, $toolbar, $t);
        }

        foreach ($languages as $lang) {
            $isDefault = $lang === '' || $lang === $this->defaultLang;
            $inputId = $isDefault ? $id : $id . '-' . $lang;
            $value = $translatable ? (string) (is_array($values) ? ($values[$lang] ?? '') : '') : (is_array($values) ? '' : (string) $values);
            $direction = $lang === '' ? null : Languages::direction($lang);
            $controlAttributes = $lang === '' ? [] : ['dir' => (string) $direction, 'lang' => $lang];
            $describedBy = [];
            if ($isDefault && $field->getHelp() !== null) { $describedBy[] = $id . '-help'; }
            if ($isDefault && isset($this->errors[$name])) { $describedBy[] = $id . '-error'; $controlAttributes['aria-invalid'] = 'true'; }
            if (!$isDefault && isset($langErrors[$lang])) { $describedBy[] = $inputId . '-error'; $controlAttributes['aria-invalid'] = 'true'; }
            if ($describedBy !== []) { $controlAttributes['aria-describedby'] = implode(' ', $describedBy); }
            $html .= '<div class="fa-rich-panel" id="' . Str::e($id . '-' . ($lang === '' ? 'value' : $lang) . '-panel') . '"' . ($lang === '' ? '' : ' data-lang="' . Str::e($lang) . '" dir="' . $direction . '" lang="' . Str::e($lang) . '"')
                . ($tabbed ? ' aria-labelledby="' . Str::e($id . '-' . $lang . '-tab') . '"' : '') . '>';
            if ($tabbed) {
                $html .= '<p class="fa-rich-lang" data-rich-lang-title><span class="fa-code">' . Str::e($lang) . '</span> ' . Str::e(Languages::name($lang)) . '</p>';
            }
            $html .= $field->renderControl($inputId, $translatable ? $name . '[' . $lang . ']' : $name, $value, $controlAttributes);
            if (!$isDefault && isset($langErrors[$lang])) {
                $html .= '<p class="fa-error" role="alert" id="' . Str::e($inputId . '-error') . '">' . Str::e($langErrors[$lang]) . '</p>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    private function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : (int) ceil($bytes / 1024) . ' KB';
    }

    /**
     * Modal dialogs for the link, image-from-link and video-link inputs, the
     * hidden file pickers, and the upload status bar. The dialogs are native
     * <dialog> elements opened with showModal() above the form (or the
     * resource dialog), and use plain buttons: a <form> cannot sit inside a form.
     *
     * @param array<string, mixed> $config
     * @param list<string> $toolbar
     */
    private function richDialogs(Fields\RichEditor $field, string $id, array $config, array $toolbar, \Closure $t): string
    {
        $dialog = function (string $name, string $title, string $body, string $apply, string $extra = '') use ($id, $t): string {
            $dialogId = $id . '-' . $name . '-dialog';
            return '<dialog class="fa-rich-dialog" id="' . Str::e($dialogId) . '" data-rich-dialog="' . $name . '" aria-labelledby="' . Str::e($dialogId . '-title') . '">'
                . '<div class="fa-rich-dialog-head"><h2 class="fa-rich-dialog-title" id="' . Str::e($dialogId . '-title') . '">' . Str::e($title) . '</h2>'
                . '<button type="button" class="fa-rich-dialog-close" data-rich-cancel aria-label="' . Str::e(AdminLang::get('actions.cancel')) . '">' . EditorIcons::svg('close') . '</button></div>'
                . '<div class="fa-rich-dialog-body">' . $body . '<p class="fa-rich-dialog-error" role="alert" data-rich-error hidden></p></div>'
                . '<div class="fa-rich-dialog-foot">' . $extra . '<span class="fa-spacer"></span>'
                . '<button type="button" class="fa-btn" data-rich-cancel>' . Str::e(AdminLang::get('actions.cancel')) . '</button>'
                . '<button type="button" class="fa-btn fa-btn-primary" data-rich-apply>' . Str::e($apply) . '</button></div></dialog>';
        };
        $input = static fn (string $key, string $label, string $type, string $attributes, string $help = ''): string => '<div class="fa-rich-dialog-field">'
            . '<label class="fa-rich-dialog-label" for="' . Str::e($id . '-' . $key) . '">' . Str::e($label) . '</label>'
            . '<input class="fa-input" type="' . $type . '" id="' . Str::e($id . '-' . $key) . '" ' . $attributes . '>'
            . ($help === '' ? '' : '<p class="fa-help">' . Str::e($help) . '</p>') . '</div>';
        $html = '';
        if (in_array('link', $toolbar, true)) {
            $html .= $dialog('link', $t('link_title'),
                $input('link-url', $t('link_url'), 'url', 'data-rich-url inputmode="url" autocomplete="url" placeholder="https://"')
                . '<label class="fa-check fa-rich-check"><input type="checkbox" data-rich-blank> ' . Str::e($t('link_new_tab')) . '</label>',
                $t('apply'), '<button type="button" class="fa-btn fa-btn-ghost fa-btn-danger-text" data-rich-remove>' . EditorIcons::svg('unlink') . '<span>' . Str::e($t('link_remove')) . '</span></button>');
        }
        if (in_array('image', $toolbar, true)) {
            $html .= $dialog('image', $t('image_title'),
                $input('image-url', $t('image_url'), 'url', 'data-rich-url inputmode="url" placeholder="https://"')
                . $input('image-alt', $t('image_alt'), 'text', 'data-rich-alt maxlength="300"', $t('image_alt_help'))
                . $input('image-caption', $t('caption'), 'text', 'data-rich-caption maxlength="300"', $t('caption_help'))
                . $input('image-href', $t('image_link'), 'url', 'data-rich-href inputmode="url" placeholder="https://"', $t('image_link_help')), $t('insert'));
        }
        if (in_array('embed', $toolbar, true)) {
            $providers = implode(' / ', array_map(static fn (string $p): string => $p === 'youtube' ? 'YouTube' : 'Vimeo', $field->getEmbeds()));
            $html .= $dialog('embed', $t('embed_title'),
                $input('embed-url', $t('embed_url', ['providers' => $providers]), 'url', 'data-rich-url inputmode="url" placeholder="https://www.youtube.com/watch?v=…"', $t('embed_help')), $t('insert'));
        }
        if ($config['uploads'] && in_array('image', $toolbar, true)) {
            $html .= '<input type="file" class="fa-rich-file-input" accept="image/jpeg,image/png,image/gif,image/webp" data-rich-file="image" tabindex="-1" aria-hidden="true" hidden>';
        }
        if ($config['uploads'] && in_array('video', $toolbar, true)) {
            $html .= '<input type="file" class="fa-rich-file-input" accept="video/mp4,video/webm" data-rich-file="video" tabindex="-1" aria-hidden="true" hidden>';
        }
        // Options for a selected image, video or embed: position, width, edit, replace, remove.
        $media = static fn (string $action, string $icon, string $label, string $extra = ''): string => '<button type="button" class="fa-rich-btn" data-media="' . $action . '" title="' . Str::e($label) . '" aria-label="' . Str::e($label) . '"' . $extra . ' tabindex="-1">' . ($icon !== '' ? EditorIcons::svg($icon) : '') . '</button>';
        $bar = '<span class="fa-rich-group">';
        foreach (['left' => 'mediaLeft', 'center' => 'mediaCenter', 'right' => 'mediaRight'] as $position => $icon) { $bar .= $media('align-' . $position, $icon, $t('media_' . $position), ' aria-pressed="false"'); }
        $bar .= '</span><span class="fa-rich-sep" aria-hidden="true"></span><span class="fa-rich-group">';
        foreach (['left' => 'wrapLeft', 'right' => 'wrapRight'] as $position => $icon) { $bar .= $media('wrap-' . $position, $icon, $t('wrap_' . $position), ' aria-pressed="false"'); }
        $bar .= '</span><span class="fa-rich-sep" aria-hidden="true"></span><span class="fa-rich-group fa-rich-sizes">';
        foreach ([25, 50, 75, 100] as $width) {
            $bar .= '<button type="button" class="fa-rich-btn fa-rich-size" data-media="width-' . $width . '" aria-pressed="false" title="' . Str::e($t('width_to', ['size' => $width])) . '" aria-label="' . Str::e($t('width_to', ['size' => $width])) . '" tabindex="-1">' . $width . '%</button>';
        }
        $bar .= '</span><span class="fa-rich-sep" aria-hidden="true" data-media-only="image"></span><span class="fa-rich-group" data-media-only="image">' . $media('edit', 'edit', $t('image_edit_title'))
            . ($config['uploads'] ? $media('replace', 'replace', $t('image_replace')) : '') . '</span><span class="fa-rich-sep" aria-hidden="true"></span><span class="fa-rich-group">' . $media('remove', 'trash', $t('media_remove')) . '</span>';
        if (array_intersect(['image', 'video', 'embed'], $toolbar) !== []) {
            $html .= '<div class="fa-rich-mediabar" role="toolbar" aria-label="' . Str::e($t('media_toolbar')) . '" data-rich-mediabar hidden>' . $bar . '</div>';
        }

        // Upload progress and errors, at the bottom edge of the editor.
        return $html . '<div class="fa-rich-status" data-rich-status hidden><span class="fa-rich-status-text" role="status" aria-live="polite" data-rich-status-text></span>'
            . '<span class="fa-rich-status-bar" aria-hidden="true"><span data-rich-status-bar></span></span>'
            . '<button type="button" class="fa-rich-status-close" data-rich-status-cancel aria-label="' . Str::e(AdminLang::get('actions.cancel')) . '">' . EditorIcons::svg('close') . '</button></div>';
    }

    /** @return array<string, string> lang => message (other languages; the default one is reported on the field) */
    private function languageErrors(string $name): array
    {
        $errors = [];
        foreach ($this->languages as $lang) {
            $key = $name . '.' . $lang;
            if (isset($this->errors[$key])) {
                $errors[$lang] = $this->errors[$key];
            }
        }

        return $errors;
    }

    private function stringState(Field $field): string
    {
        $value = $this->state[$field->getName()] ?? '';

        return is_array($value) ? '' : $value;
    }
}
