<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use InvalidArgumentException;
use LogicException;

/**
 * A list of small items inside one record (0.24), each with its own fields,
 * stored as a JSON list in one TEXT or JSON column:
 *
 *     Repeater::make('rooms')->schema([
 *         TextInput::make('name')->translatable()->required(),
 *         TextInput::make('size')->label('Size (m²)')->rules('numeric'),
 *         MediaPicker::make('photo'),
 *     ])->itemLabel('name')->addLabel('Add room')->maxItems(20)
 *
 * Stored: [{"_key":"k7f2a91c","name":{"en":"Bedroom","ar":"غرفة نوم"},"size":"18","photo":"42"}, …]
 * The "_key" matches an item across saves (the activity log reads a moved item
 * as moved). The website reads the list with repeater_items($record->rooms).
 *
 * Inside the form the state is the same list as a JSON string, so every part
 * of the panel that passes field states around treats it like any text field.
 * Inputs are named rooms[<key>][name] (rooms[<key>][name][ar] when translatable),
 * so the browser sends the items in their order on the page.
 */
class Repeater extends Field
{
    /** The most items a list may hold (->maxItems() defaults to 50). */
    public const ITEMS_LIMIT = 200;
    /**
     * The most bytes per list, measured as the list is carried inside a dialog's
     * draft (its JSON text encoded once more, so quotes and slashes count twice),
     * leaving room in the 64,000-byte draft for the form's other fields.
     */
    public const MAX_BYTES = 48000;

    /** Field types allowed inside an item. */
    private const ALLOWED = [TextInput::class, Textarea::class, Select::class, Toggle::class, Date::class, DateTime::class, ColorPicker::class, Hidden::class, MediaPicker::class, CheckboxList::class, TagsInput::class, TimePicker::class];

    /** @var array<string, Field> */
    protected array $schema = [];
    protected ?string $itemLabel = null;
    protected ?string $addLabel = null;
    protected int $minItems = 0;
    protected int $maxItems = 50;
    protected int $itemColumns = 2;
    protected bool $collapsed = false;
    protected bool $tableLayout = false;
    protected ?string $distinct = null;

    protected function setUp(): void
    {
        $this->columnSpan = 'full';
        $this->nullable = true;
    }

    /** @param list<Field> $fields the fields of one item */
    public function schema(array $fields): static
    {
        $schema = [];
        foreach ($fields as $field) {
            if (!$field instanceof Field) { throw new InvalidArgumentException('Repeater::schema() expects fields.'); }
            $name = $field->getName();
            if ($name === '_key' || isset($schema[$name])) { throw new InvalidArgumentException('Repeater "' . $this->name . '": the item fields need unique names other than "_key".'); }
            self::assertAllowed($this->name, $field);
            $schema[$name] = $field;
        }
        if ($schema === []) { throw new InvalidArgumentException('Repeater "' . $this->name . '" needs at least one field.'); }
        $this->schema = $schema;
        return $this;
    }

    private static function assertAllowed(string $repeater, Field $field): void
    {
        $allowed = false;
        foreach (self::ALLOWED as $class) { if ($field instanceof $class) { $allowed = true; } }
        $name = $field->getName();
        if (!$allowed || $field instanceof Slug) {
            throw new LogicException('Repeater "' . $repeater . '": ' . (new \ReflectionClass($field))->getShortName() . ' "' . $name . '" cannot sit inside an item. Use text, textarea, select, checkbox list, tags, toggle, date, date-time, time, colour, hidden or media picker fields.');
        }
        if ($field instanceof TextInput && $field->isPassword()) { throw new LogicException('Repeater "' . $repeater . '": passwords cannot sit inside an item.'); }
        if ($field instanceof Select && $field->hasDependentOptions()) { throw new LogicException('Repeater "' . $repeater . '": select "' . $name . '" cannot depend on another field inside an item.'); }
        if ($field->isUnique()) { throw new LogicException('Repeater "' . $repeater . '": "' . $name . '" cannot be unique(); use ->distinct(\'' . $name . '\') on the repeater.'); }
        if ($field->dependencies() !== []) { throw new LogicException('Repeater "' . $repeater . '": "' . $name . '" cannot use visibleWhen()/requiredWhen() inside an item.'); }
    }

    /** The field whose value titles an item ("Kitchen"); default "Item 1", "Item 2". */
    public function itemLabel(string $field): static { $this->itemLabel = $field; return $this; }
    /** The Add button's text; default "Add item". */
    public function addLabel(string $label): static { $this->addLabel = $label; return $this; }
    public function minItems(int $min): static
    {
        if ($min < 0 || $min > self::ITEMS_LIMIT) { throw new InvalidArgumentException('minItems must be 0–' . self::ITEMS_LIMIT . '.'); }
        $this->minItems = $min; return $this;
    }
    public function maxItems(int $max): static
    {
        if ($max < 1 || $max > self::ITEMS_LIMIT) { throw new InvalidArgumentException('maxItems must be 1–' . self::ITEMS_LIMIT . '.'); }
        $this->maxItems = $max; return $this;
    }
    /** Fields per row inside an item (1–4). */
    public function columns(int $columns): static
    {
        if ($columns < 1 || $columns > 4) { throw new InvalidArgumentException('Repeater columns must be 1–4.'); }
        $this->itemColumns = $columns; return $this;
    }
    /** Items start folded (an item with an error opens). */
    public function collapsed(bool $collapsed = true): static { $this->collapsed = $collapsed; return $this; }
    /** A compact layout: one row per item, one column per field (short items: opening hours). */
    public function table(bool $table = true): static { $this->tableLayout = $table; return $this; }
    /** Two items may not share a value of this field ("Kitchen" twice). */
    public function distinct(string $field): static { $this->distinct = $field; return $this; }

    /** @return array<string, Field> */
    public function fields(): array { return $this->schema; }
    public function getItemLabelField(): ?string { return $this->itemLabel; }
    public function getAddLabel(): string { return $this->addLabel !== null ? AdminLang::label($this->addLabel) : AdminLang::get('repeater.add'); }
    public function getMinItems(): int { return $this->minItems; }
    public function getMaxItems(): int { return $this->maxItems; }
    public function getItemColumns(): int { return $this->itemColumns; }
    public function isCollapsed(): bool { return $this->collapsed; }
    public function isTable(): bool { return $this->tableLayout; }
    public function getDistinct(): ?string { return $this->distinct; }
    /** Whether items keep their "_key" in the stored JSON (KeyValue matches pairs by their key instead). */
    public function storesKeys(): bool { return true; }
    /** Whether editors add, remove and reorder items (KeyValue with fixed keys: no). */
    public function hasFreeItems(): bool { return true; }

    public function supportsTranslation(): bool { return false; }
    public function isLong(): bool { return true; }

    /** A fresh item key. */
    public static function newKey(): string
    {
        return 'k' . bin2hex(random_bytes(4));
    }

    public static function isKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^k[a-z0-9]{1,24}$/D', $key) === 1;
    }

    /* ---------------------------------------------------------------
     * Values: the state is a JSON list of items ['_key' => …, field => state]
     * ------------------------------------------------------------- */

    /**
     * The items of a state (or a stored value): a list of ['_key' => …, field => string|array].
     *
     * @return list<array<string, mixed>>
     */
    public function items(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') { return []; }
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) { return []; }
        return $this->normalize($value);
    }

    /**
     * Items from submitted input (keyed by item key) or a decoded list, each
     * through its fields' own input handling.
     *
     * @param array<array-key, mixed> $entries
     * @return list<array<string, mixed>>
     */
    protected function normalize(array $entries): array
    {
        $items = [];
        $seen = [];
        $position = 0;
        foreach ($entries as $index => $entry) {
            if ($index === '_' || !is_array($entry)) { continue; }
            if (count($items) >= self::ITEMS_LIMIT + 20) { break; }
            $key = $entry['_key'] ?? null;
            if (!self::isKey($key)) { $key = self::isKey($index) ? $index : 'k' . $position; }
            if (isset($seen[$key])) { $key = self::newKey(); }
            $seen[$key] = true;
            $position++;
            $item = ['_key' => $key];
            foreach ($this->schema as $name => $field) {
                $item[$name] = $field->stateFromInput($entry, Languages::all());
            }
            $items[] = $item;
        }
        return $items;
    }

    /** @param list<array<string, mixed>> $items */
    public function encodeState(array $items): string
    {
        return json_encode(array_values($items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function stateFromRecord(?object $record, string $defaultLang): string|array
    {
        if ($record === null || !property_exists($record, $this->name)) {
            return $this->defaultState($defaultLang);
        }
        return $this->encodeState($this->items($record->{$this->name}));
    }

    protected function defaultState(string $defaultLang): string|array
    {
        $default = $this->default instanceof \Closure ? ($this->default)() : $this->default;
        return $this->encodeState(is_array($default) || is_string($default) ? $this->items($default) : []);
    }

    /** Input: rooms[<key>][field]… from a form, or the JSON state from a dialog's draft. */
    public function stateFromInput(array $input, array $languages): string|array
    {
        $value = $input[$this->name] ?? [];
        return $this->encodeState($this->items(is_array($value) || is_string($value) ? $value : []));
    }

    /** The field is in the input (a hidden rooms[_] marks it, so an emptied list is sent too). */
    public function submitted(array $input): bool
    {
        return array_key_exists($this->name, $input);
    }

    /** Whether every field of an item is empty (an unticked toggle counts as empty). */
    public function isEmptyItem(array $item): bool
    {
        foreach ($this->schema as $name => $field) {
            $value = $item[$name] ?? '';
            if ($field instanceof Hidden) { continue; }
            if ($field instanceof Toggle) { if ($value === '1') { return false; } continue; }
            if (is_array($value)) { foreach ($value as $part) { if (is_string($part) && trim($part) !== '') { return false; } } continue; }
            if (trim((string) $value) !== '') { return false; }
        }
        return true;
    }

    /**
     * The value written to the column: the items in order without empty ones,
     * each field as stored (translations as {"en": …}, media as ids), or null.
     */
    public function dehydrate(string|array $state): mixed
    {
        $stored = [];
        foreach ($this->items(is_string($state) ? $state : '') as $item) {
            if ($this->isEmptyItem($item)) { continue; }
            $stored[] = $this->storedItem($item);
        }
        if ($stored === []) { return $this->isNullable() ? null : ''; }
        return json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    protected function storedItem(array $item): array
    {
        $stored = $this->storesKeys() ? ['_key' => $item['_key']] : [];
        foreach ($this->schema as $name => $field) {
            $value = $item[$name] ?? '';
            if ($field->isTranslatable()) {
                $clean = array_filter(is_array($value) ? $value : [], static fn ($part): bool => is_string($part) && $part !== '');
                if ($clean !== []) { $stored[$name] = $clean; }
                continue;
            }
            if ($field instanceof ListField) {
                // A list stays a list (0.37), so repeater_items() gives an array
                $list = $field->values(is_string($value) ? $value : '');
                if ($list !== []) { $stored[$name] = $list; }
                continue;
            }
            if ($field instanceof MediaPicker) {
                $ids = array_map('strval', MediaPicker::ids(is_string($value) ? $value : ''));
                if ($ids !== []) { $stored[$name] = $field->isMultiple() ? $ids : $ids[0]; }
                continue;
            }
            $value = is_string($value) ? $value : '';
            if ($value !== '' && !($field instanceof Toggle && $value === '0')) { $stored[$name] = $value; }
        }
        return $stored;
    }

    /** Too large for one field (MAX_BYTES), checked before the items. */
    public function typeError(string $value): ?array
    {
        $size = strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return $size > self::MAX_BYTES ? ['validation.repeater_size', ['max' => (string) intdiv(self::MAX_BYTES, 1000)]] : null;
    }

    /**
     * Errors of the list: "rooms" (too few or too many, the size) and of the fields
     * inside items, keyed "rooms.2.name" or "rooms.2.name.ar" by the item's place.
     * Empty items are left out (they are dropped on save).
     *
     * @param \Closure(Field, string|array<string, string>, string): array<string, string> $check one field's errors under a key
     * @return array<string, string>
     */
    public function validateItems(string $state, \Closure $check): array
    {
        if (($size = $this->typeError($state)) !== null) {
            return [$this->name => AdminLang::get($size[0], ['attribute' => $this->getLabel()] + $size[1])];
        }
        $items = $this->items($state);
        $errors = [];
        $filled = 0;
        $values = [];
        foreach ($items as $index => $item) {
            if ($this->isEmptyItem($item)) { continue; }
            $filled++;
            foreach ($this->schema as $name => $field) {
                $errors += $check($field, $item[$name] ?? ($field->isTranslatable() ? [] : ''), $this->name . '.' . $index . '.' . $name);
            }
            if ($this->distinct !== null && isset($this->schema[$this->distinct])) {
                $value = $item[$this->distinct] ?? '';
                $value = is_array($value) ? (string) ($value[Languages::default()] ?? '') : (string) $value;
                $normal = mb_strtolower(trim($value));
                if ($normal !== '' && isset($values[$normal])) {
                    $errors[$this->name . '.' . $index . '.' . $this->distinct] ??= AdminLang::get('validation.repeater_distinct', ['attribute' => $this->schema[$this->distinct]->getLabel()]);
                }
                $values[$normal] = true;
            }
        }
        $required = $this->isRequired() ? max(1, $this->minItems) : $this->minItems;
        if ($filled < $required) {
            $errors[$this->name] = AdminLang::get($required === 1 ? 'validation.repeater_min_one' : 'validation.repeater_min', ['attribute' => $this->getLabel(), 'min' => (string) $required]);
        } elseif ($filled > $this->maxItems) {
            $errors[$this->name] = AdminLang::get('validation.repeater_max', ['attribute' => $this->getLabel(), 'max' => (string) $this->maxItems]);
        }
        return $errors;
    }

    /**
     * The no-JavaScript buttons (Add, Remove, Up, Down): "rooms:add", "rooms:remove:k1",
     * "rooms:up:k1", "rooms:down:k1". Returns the changed state, or null when the
     * operation is not this field's.
     */
    public function applyOperation(string $state, string $operation): ?string
    {
        $parts = explode(':', $operation);
        if (($parts[0] ?? '') !== $this->name || !in_array($parts[1] ?? '', ['add', 'remove', 'up', 'down'], true)) { return null; }
        $items = $this->items($state);
        if ($parts[1] === 'add') {
            if (!$this->hasFreeItems() || count($items) >= $this->maxItems) { return $this->encodeState($items); }
            $item = ['_key' => self::newKey()];
            foreach ($this->schema as $name => $field) { $item[$name] = $field->stateFromRecord(null, Languages::default()); }
            $items[] = $item;
            return $this->encodeState($items);
        }
        $key = $parts[2] ?? '';
        $index = null;
        foreach ($items as $i => $item) { if ($item['_key'] === $key) { $index = $i; } }
        if ($index === null || !$this->hasFreeItems()) { return $this->encodeState($items); }
        if ($parts[1] === 'remove') { array_splice($items, $index, 1); }
        elseif ($parts[1] === 'up' && $index > 0) { [$items[$index - 1], $items[$index]] = [$items[$index], $items[$index - 1]]; }
        elseif ($parts[1] === 'down' && $index < count($items) - 1) { [$items[$index + 1], $items[$index]] = [$items[$index], $items[$index + 1]]; }
        return $this->encodeState($items);
    }

    /** An item's title: its itemLabel field in the default language, else "Item 3". */
    public function itemTitle(array $item, int $index): string
    {
        if ($this->itemLabel !== null && isset($this->schema[$this->itemLabel])) {
            $value = $item[$this->itemLabel] ?? '';
            $text = is_array($value) ? Translations::get($value, Languages::default()) : (string) $value;
            $field = $this->schema[$this->itemLabel];
            if ($field instanceof Select && $text !== '') { $text = (string) ($field->getOptions()[$text] ?? $text); }
            if (trim($text) !== '') { return mb_strimwidth(trim($text), 0, 80, '…'); }
        }
        return AdminLang::get('repeater.item', ['number' => (string) ($index + 1)]);
    }

    /** The rendering of an item's own control: never called (the renderer draws the list). */
    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        return '';
    }
}
