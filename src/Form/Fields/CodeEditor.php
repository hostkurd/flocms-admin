<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;

/**
 * HTML code (0.30): a monospaced editor with line numbers, highlighting and
 * Format (resources/js/code-editor.ts); without JavaScript a plain textarea.
 * The value is stored as typed, so show it only to people allowed to write
 * HTML (the Pages resource asks for blocks.html).
 *
 *     CodeEditor::make('html')->rows(24)
 */
class CodeEditor extends Textarea
{
    protected int $rows = 22;

    protected function setUp(): void
    {
        $this->columnSpan = 'full';
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $rows = (string) ($attributes['data-rows'] ?? $this->rows);
        unset($attributes['data-rows']);
        $attrs = $this->baseAttributes($id, $name, $attributes) + [
            'class' => 'fa-input fa-textarea fa-code-input',
            'rows' => $rows,
            'dir' => 'ltr',
            'spellcheck' => 'false',
            'autocapitalize' => 'off',
            'autocomplete' => 'off',
            'wrap' => 'off',
            'data-code-input' => true,
        ];
        $t = static fn (string $key): string => Str::e(AdminLang::get('code.' . $key));
        return '<div class="fa-code" data-code-editor data-language="html">'
            . '<div class="fa-code-bar" data-code-bar hidden><span class="fa-code-lang">HTML</span>'
            . '<span class="fa-code-pos" data-code-pos aria-live="off"></span>'
            . '<button type="button" class="fa-btn fa-btn-sm fa-btn-ghost" data-code-format>' . $t('format') . '</button></div>'
            . '<div class="fa-code-body"><pre class="fa-code-lines" aria-hidden="true" data-code-lines hidden></pre>'
            . '<div class="fa-code-stack"><pre class="fa-code-highlight" aria-hidden="true" data-code-highlight hidden><code></code></pre>'
            . '<textarea' . self::attributes($attrs) . '>' . Str::e($value) . '</textarea></div></div></div>';
    }
}
