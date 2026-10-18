<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;

/**
 * Labels and values (0.24), e.g. a property's specs ("Parking → 2 spaces"),
 * stored in order as a JSON list in one TEXT or JSON column:
 *
 *     KeyValue::make('specs')->keyLabel('Feature')->valueLabel('Details')
 *         ->translatableValues()                     // values per language, keys shared
 *         ->keys(['Parking', 'Floor', 'Heating'])    // optional: fixed keys, no free ones
 *
 * Stored: [{"key":"Parking","value":"2 spaces"}, …] (with translatable values
 * {"key":"Parking","value":{"en":"2 spaces","ar":"مكانان"}}). Keys are distinct.
 * The website reads it with key_values($record->specs).
 */
class KeyValue extends Repeater
{
    /** @var list<string>|null */
    private ?array $fixedKeys = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tableLayout = true;
        $this->itemLabel = 'key';
        $this->distinct = 'key';
        $this->schema = [
            'key' => TextInput::make('key')->label(AdminLang::get('repeater.key'))->required()->rules('max:190'),
            'value' => TextInput::make('value')->label(AdminLang::get('repeater.value'))->rules('max:1000'),
        ];
    }

    public function keyLabel(string $label): static { $this->schema['key']->label($label); return $this; }
    public function valueLabel(string $label): static { $this->schema['value']->label($label); return $this; }

    /** One value per language; the keys stay the same in every language. */
    public function translatableValues(bool $translatable = true): static
    {
        $this->schema['value']->translatable($translatable);
        return $this;
    }

    /** Fixed keys: one row each, in this order; editors fill the values only. */
    public function keys(array $keys): static
    {
        $clean = [];
        foreach ($keys as $key) {
            if (!is_string($key) || trim($key) === '' || mb_strlen($key) > 190) { throw new \InvalidArgumentException('KeyValue keys must be non-empty texts of at most 190 characters.'); }
            $clean[] = trim($key);
        }
        if (count(array_unique(array_map('mb_strtolower', $clean))) !== count($clean)) { throw new \InvalidArgumentException('KeyValue keys must be distinct.'); }
        $this->fixedKeys = $clean;
        return $this;
    }

    /** @return list<string>|null */
    public function getFixedKeys(): ?array { return $this->fixedKeys; }

    public function schema(array $fields): static
    {
        throw new \LogicException('KeyValue has its own key and value fields; use Repeater for other fields.');
    }

    public function table(bool $table = true): static { return $this; }
    public function storesKeys(): bool { return false; }
    public function hasFreeItems(): bool { return $this->fixedKeys === null; }

    /** With fixed keys: one item per key, in order, holding the stored value of that key. */
    public function items(mixed $value): array
    {
        $items = parent::items($value);
        if ($this->fixedKeys === null) { return $items; }
        // Posted rows carry their place ("k0", "k1"); stored pairs are matched by their key.
        $byKey = [];
        $byPlace = [];
        foreach ($items as $item) {
            $byKey[mb_strtolower(trim(is_string($item['key'] ?? null) ? $item['key'] : ''))] ??= $item;
            if (preg_match('/^k(\d+)$/D', (string) $item['_key'], $place) === 1) { $byPlace[(int) $place[1]] ??= $item; }
        }
        $posted = is_array($value) && !array_is_list($value);
        $fixed = [];
        foreach ($this->fixedKeys as $index => $key) {
            $found = $posted ? ($byPlace[$index] ?? null) : ($byKey[mb_strtolower($key)] ?? null);
            $fixed[] = ['_key' => 'k' . $index, 'key' => $key, 'value' => $found['value'] ?? ($this->schema['value']->isTranslatable() ? [] : '')];
        }
        return $fixed;
    }

    /** An item without a value says nothing ("Parking" with no details): dropped on save. */
    public function isEmptyItem(array $item): bool
    {
        if ($this->fixedKeys !== null) {
            $value = $item['value'] ?? '';
            return is_array($value) ? trim((string) ($value[Languages::default()] ?? '')) === '' && array_filter($value, static fn ($v): bool => is_string($v) && trim($v) !== '') === [] : trim((string) $value) === '';
        }
        return parent::isEmptyItem($item);
    }
}
