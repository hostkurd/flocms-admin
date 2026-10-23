<?php
declare(strict_types=1);

namespace FloCMS\Admin\View;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Layout\Grid;
use FloCMS\Admin\Form\Layout\Layout;
use FloCMS\Admin\Form\Layout\Tabs;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Column;

/**
 * The view page's fields (0.27): the form's sections, tabs, grid and aside,
 * with each field shown as a label and its value. Sections are cards; tabs
 * follow one another with their names as headings (everything is in view,
 * and prints in full); fields that hold a lot (rich text, lists) take the
 * whole row.
 *
 * @internal
 */
final class RecordView
{
    /** @var array<string, mixed> */
    private array $state = [];
    private Form $resolved;
    private ValueRenderer $values;
    /** @var array<string, bool> languages with something not translated */
    private array $missing = [];

    /**
     * @param list<string> $languages
     * @param array<string, Column> $columns
     */
    public function __construct(
        private readonly Resource $resource,
        private readonly Form $form,
        private readonly object $record,
        private readonly string $lang,
        private readonly string $defaultLang,
        private readonly array $languages,
        array $columns = [],
    ) {
        foreach ($form->fields() as $name => $field) {
            $this->state[$name] = $field->stateFromRecord($record, $defaultLang);
        }
        $state = $this->state;
        $this->resolved = $form->evaluate($state, $record);
        $this->values = new ValueRenderer($resource, $record, $lang, $defaultLang, $columns);
        foreach ($this->resolved->fields() as $name => $field) {
            if (!$this->shows($field) || !$field->isTranslatable()) { continue; }
            foreach ($languages as $code) {
                if ($code === $defaultLang) { continue; }
                $check = new ValueRenderer($resource, $record, $code, $defaultLang);
                if ($check->missing($field, $this->state[$name] ?? '')) { $this->missing[$code] = true; }
            }
        }
    }

    /** Languages (other than the default) with at least one field not translated. */
    public function missingLanguages(): array
    {
        return array_keys($this->missing);
    }

    public function hasTranslatableFields(): bool
    {
        foreach ($this->resolved->fields() as $field) {
            if ($this->shows($field) && $field->isTranslatable()) { return true; }
        }
        return false;
    }

    public function render(): string
    {
        $main = $this->cards($this->form->components(), $this->form->getColumns(), 'main');
        if ($this->form->asideComponents() === []) {
            return '<div class="fa-view-layout"><div class="fa-view-main">' . $main . '</div></div>';
        }
        $aside = $this->cards($this->form->asideComponents(), 1, 'aside');
        return '<div class="fa-view-layout has-aside"><div class="fa-view-main">' . $main . '</div><div class="fa-view-aside">' . $aside . '</div></div>';
    }

    /**
     * Top-level parts as cards: each section is a card; fields outside a
     * section share one card per run.
     *
     * @param list<Field|Layout> $nodes
     */
    private function cards(array $nodes, int $columns, string $path): string
    {
        $html = '';
        $loose = [];
        $flush = function () use (&$loose, &$html, $columns): void {
            $body = $this->nodes($loose, $columns, 'loose');
            if ($body !== '') { $html .= '<section class="fa-view-card"><div class="fa-view-grid fa-grid-' . $columns . '">' . $body . '</div></section>'; }
            $loose = [];
        };
        foreach ($nodes as $index => $node) {
            // A wizard (0.39): each step is a card of its own.
            if ($node instanceof \FloCMS\Admin\Form\Layout\Wizard) { $flush(); $html .= $this->cards($node->children(), $columns, $path . '-' . $index); continue; }
            if ($node instanceof Layout && !$node instanceof Grid) {
                $flush();
                $id = 'fa-view-' . $path . '-' . $index;
                $body = $node instanceof Tabs ? $this->tabs($node, $id) : $this->nodes($node->children(), $node->getColumns(), $id);
                if ($body === '') { continue; }
                $description = $node->getDescription() !== null ? '<p class="fa-view-description">' . Str::e($node->getDescription()) . '</p>' : '';
                $grid = $node instanceof Tabs ? $body : '<div class="fa-view-grid fa-grid-' . $node->getColumns() . '">' . $body . '</div>';
                $html .= '<section class="fa-view-card" aria-labelledby="' . $id . '-title"><h2 class="fa-view-card-title" id="' . $id . '-title">' . Str::e($node->getLabel()) . '</h2>' . $description . $grid . '</section>';
                continue;
            }
            $loose[] = $node;
        }
        $flush();
        return $html;
    }

    private function tabs(Tabs $tabs, string $id): string
    {
        $html = '';
        foreach ($tabs->children() as $index => $tab) {
            $body = $this->nodes($tab->children(), $tab->getColumns(), $id . '-' . $index);
            if ($body === '') { continue; }
            $html .= '<div class="fa-view-group"><h3 class="fa-view-group-title">' . Str::e($tab->getLabel()) . '</h3><div class="fa-view-grid fa-grid-' . $tab->getColumns() . '">' . $body . '</div></div>';
        }
        return $html;
    }

    /** @param list<Field|Layout> $nodes */
    private function nodes(array $nodes, int $columns, string $path): string
    {
        $html = '';
        foreach ($nodes as $index => $node) {
            if ($node instanceof Field) {
                $field = $this->resolved->field($node->getName());
                if ($field === null || !$this->shows($field)) { continue; }
                $html .= $this->field($field, $columns);
                continue;
            }
            if ($node instanceof Tabs) {
                $body = $this->tabs($node, $path . '-' . $index);
                if ($body !== '') { $html .= '<div class="fa-view-nested fa-span-full">' . $body . '</div>'; }
                continue;
            }
            $body = $this->nodes($node->children(), $node->getColumns(), $path . '-' . $index);
            if ($body === '') { continue; }
            if ($node instanceof Grid) {
                $html .= '<div class="fa-view-grid fa-span-full fa-grid-' . $node->getColumns() . '">' . $body . '</div>';
                continue;
            }
            $html .= '<div class="fa-view-group fa-span-full"><h3 class="fa-view-group-title">' . Str::e($node->getLabel()) . '</h3>'
                . ($node->getDescription() !== null ? '<p class="fa-view-description">' . Str::e($node->getDescription()) . '</p>' : '')
                . '<div class="fa-view-grid fa-grid-' . $node->getColumns() . '">' . $body . '</div></div>';
        }
        return $html;
    }

    private function field(Field $field, int $columns): string
    {
        $classes = ['fa-view-field'];
        $span = $field->getColumnSpan();
        if ($span === 'full' || $field->isLong() || $field instanceof Fields\MediaPicker) { $classes[] = 'fa-span-full'; }
        elseif (is_int($span) && $span > 1) { $classes[] = 'fa-span-' . min($span, $columns); }
        $state = $this->state[$field->getName()] ?? '';
        $dir = $field->isTranslatable() ? ' dir="' . (\FloCMS\Admin\Translation\Languages::isRtl($this->lang) ? 'rtl' : 'ltr') . '" lang="' . Str::e($this->lang) . '"' : '';
        return '<dl class="' . implode(' ', $classes) . '" data-field="' . Str::e($field->getName()) . '"><dt>' . Str::e($field->getLabel()) . '</dt><dd' . $dir . '>' . $this->values->render($field, $state) . '</dd></dl>';
    }

    /** Fields never shown: hidden ones, passwords, hidden by a condition or by hiddenOnView(). */
    private function shows(Field $field): bool
    {
        if (!$field->isVisible() || $field->isOmitted() || $field->isHiddenOnView() || $field instanceof Fields\Hidden) { return false; }
        if ($field instanceof Fields\TextInput && $field->isPassword()) { return false; }
        return true;
    }
}
