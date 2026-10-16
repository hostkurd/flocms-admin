<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

use FloCMS\Admin\Form\Fields\Hidden;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
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
        private readonly ?string $editorKey = null
    ) {
    }

    public function render(Form $form): string
    {
        $resolved = $form->evaluate($this->state);
        $html = $this->nodes($form->components(), $resolved, $form->getColumns(), $form->liveFields(), 'layout');
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
                $html .= '<section class="fa-form-section fa-span-full"' . ($title !== '' ? ' aria-labelledby="' . Str::e($id . '-title') . '"' : '') . '>' . $title . $description . $this->nodes($node->children(), $form, $node->getColumns(), $live, $id) . '</section>';
            }
        }
        return '<div class="fa-form-grid fa-grid-' . $columns . '">' . $html . '</div>';
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

        $html = '<div class="' . implode(' ', $classes) . '" data-field="' . Str::e($name) . '">';
        $html .= '<label class="fa-label" for="' . Str::e($id) . '">' . $label . '</label>';
        if ($field instanceof Fields\RichEditor) {
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

        // The row takes the default language's direction, so the badge sits at the
        // end of that text (left for Arabic content, right for English) on any page.
        $html = '<div class="fa-input-row' . (count($this->languages) > 6 ? ' is-many' : '') . '" dir="' . Languages::direction($default) . '">' . $control;
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
            'maxImage' => $field->getMaxImageBytes(), 'maxVideo' => $field->getMaxVideoBytes(), 'csrf' => \FloCMS\Core\Csrf::token(),
        ];
        $t = static fn (string $key, array $replace = []): string => AdminLang::get('editor.' . $key, $replace);
        $strings = [];
        foreach (['uploading', 'image_invalid', 'video_invalid', 'upload_failed', 'embed_invalid', 'link_invalid', 'too_large', 'progress',
            'youtube', 'vimeo', 'video_file', 'empty', 'filled'] as $key) { $strings[$key] = $t($key); }

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

        // Toolbar and insert panels are shown by the script once the editor runs.
        if (!$field->isDisabled()) {
            $buttons = ['heading2' => ['H2', 'heading2'], 'heading3' => ['H3', 'heading3'], 'bold' => ['bold', 'bold'], 'italic' => ['italic', 'italic'],
                'underline' => ['underline', 'underline'], 'strike' => ['strike', 'strike'], 'link' => ['link', 'link'], 'bulletList' => ['list', 'bullet_list'],
                'orderedList' => ['list-ordered', 'ordered_list'], 'blockquote' => ['quote', 'blockquote'], 'codeBlock' => ['code', 'code_block'],
                'horizontalRule' => ['minus', 'horizontal_rule'], 'image' => ['image', 'image'], 'video' => ['video', 'video'], 'embed' => ['play', 'embed'],
                'undo' => ['undo', 'undo'], 'redo' => ['redo', 'redo']];
            $groups = [['heading2', 'heading3'], ['bold', 'italic', 'underline', 'strike', 'link'], ['bulletList', 'orderedList', 'blockquote', 'codeBlock', 'horizontalRule'], ['image', 'video', 'embed'], ['undo', 'redo']];
            $html .= '<div class="fa-rich-toolbar" role="toolbar" aria-label="' . Str::e($t('toolbar', ['field' => $field->getLabel()])) . '" data-rich-toolbar hidden>';
            $first = true;
            foreach ($groups as $group) {
                $items = array_values(array_intersect($group, $toolbar));
                if ($items === []) { continue; }
                if (!$first) { $html .= '<span class="fa-rich-sep" aria-hidden="true"></span>'; }
                $first = false;
                foreach ($items as $command) {
                    [$icon, $key] = $buttons[$command];
                    $label = $t($key);
                    $content = str_starts_with($icon, 'H') ? '<span class="fa-rich-heading" aria-hidden="true">' . $icon . '</span>' : Icons::svgFor($icon);
                    $toggle = !in_array($command, ['image', 'video', 'embed', 'undo', 'redo', 'horizontalRule'], true);
                    $html .= '<button type="button" class="fa-rich-btn" data-command="' . $command . '" title="' . Str::e($label) . '" aria-label="' . Str::e($label) . '"'
                        . ($toggle ? ' aria-pressed="false"' : '') . ' tabindex="-1">' . $content . '</button>';
                }
            }
            $html .= '</div>' . $this->richInsertPanels($field, $id, $config, $toolbar, $t);
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

    /** @param array<string, mixed> $config */
    private function richInsertPanels(Fields\RichEditor $field, string $id, array $config, array $toolbar, \Closure $t): string
    {
        $size = static fn (int $bytes): string => $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : (int) ceil($bytes / 1024) . ' KB';
        $button = static fn (string $attribute, string $text, string $style = ''): string => '<button type="button" class="fa-btn fa-btn-sm' . ($style === '' ? '' : ' ' . $style) . '" ' . $attribute . '>' . Str::e($text) . '</button>';
        $input = static fn (string $key, string $label, string $type, string $attribute, string $extra = ''): string => '<label class="fa-rich-insert-label" for="' . Str::e($id . '-' . $key) . '">' . Str::e($label) . '</label>'
            . '<input class="fa-input" type="' . $type . '" id="' . Str::e($id . '-' . $key) . '" ' . $attribute . $extra . '>';
        $actions = static fn (string $apply, string $extra = ''): string => '<div class="fa-rich-insert-actions">' . $extra . '<span class="fa-spacer"></span>'
            . $button('data-insert-cancel', AdminLang::get('actions.cancel'), 'fa-btn-ghost') . $button('data-insert-apply', $apply, 'fa-btn-primary') . '</div>';
        $status = '<p class="fa-help" role="status" data-insert-status></p>';
        $html = '';
        if (in_array('link', $toolbar, true)) {
            $html .= '<div class="fa-rich-insert" data-insert="link" hidden>'
                . $input('link', $t('link_url'), 'url', 'data-insert-url', ' inputmode="url" autocomplete="url" placeholder="https://"')
                . '<label class="fa-check"><input type="checkbox" data-insert-blank> ' . Str::e($t('link_new_tab')) . '</label>' . $status
                . $actions($t('apply'), $button('data-insert-remove', $t('link_remove'), 'fa-btn-ghost')) . '</div>';
        }
        if (in_array('image', $toolbar, true)) {
            $upload = $config['uploads'] ? '<label class="fa-btn fa-btn-sm fa-rich-file">' . Icons::svgFor('upload') . '<span>' . Str::e($t('image_upload')) . '</span>'
                . '<input type="file" class="fa-sr" accept="image/jpeg,image/png,image/gif,image/webp" data-insert-file></label>'
                . '<p class="fa-help">' . Str::e($t('image_limit', ['size' => $size($field->getMaxImageBytes())])) . '</p><p class="fa-rich-or">' . Str::e($t('or')) . '</p>' : '';
            $html .= '<div class="fa-rich-insert" data-insert="image" hidden>' . $upload
                . $input('image-url', $t('image_url'), 'url', 'data-insert-url', ' inputmode="url" placeholder="https://"')
                . $input('image-alt', $t('image_alt'), 'text', 'data-insert-alt', ' maxlength="300"')
                . '<p class="fa-help">' . Str::e($t('image_alt_help')) . '</p>' . $status . $actions($t('insert')) . '</div>';
        }
        if (in_array('video', $toolbar, true)) {
            $html .= '<div class="fa-rich-insert" data-insert="video" hidden>'
                . '<label class="fa-btn fa-btn-sm fa-rich-file">' . Icons::svgFor('upload') . '<span>' . Str::e($t('video_upload')) . '</span>'
                . '<input type="file" class="fa-sr" accept="video/mp4,video/webm" data-insert-file></label>'
                . '<p class="fa-help">' . Str::e($t('video_limit', ['size' => $size($field->getMaxVideoBytes())])) . '</p>'
                . '<progress class="fa-rich-progress" max="100" value="0" data-insert-progress hidden></progress>' . $status
                . '<div class="fa-rich-insert-actions"><span class="fa-spacer"></span>' . $button('data-insert-cancel', AdminLang::get('actions.cancel'), 'fa-btn-ghost') . '</div></div>';
        }
        if (in_array('embed', $toolbar, true)) {
            $providers = implode(' / ', array_map(static fn (string $p): string => $p === 'youtube' ? 'YouTube' : 'Vimeo', $field->getEmbeds()));
            $html .= '<div class="fa-rich-insert" data-insert="embed" hidden>'
                . $input('embed', $t('embed_url', ['providers' => $providers]), 'url', 'data-insert-url', ' inputmode="url" placeholder="https://www.youtube.com/watch?v=…"')
                . '<p class="fa-help">' . Str::e($t('embed_help')) . '</p>' . $status . $actions($t('insert')) . '</div>';
        }
        return $html;
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
