<?php
declare(strict_types=1);

namespace FloCMS\Admin\View;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Layout\Layout;
use FloCMS\Admin\Form\WizardFlow;
use FloCMS\Admin\Form\WizardState;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Languages;

/**
 * A wizard's review step (0.39): the answers so far, grouped by step, each group
 * with an Edit button back to its step. Values are shown as on the view page;
 * hidden steps, hidden fields, passwords and hiddenOnView() fields are left out.
 *
 * @internal
 */
final class WizardSummary
{
    /** null unless the review step is shown. */
    public static function closure(Resource $resource, Form $form, array $state, ?object $record, WizardState $wizard, ?string $lang = null): ?\Closure
    {
        // Built only when the review step is the one shown.
        if ($wizard->step !== WizardFlow::REVIEW) { return null; }
        return static fn (): string => self::render($resource, $form, $state, $record, $wizard, $lang ?? Languages::default());
    }

    public static function render(Resource $resource, Form $form, array $state, ?object $record, WizardState $wizard, string $lang): string
    {
        $resolved = $form->evaluate($state, $record);
        $values = new ValueRenderer($resource, $record, $lang, Languages::default());
        $html = '';
        $number = 0;
        foreach ($form->wizard()?->steps() ?? [] as $step) {
            if (!in_array($step->getName(), $wizard->visible, true)) { continue; }
            $number++;
            $rows = '';
            foreach ($form->stepFields($step->getName()) as $name) {
                $field = $resolved->field($name);
                if ($field === null || !self::shows($field)) { continue; }
                $value = $state[$name] ?? '';
                $shown = $field instanceof Fields\FileUpload && is_string($value) && str_starts_with($value, '@upload:') ? self::pending($value) : $values->render($field, $value);
                $rows .= '<dt>' . Str::e($field->getLabel()) . '</dt><dd>' . $shown . '</dd>';
            }
            $id = 'fa-wizard-review-' . $step->getName();
            $html .= '<section class="fa-wizard-group" aria-labelledby="' . Str::e($id) . '"><div class="fa-wizard-group-head"><span class="fa-wizard-group-number" aria-hidden="true">' . $number . '</span>'
                . '<h3 id="' . Str::e($id) . '">' . Str::e($step->getLabel()) . '</h3>'
                . '<button type="submit" class="fa-btn fa-btn-sm" name="_wizard" value="' . Str::e('go:' . $step->getName()) . '" formnovalidate aria-label="' . Str::e(AdminLang::get('wizard.edit_step', ['step' => $step->getLabel()])) . '">' . Str::e(AdminLang::get('actions.edit')) . '</button></div>'
                . ($rows !== '' ? '<dl class="fa-wizard-answers">' . $rows . '</dl>' : '<p class="fa-wizard-none">' . Str::e(AdminLang::get('wizard.nothing')) . '</p>') . '</section>';
        }
        return $html;
    }

    /** A file chosen in an earlier step and kept until the save: its name. */
    private static function pending(string $token): string
    {
        try { $entry = \FloCMS\Admin\Uploads\Uploads::entry($token); }
        catch (\Throwable) { return ValueRenderer::empty(); }
        return '<span class="fa-wizard-file">' . Str::e((string) $entry['name']) . '</span> <span class="fa-help">' . Str::e(AdminLang::get('wizard.file_kept')) . '</span>';
    }

    private static function shows(Field $field): bool
    {
        if (!$field->isVisible() || $field->isOmitted() || $field->isHiddenOnView() || $field instanceof Fields\Hidden || $field instanceof Fields\Placeholder) { return false; }
        return !($field instanceof Fields\TextInput && $field->isPassword());
    }
}
