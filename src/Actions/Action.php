<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;

use Closure;
use FloCMS\Admin\{Resource, Form\Form, Support\AdminLang, Support\Str};
use FloCMS\Core\{Auth, HttpException};
use InvalidArgumentException;

/** A PHP-defined action. Callback names and classes never come from the client. */
class Action
{
    protected string $kind = 'custom';
    private ?string $label = null;
    private ?string $icon = null;
    private string $color = 'default';
    private ?string $permission = null;
    private ?Closure $visibility = null;
    private ?Closure $authorization = null;
    private ?Closure $form = null;
    private ?Closure $fill = null;
    private ?Closure $normalizer = null;
    private ?Closure $validator = null;
    private ?Closure $handler = null;
    private ?Closure $uniqueness = null;
    private bool $confirmation = true;
    private ?string $description = null;
    private ?string $mode = null;
    private ?string $minHeight = null;

    protected function __construct(private readonly string $name)
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Action names must be stable lowercase identifiers.');
        }
    }

    public static function make(string $name): static { return new static($name); }
    public function label(string $label): static { $this->label = $label; return $this; }
    public function icon(?string $icon): static { $this->icon = $icon; return $this; }
    public function color(string $color): static
    {
        if (!in_array($color, ['default', 'primary', 'danger', 'success', 'warning'], true)) { throw new InvalidArgumentException('Invalid action color.'); }
        $this->color = $color; return $this;
    }
    public function permission(string $permission): static
    {
        if (trim($permission) === '') { throw new InvalidArgumentException('Action permission cannot be empty.'); }
        $this->permission = $permission; return $this;
    }
    /** Visibility is also enforced when opening/executing, including direct requests. */
    public function visible(Closure $callback): static { $this->visibility = $callback; return $this; }
    public function authorize(Closure $callback): static { $this->authorization = $callback; return $this; }
    /** Closure(Form): Form. Custom action forms are independent of the resource form. */
    public function form(Closure $callback): static
    {
        $this->customOnly(); $this->form = $callback; return $this;
    }
    public function fillForm(Closure $callback): static { $this->customOnly(); $this->fill = $callback; return $this; }
    public function normalizeState(Closure $callback): static { $this->customOnly(); $this->normalizer = $callback; return $this; }
    public function validate(Closure $callback): static { $this->customOnly(); $this->validator = $callback; return $this; }
    public function uniqueUsing(Closure $callback): static { $this->customOnly(); $this->uniqueness = $callback; return $this; }
    /** Closure(ActionContext, array): void; only executes after validation inside the transaction. */
    public function action(Closure $callback): static { $this->customOnly(); $this->handler = $callback; return $this; }
    public function requiresConfirmation(bool $value = true): static { $this->confirmation = $value; return $this; }
    public function modalDescription(string $description): static { $this->description = $description; return $this; }
    /**
     * Reserve room in the dialog's body (0.19.1): pixels or a CSS length such as
     * '20rem'; null fits the content (the default, or the panel's 'dialog_min_height').
     */
    public function modalMinHeight(int|string|null $height): static
    {
        $length = $height === null ? null : \FloCMS\Admin\AdminConfig::cssLength($height);
        if ($height !== null && $length === null) { throw new \InvalidArgumentException('modalMinHeight() takes pixels (an int) or a CSS length such as "20rem".'); }
        $this->minHeight = $length;
        return $this;
    }
    public function getModalMinHeight(): ?string { return $this->minHeight; }
    /** Open in a dialog over the list. */
    public function modal(): static { $this->mode = 'modal'; return $this; }
    /** Open in a panel that slides in from the side of the window. */
    public function slideOver(): static { $this->mode = 'slide-over'; return $this; }
    /** Open as a full page (Create and Edit only; other actions are short confirmations). */
    public function page(): static
    {
        if (!in_array($this->kind, ['create', 'edit'], true)) { throw new \LogicException('Only Create and Edit actions open as a page.'); }
        $this->mode = 'page'; return $this;
    }
    /** 'page', 'modal' or 'slide-over'. Create and Edit follow the resource's $formMode unless set here. */
    public function getMode(Resource $resource): string
    {
        // Inside a relation manager Create and Edit open over the parent's page (0.22).
        if ($resource->parentScope() !== null && in_array($this->kind, ['create', 'edit'], true)) { return $this->mode === 'slide-over' || ($this->mode === null && $resource::getFormMode() === 'slide-over') ? 'slide-over' : 'modal'; }
        if ($this->mode !== null) { return $this->mode; }
        return in_array($this->kind, ['create', 'edit'], true) ? $resource::getFormMode() : 'modal';
    }

    public function getName(): string { return $this->name; }
    /** The permission set with permission(), or null when the resource's ability decides (0.21). */
    public function getPermission(): ?string { return $this->permission; }
    public function getKind(): string { return $this->kind; }
    public function getIcon(): ?string { return $this->icon; }
    public function getColor(): string { return $this->color; }
    public function getLabel(Resource $resource): string
    {
        if ($this->label !== null) { return AdminLang::label($this->label); }
        return match ($this->kind) {
            'create' => AdminLang::get('actions.create_label', ['label' => $resource::getLabel()]),
            'edit' => AdminLang::get('actions.edit'), 'delete' => AdminLang::get('actions.delete'),
            default => Str::headline($this->name),
        };
    }
    public function getTitle(Resource $resource): string
    {
        return match ($this->kind) {
            'create' => AdminLang::get('form.create_title', ['label' => $resource::getLabel()]),
            'edit' => AdminLang::get('form.edit_title', ['label' => $resource::getLabel()]),
            'delete' => AdminLang::get('delete.title'), default => $this->getLabel($resource),
        };
    }
    public function getDescription(?Resource $resource = null): string
    {
        if ($this->description !== null) { return AdminLang::label($this->description); }
        if ($this->kind === 'delete') { return $resource !== null ? \FloCMS\Admin\Http\PageView::deleteText($resource::class) : AdminLang::get('delete.text'); }
        return AdminLang::get('action.confirm_text');
    }
    public function getSubmitLabel(Resource $resource): string
    {
        return match ($this->kind) {
            'create' => AdminLang::get('actions.create'), 'edit' => AdminLang::get('actions.save_changes'),
            default => $this->getLabel($resource),
        };
    }
    public function needsDialog(Resource $resource): bool
    {
        return $this->kind !== 'custom' || $this->confirmation || $this->getForm($resource)->fields() !== [];
    }
    public function getForm(Resource $resource): Form
    {
        if (in_array($this->kind, ['create', 'edit'], true)) {
            $form = $resource->form(new Form());
            return ($scope = $resource->parentScope()) !== null ? $scope->adjustForm($form) : $form;
        }
        $form = $this->form !== null ? ($this->form)(new Form()) : new Form();
        foreach ($form->fields() as $field) { if ($field instanceof \FloCMS\Admin\Form\Fields\FileUpload) { throw new \LogicException('Upload fields belong to resource CRUD forms; custom and bulk actions do not own a file column lifecycle.'); } }
        return $form;
    }
    public function allowed(Resource $resource, ?object $record): bool
    {
        $ability = match ($this->kind) { 'edit' => 'update', 'custom' => $this->name, default => $this->kind };
        if (!Auth::can($this->permission ?? $resource::permission($ability))) { return false; }
        $policy = match ($this->kind) {
            'create' => $resource->canCreate(), 'edit' => $record !== null && $resource->canEdit($record),
            'delete' => $record !== null && $resource->canDelete($record), default => true,
        };
        return $policy && ($this->visibility === null || (bool) ($this->visibility)($record))
            && ($this->authorization === null || (bool) ($this->authorization)($record));
    }
    public function authorizePermission(Resource $resource): void
    {
        $ability = match ($this->kind) { 'edit' => 'update', 'custom' => $this->name, default => $this->kind };
        Auth::authorize($this->permission ?? $resource::permission($ability));
    }
    public function authorizeRequest(Resource $resource, ?object $record): void
    {
        if (!$this->allowed($resource, $record)) { throw new HttpException(403); }
    }
    public function initialState(Form $form, ?object $record): array
    {
        $filled = $this->fill !== null ? ($this->fill)($record) : null;
        $state = [];
        foreach ($form->fields() as $name => $field) {
            $state[$name] = $filled !== null && $field->isWritable()
                ? $field->stateFromInput($filled, \FloCMS\Admin\Translation\Languages::all())
                : $field->stateFromRecord($this->kind === 'custom' && $field->isWritable() ? null : $record, \FloCMS\Admin\Translation\Languages::default());
        }
        return $state;
    }
    public function normalize(array $state, ?object $record): array { return $this->normalizer !== null ? ($this->normalizer)($state, $record) : $state; }
    public function errors(array $state, ?object $record): array { return $this->validator !== null ? ($this->validator)($state, $record) : []; }
    public function uniqueness(): ?Closure { return $this->uniqueness; }
    public function invoke(ActionContext $context, array $data): void
    {
        if ($this->handler === null) { throw new \LogicException('Custom action ' . $this->name . ' needs an action() callback.'); }
        ($this->handler)($context, $data);
    }
    private function customOnly(): void
    {
        if ($this->kind !== 'custom') { throw new \LogicException('Built-in CRUD actions use the resource form and save contracts.'); }
    }
}
