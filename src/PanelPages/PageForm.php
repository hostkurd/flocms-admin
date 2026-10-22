<?php
declare(strict_types=1);

namespace FloCMS\Admin\PanelPages;

use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Validation\FormValidator;
use FloCMS\Core\ValidationException;

/**
 * A panel page's form with Save (0.36): starting values from fill(), the
 * same validation as resources, then submit(array $data). Works without HTTP.
 *
 * @internal
 */
final class PageForm
{
    private readonly Form $form;

    public function __construct(private readonly AdminPage $page)
    {
        $this->form = $page->form(new Form());
        foreach ($this->form->fields() as $field) {
            if ($field instanceof \FloCMS\Admin\Form\Fields\FileUpload) {
                throw new \LogicException('Field "' . $field->getName() . '": upload fields are not supported on panel pages yet; use a MediaPicker or a settings page.');
            }
            if ($field instanceof \FloCMS\Admin\Form\Fields\Slug || $field instanceof \FloCMS\Admin\Form\Fields\BelongsToMany) {
                throw new \LogicException('Field "' . $field->getName() . '": ' . (new \ReflectionClass($field))->getShortName() . ' fields belong to resources.');
            }
        }
    }

    public function form(): Form
    {
        return $this->form;
    }

    /** The values fill() returns, as a record. */
    public function record(): object
    {
        $filled = $this->page->fill();
        return (object) (is_array($filled) ? $filled : []);
    }

    /** @return array<string, string|array<string, string>> */
    public function initialState(): array
    {
        $record = $this->record();
        $state = [];
        foreach ($this->form->fields() as $name => $field) {
            $state[$name] = $field->stateFromRecord($record, Languages::default());
        }
        return $state;
    }

    /**
     * A repeater button sent without JavaScript: the list changed, nothing saved.
     *
     * @param array<string, mixed> $input
     * @return array<string, string|array<string, string>>
     */
    public function operate(array $input, mixed $operation): array
    {
        $record = $this->record();
        $state = $this->stateFromInput($input, $record);
        $this->form->evaluate($state, $record)->applyRepeaterOperation($state, $operation);
        return $state;
    }

    /**
     * Validate; when valid, call the page's submit().
     *
     * @param array<string, mixed> $input submitted fields
     * @return array{state: array<string, string|array<string, string>>, errors: array<string, string>, saved: bool, message: ?string}
     */
    public function submit(array $input): array
    {
        $record = $this->record();
        $before = $this->initialState();
        $state = $this->stateFromInput($input, $record);
        $resolved = $this->form->evaluate($state, $record);
        foreach ($resolved->fields() as $field) { $state = $field->prepare($state, Languages::default()); }
        $resolved = $this->form->evaluate($state, $record);

        $skip = [];
        foreach ($resolved->writableFields() as $name => $field) {
            if ($field->skipsWrite($state[$name] ?? '', true)) { $skip[] = $name; }
        }
        $errors = (new FormValidator(Languages::all(), Languages::default(), null, true))->validate($resolved, $state, $skip);
        if ($errors !== []) {
            return ['state' => $state, 'errors' => $errors, 'saved' => false, 'message' => null];
        }

        $data = [];
        foreach ($resolved->writableFields() as $name => $field) {
            if (in_array($name, $skip, true) || $field instanceof \FloCMS\Admin\Form\Fields\Placeholder) { continue; }
            $data[$name] = self::value($field, $state[$name] ?? '');
        }
        try {
            $result = $this->page->submit($data);
        } catch (ValidationException $error) {
            $errors = [];
            foreach ($error->getErrors() as $field => $messages) { $errors[(string) $field] = implode(' ', (array) $messages); }
            return ['state' => $state, 'errors' => $errors !== [] ? $errors : ['_form' => $error->getMessage()], 'saved' => false, 'message' => null];
        }
        $this->log($before, $state, $skip);

        return ['state' => $state, 'errors' => [], 'saved' => true, 'message' => is_string($result) && trim($result) !== '' ? $result : null];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|array<string, string>>
     */
    private function stateFromInput(array $input, object $record): array
    {
        $state = [];
        foreach ($this->form->fields() as $name => $field) {
            $state[$name] = $field->isWritable() ? $field->stateFromInput($input, Languages::all()) : $field->stateFromRecord($record, Languages::default());
        }
        return $state;
    }

    /**
     * The value submit() receives: translatable fields ['en' => …, 'ar' => …],
     * repeaters, builders, checkbox lists and tags their list, other fields what
     * a resource would store.
     */
    public static function value(Field $field, string|array $state): mixed
    {
        if ($field instanceof \FloCMS\Admin\Form\Fields\ListField) {
            return $field->values(is_string($state) ? $state : '');
        }
        if ($field instanceof \FloCMS\Admin\Form\Fields\Repeater || $field instanceof \FloCMS\Admin\Form\Fields\Builder) {
            $encoded = $field->dehydrate(is_string($state) ? $state : '');
            $list = is_string($encoded) ? json_decode($encoded, true) : $encoded;
            return is_array($list) ? $list : [];
        }
        if ($field->isTranslatable()) {
            $encoded = $field->dehydrate(array_map('strval', is_array($state) ? $state : []));
            $decoded = is_string($encoded) ? json_decode($encoded, true) : null;
            $values = [];
            foreach (Languages::all() as $lang) {
                $item = is_array($decoded) ? ($decoded[$lang] ?? '') : '';
                $values[$lang] = is_scalar($item) ? (string) $item : '';
            }
            return $values;
        }
        return $field->dehydrate(is_array($state) ? '' : $state);
    }

    /** One "page saved" activity entry with the fields that changed (0.36). */
    private function log(array $before, array $after, array $skip): void
    {
        $page = $this->page;
        if (!$page::logsActivity()) { return; }
        try {
            if (!\FloCMS\Admin\Activity\Activity::enabled()) { return; }
            $form = $this->form;
            $tracked = \FloCMS\Admin\Activity\ActivityChanges::state($form, $this->record());
            $old = array_intersect_key($before, $tracked) + $tracked;
            $new = array_diff_key(array_intersect_key($after, $tracked), array_flip($skip)) + $old;
            $changes = \FloCMS\Admin\Activity\ActivityChanges::diff($form, $old, $new);
            \FloCMS\Admin\Activity\Activity::record('page_saved', CustomPages::ACTIVITY, $page::getSlug(), strip_tags($page::getLabel()), $changes);
        } catch (\Throwable $error) {
            \FloCMS\Admin\Support\Log::error('could not log the page save: ' . $error->getMessage());
        }
    }
}
