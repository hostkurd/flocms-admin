<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

use FloCMS\Admin\Form\Fields\Hidden;
use FloCMS\Admin\Support\AdminLang;
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
        private readonly array $state,
        private readonly array $errors,
        private readonly array $languages,
        private readonly string $defaultLang,
        private readonly bool $isEdit = false,
        private readonly bool $inlineTranslations = false,
        private readonly string $prefix = ''
    ) {
    }

    public function render(Form $form): string
    {
        $hidden = '';
        $html = '';

        foreach ($form->fields() as $field) {
            if ($field instanceof Hidden) {
                $hidden .= $field->renderControl($this->prefix . 'f-' . $field->getName(), $field->getName(), $this->stringState($field));
                continue;
            }
            $html .= $this->field($field);
        }

        return $hidden . '<div class="fa-form-grid">' . $html . '</div>';
    }

    public function field(Field $field): string
    {
        $name = $field->getName();
        $id = $this->prefix . 'f-' . $name;
        $error = $this->errors[$name] ?? null;
        $langErrors = $field->isTranslatable() ? $this->languageErrors($name) : [];

        $classes = ['fa-field'];
        if ($field->getColumnSpan() === 2) {
            $classes[] = 'fa-span-2';
        }
        if ($error !== null || $langErrors !== []) {
            $classes[] = 'has-error';
        }

        $label = Str::e($field->getLabel());
        if ($field->isRequired() || ($field->isRequiredOnCreate() && !$this->isEdit)) {
            $label .= ' <span class="fa-required" aria-hidden="true">*</span>';
        }

        $html = '<div class="' . implode(' ', $classes) . '" data-field="' . Str::e($name) . '">';
        $html .= '<label class="fa-label" for="' . Str::e($id) . '">' . $label . '</label>';
        $html .= $field->isTranslatable() ? $this->translatable($field, $id, $langErrors) : $this->single($field, $id);

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
        $describedBy = [];
        if ($field->getHelp() !== null) { $describedBy[] = $id . '-help'; }
        if (isset($this->errors[$field->getName()])) { $describedBy[] = $id . '-error'; }
        if ($describedBy !== []) { $attributes['aria-describedby'] = implode(' ', $describedBy); }
        if (isset($this->errors[$field->getName()])) {
            $attributes['aria-invalid'] = 'true';
        }

        return $field->renderControl($id, $field->getName(), $this->stringState($field), $attributes);
    }

    /** @param array<string, string> $langErrors */
    private function translatable(Field $field, string $id, array $langErrors): string
    {
        if ($this->inlineTranslations) { return $this->inlineTranslations($field, $id); }
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
        $badge = '';
        foreach ($this->languages as $lang) {
            $filled = ($values[$lang] ?? '') !== '';
            if (!$filled) {
                $missing[] = $lang;
            }
            $state = $lang === $default ? 'is-default' : ($filled ? 'is-filled' : 'is-empty');
            if (isset($langErrors[$lang])) {
                $state .= ' is-invalid';
            }
            $badge .= '<span class="fa-code ' . $state . '">' . Str::e($lang) . '</span>';
        }

        $title = $missing === []
            ? AdminLang::get('translations.all_filled')
            : AdminLang::get('translations.missing', ['languages' => implode(', ', $missing)]);

        $html = '<div class="fa-input-row">' . $control;
        $html .= '<button type="button" class="fa-lang-badge' . ($langErrors !== [] ? ' is-invalid' : '') . '"'
            . ' data-dialog="' . Str::e($dialogId) . '" aria-haspopup="dialog" aria-controls="' . Str::e($dialogId) . '"'
            . ' data-missing-text="' . Str::e(AdminLang::get('translations.missing', ['languages' => ''])) . '"'
            . ' data-complete-text="' . Str::e(AdminLang::get('translations.all_filled')) . '"'
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
                . '<span class="fa-lang-name">' . Str::e(Languages::name($lang)) . '</span> '
                . '<code class="fa-lang-code">' . Str::e($lang) . '</code>'
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
            . '<button type="button" class="fa-btn fa-btn-primary" data-dialog-save>' . Str::e(AdminLang::get('actions.save')) . '</button>'
            . '<button type="button" class="fa-btn" data-dialog-cancel>' . Str::e(AdminLang::get('actions.cancel')) . '</button>'
            . '</div></dialog>';

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

    /** Inline languages avoid stacking translation dialogs inside a resource editor. */
    private function inlineTranslations(Field $field, string $id): string
    {
        $values = $this->state[$field->getName()] ?? [];
        $values = is_array($values) ? $values : [];
        $html = '<div class="fa-translations-inline">';
        foreach ($this->languages as $lang) {
            $default = $lang === $this->defaultLang;
            $inputId = $default ? $id : $id . '-' . $lang;
            $key = $field->getName() . ($default ? '' : '.' . $lang);
            $error = $this->errors[$key] ?? null;
            $html .= '<div class="fa-lang-row"><label class="fa-lang-label" for="' . Str::e($inputId) . '">' . Str::e(Languages::name($lang)) . ' <code>' . Str::e($lang) . '</code></label>';
            $attributes = ['dir' => Languages::direction($lang), 'lang' => $lang];
            $described = [];
            if ($field->getHelp() !== null) { $described[] = $id . '-help'; }
            if ($error !== null) { $attributes['aria-invalid'] = 'true'; $described[] = $inputId . '-error'; }
            if ($described !== []) { $attributes['aria-describedby'] = implode(' ', $described); }
            $html .= $field->renderControl($inputId, $field->getName() . '[' . $lang . ']', $values[$lang] ?? '', $attributes);
            if ($error !== null && !$default) { $html .= '<p class="fa-error" id="' . Str::e($inputId) . '-error" role="alert">' . Str::e($error) . '</p>'; }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    private function stringState(Field $field): string
    {
        $value = $this->state[$field->getName()] ?? '';

        return is_array($value) ? '' : $value;
    }
}
