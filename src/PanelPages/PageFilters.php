<?php
declare(strict_types=1);

namespace FloCMS\Admin\PanelPages;

use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Validation\FormValidator;

/**
 * A panel page's filters (0.36): a form read from the address (?filter[from]=…),
 * so a filtered page can be bookmarked and shared. Values that fail the
 * fields' rules are shown with their error and left out.
 *
 * @internal
 */
final class PageFilters
{
    /** The address marks a submitted filter form with filter[_]=1, so a cleared field stays cleared. */
    public const MARKER = '_';

    private Form $form;
    private bool $failed = false;
    /** @var array<string, string|array<string, string>> */
    private array $state = [];
    /** @var array<string, string> */
    private array $errors = [];
    /** @var array<string, mixed> */
    private array $values = [];
    /** @var array<string, string|array<string, string>> the submitted values, for the address */
    private array $active = [];

    /** @param array<string, mixed> $input the request's filter[...] values */
    public function __construct(AdminPage $page, array $input)
    {
        $this->form = $page->filters(new Form());
        foreach ($this->form->fields() as $field) {
            if ($field instanceof \FloCMS\Admin\Form\Fields\FileUpload || $field instanceof \FloCMS\Admin\Form\Fields\BelongsTo
                || $field instanceof \FloCMS\Admin\Form\Fields\BelongsToMany || $field instanceof \FloCMS\Admin\Form\Fields\Repeater
                || $field instanceof \FloCMS\Admin\Form\Fields\Builder || $field instanceof \FloCMS\Admin\Form\Fields\RichEditor
                || $field->isTranslatable()) {
                throw new \LogicException('Filter "' . $field->getName() . '": use simple fields in filters (text, select, date, toggle).');
            }
        }
        if ($this->form->fields() === []) { return; }
        // Given: the form was sent (its marker), or a link names a filter; else the fields' defaults.
        $given = ($input[self::MARKER] ?? null) === '1';
        foreach ($this->form->fields() as $name => $field) {
            $this->state[$name] = $field->stateFromInput($input, Languages::all());
            if (array_key_exists($name, $input) && $this->state[$name] !== '') { $given = true; }
        }
        if (!$given) {
            // No filter in the address: the fields' defaults.
            foreach ($this->form->fields() as $name => $field) { $this->state[$name] = $field->stateFromRecord(null, Languages::default()); }
        }
        $resolved = $this->form->evaluate($this->state);
        if ($given) {
            $errors = (new FormValidator(Languages::all(), Languages::default()))->validate($resolved, $this->state);
            $this->errors = $errors;
        }
        foreach ($resolved->fields() as $name => $field) {
            $state = $this->state[$name] ?? '';
            if ($state === '' || isset($this->errors[$name]) || $field instanceof \FloCMS\Admin\Form\Fields\Placeholder) { continue; }
            // A checkbox list or tags (0.37): the list itself
            $value = $field instanceof \FloCMS\Admin\Form\Fields\ListField ? $field->values($state) : $field->dehydrate($state);
            if ($value === null || $value === '' || $value === false || $value === []) { continue; }
            $this->values[$name] = $value;
            if ($given) { $this->active[$name] = $state; }
        }
        if ($given) { $this->active = [self::MARKER => '1'] + $this->active; }
    }

    /** Filters that could not be built (filters() threw): none, and the page says so. */
    public static function failed(AdminPage $page): self
    {
        $filters = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $filters->form = new Form();
        $filters->failed = true;
        return $filters;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function exists(): bool
    {
        return $this->form->fields() !== [];
    }

    /** @return array<string, mixed> the valid, non-empty values by field name */
    public function values(): array
    {
        return $this->values;
    }

    /** @return array<string, string|array<string, string>> the values chosen in the address (for links), with the marker */
    public function active(): array
    {
        return $this->active;
    }

    /** Whether a filter differs from the defaults (Reset is offered). */
    public function changed(): bool
    {
        return $this->active !== [];
    }

    public function render(): string
    {
        $renderer = new FormRenderer($this->state, $this->errors, Languages::all(), Languages::default(), prefix: 'fa-pf-', namePrefix: 'filter');
        return $renderer->render($this->form);
    }
}
