<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;

use Closure;
use FloCMS\Admin\{Resource, Data\RecordRepository, Form\Form, Form\Fields\FileUpload, Form\Fields\Slug, Form\Fields\TextInput, Support\AdminLang, Support\Str, Translation\Languages, Uploads\Uploads};
use InvalidArgumentException;
use LogicException;

/**
 * Duplicate (0.40): the resource's New form, filled from a record. Nothing is
 * saved until the editor presses Create, and the save is an ordinary create;
 * the record it copies only gives the form its first values.
 *
 *     ->actions([EditAction::make(), DuplicateAction::make()->except(['code']), DeleteAction::make()])
 */
final class DuplicateAction extends Action
{
    protected string $kind = 'duplicate';
    /** @var list<string> */
    private array $except = [];
    /** @var list<string>|null */
    private ?array $only = null;
    private ?Closure $filler = null;

    public static function make(string $name = 'duplicate'): static { return parent::make($name)->icon('copy'); }

    /** These fields start at their default instead of the record's value. */
    public function except(array $fields): static
    {
        if ($this->only !== null) { throw new LogicException('Use except() or only() on a DuplicateAction, not both.'); }
        $this->except = self::names($fields); return $this;
    }
    /** Copy only these fields; every other field starts at its default. */
    public function only(array $fields): static
    {
        if ($this->except !== []) { throw new LogicException('Use except() or only() on a DuplicateAction, not both.'); }
        $this->only = self::names($fields); return $this;
    }
    /** Change the copied values last: fn (array $state, object $source): array returns the state. */
    public function fill(Closure $callback): static { $this->filler = $callback; return $this; }

    /**
     * The New form's first values from $source; the upload fields whose file
     * could not be copied, with why ("file": missing or larger than the field
     * allows; "limit": the session holds too many pending files); and the
     * pending uploads staged for the copy (released when a dialog closes).
     *
     * @return array{state: array<string, mixed>, skipped: array<string, string>, tokens: list<string>}
     */
    public function prefill(Resource $resource, RecordRepository $repository, Form $form, object $source): array
    {
        $default = Languages::default();
        $state = [];
        foreach ($form->fields() as $name => $field) {
            $copy = !$field->isDisabled() && !$field->isOmitted() && $name !== $resource::getPrimaryKey()
                && !($field instanceof TextInput && $field->getType() === 'password')
                && !($field->isUnique() && !$field instanceof Slug)
                && !in_array($name, $this->except, true) && ($this->only === null || in_array($name, $this->only, true));
            $state[$name] = $field->stateFromRecord($copy ? $source : null, $default);
            if ($copy && $field instanceof Slug) { $state[$name] = $this->freeSlug($field, $state[$name], $repository); }
        }
        // Fields hidden on a new record start at their default, as on Create.
        $resolved = $form->evaluate($state, null);
        $skipped = []; $tokens = [];
        foreach ($resolved->writableFields() as $name => $field) {
            if (!$field instanceof FileUpload || !is_string($state[$name]) || $state[$name] === '' || str_starts_with($state[$name], '@')) { continue; }
            // Its own pending upload: the copy never shares the record's stored file.
            $token = Uploads::stageCopy($field, $resource, $state[$name], $reason);
            if ($token === null) { $skipped[$name] = $reason ?? 'file'; } else { $tokens[] = $token; }
            $state[$name] = $token ?? $field->stateFromRecord(null, $default);
        }
        if ($this->filler !== null) {
            $filled = ($this->filler)($state, $source);
            if (!is_array($filled)) { throw new LogicException('DuplicateAction::fill() must return the state array.'); }
            $state = array_replace($state, array_intersect_key($filled, $state));
            $form->evaluate($state, null);
        }
        return ['state' => $state, 'skipped' => $skipped, 'tokens' => $tokens];
    }

    /**
     * The skipped upload fields that are still empty in $state, from untrusted
     * input (a page's hidden field, a dialog's state): only the form's upload
     * fields and the two known reasons.
     *
     * @return array<string, string>
     */
    public static function stillSkipped(Form $form, mixed $skipped, array $state): array
    {
        $kept = [];
        foreach (is_array($skipped) ? $skipped : [] as $name => $reason) {
            if (is_string($name) && $form->field($name) instanceof FileUpload && in_array($reason, ['file', 'limit'], true) && ($state[$name] ?? '') === '') { $kept[$name] = $reason; }
        }
        return $kept;
    }

    /**
     * The note above a duplicate form's fields: what it copies, that nothing is
     * saved yet, and which files could not be copied and why.
     *
     * @param array<string, string> $skipped field name => "file" or "limit"
     */
    public static function note(Resource $resource, Form $form, object $source, array $skipped = []): string
    {
        $text = AdminLang::get('duplicate.note', ['title' => ActionExecutor::label($resource, $source)]);
        if ($resource->relations() !== []) { $text .= ' ' . AdminLang::get('duplicate.note_related'); }
        return '<p class="fa-alert fa-alert-info fa-duplicate-note" role="status">' . Str::e($text) . '</p>' . self::skippedNote($form, $skipped);
    }

    /** @param array<string, string> $skipped */
    private static function skippedNote(Form $form, array $skipped): string
    {
        $html = '';
        foreach (['file' => 'duplicate.file_not_copied', 'limit' => 'duplicate.file_limit'] as $reason => $key) {
            $labels = [];
            foreach ($skipped as $name => $why) { if ($why === $reason && ($field = $form->field($name)) !== null) { $labels[] = $field->getLabel(); } }
            if ($labels !== []) { $html .= '<p class="fa-alert fa-alert-warning fa-duplicate-note" role="status">' . Str::e(AdminLang::get($key, ['fields' => implode(AdminLang::get('form.list_separator'), $labels)])) . '</p>'; }
        }
        return $html;
    }

    /**
     * Duplicate needs the resource's own Create action (0.40): it creates a
     * record, so it is refused wherever Create is (no CreateAction, or one whose
     * permission, visible(), authorize() or disabled() refuses).
     */
    public function permits(Resource $resource, ?object $record): bool
    {
        $create = self::createAction($resource);
        return $create !== null && $create->allowed($resource, null) && parent::permits($resource, $record);
    }

    /** Its own permission(), else the Create action's permission. */
    public function permissionName(Resource $resource): string
    {
        return $this->getPermission() ?? self::createAction($resource)?->permissionName($resource) ?? $resource::permission('create');
    }

    /** @var \WeakMap<Resource, Action|false>|null */
    private static ?\WeakMap $creates = null;
    private static function createAction(Resource $resource): ?Action
    {
        self::$creates ??= new \WeakMap();
        if (!isset(self::$creates[$resource])) {
            try { $action = (new ActionRegistry($resource))->find('page', 'create'); }
            catch (\FloCMS\Core\HttpException) { $action = null; }
            self::$creates[$resource] = $action !== null && $action->getKind() === 'create' ? $action : false;
        }
        return self::$creates[$resource] ?: null;
    }

    /**
     * "villa-copy", or "villa-copy-2", "-copy-3"… when that one is taken. A
     * Slug can't be made translatable (Field::translatable() refuses), but a
     * subclass that allows it gets the same per language.
     */
    private function freeSlug(Slug $field, string|array $slug, RecordRepository $repository): string|array
    {
        if (is_array($slug)) {
            foreach ($slug as $language => $value) { $slug[$language] = $this->free($field, (string) $value, $repository, (string) $language); }
            return $slug;
        }
        return $this->free($field, $slug, $repository, null);
    }
    private function free(Slug $field, string $slug, RecordRepository $repository, ?string $language): string
    {
        if ($slug === '') { return ''; }
        // A copy of a copy is "villa-copy-2", not "villa-copy-copy".
        $base = (string) preg_replace('/-copy(?:-\d+)?$/D', '', $slug);
        $base = $base !== '' ? $base : $slug;
        for ($number = 1; $number <= 200; ++$number) {
            $candidate = $base . '-copy' . ($number > 1 ? '-' . $number : '');
            if (!$repository->exists($field->getName(), $candidate, null, $language)) { return $candidate; }
        }
        return $base . '-copy-' . bin2hex(random_bytes(3));
    }

    /** @return list<string> */
    private static function names(array $fields): array
    {
        foreach ($fields as $field) { if (!is_string($field) || $field === '') { throw new InvalidArgumentException('List field names as strings.'); } }
        return array_values($fields);
    }
}
