<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Blocks\BlockRegistry;
use FloCMS\Admin\Blocks\BlockSettings;
use FloCMS\Admin\Blocks\Core\Html;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Form\Layout\Tabs;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Translation\Languages;

/**
 * Blocks of different kinds in one record (0.29), stored as JSON in one TEXT
 * or JSON column:
 *
 *     Builder::make('content')->blocks(['slider', 'rich_text', 'section', 'columns', LatestPosts::class])
 *         ->maxBlocks(40)->translatable()
 *
 * Stored: [{"id":"b7k2a91c","type":"slider","data":{…},"settings":{…},"children":[…]}, …],
 * or {"en":[…],"ar":[…]} with ->translatable() (one list per language). Blocks
 * inside a Columns block carry "column" (0, 1, …). The website prints them
 * with blocks($record->content).
 *
 * In the form every block's fields are named content[<lang>][<id>][f][<field>]
 * ("_" for the language of a list that is not translated), with
 * content[<lang>][<id>][type|parent|column|order] for its place, so the
 * browser posts the whole tree and the order on the page. The state is the
 * tree as a JSON string ({"_state":1,"lists":{…}}), so a dialog's draft and the
 * activity log treat it like any other field.
 */
class Builder extends Field
{
    public const BLOCKS_LIMIT = 200;
    /** The most bytes of one list's state. */
    public const MAX_BYTES = 600000;
    /** The language key of a list that is not translated. */
    public const SINGLE = '_';

    /** @var list<string> */
    protected array $names = [];
    /** @var list<string> */
    protected array $categories = [];
    protected int $maxBlocks = 60;
    protected bool $perLanguage = false;
    protected ?string $addLabel = null;
    /** @var array<string, Form> */
    private array $forms = [];
    /** @var array<string, list<array<string, mixed>>> the lists last validated, for the error summary */
    private array $validated = [];

    protected function setUp(): void
    {
        $this->columnSpan = 'full';
        $this->nullable = true;
    }

    /**
     * The kinds of blocks offered, by type ("slider") or class; default all.
     *
     * @param list<string> $blocks
     */
    public function blocks(array $blocks): static
    {
        BlockRegistry::only($blocks);
        $this->names = array_values($blocks);
        return $this;
    }

    /**
     * Only blocks of these categories (media, text, layout, marketing, site, other).
     *
     * @param list<string> $categories
     */
    public function only(array $categories): static
    {
        foreach ($categories as $category) {
            if (!in_array($category, Block::CATEGORIES, true)) { throw new \InvalidArgumentException('Unknown block category "' . $category . '".'); }
        }
        $this->categories = array_values($categories);
        return $this;
    }

    public function maxBlocks(int $max): static
    {
        if ($max < 1 || $max > self::BLOCKS_LIMIT) { throw new \InvalidArgumentException('maxBlocks must be 1–' . self::BLOCKS_LIMIT . '.'); }
        $this->maxBlocks = $max;
        return $this;
    }

    /** One list of blocks per language (each language may have different blocks). */
    public function translatable(bool $translatable = true): static
    {
        $this->perLanguage = $translatable;
        return $this;
    }

    public function addLabel(string $label): static { $this->addLabel = $label; return $this; }

    public function isPerLanguage(): bool { return $this->perLanguage; }
    public function getMaxBlocks(): int { return $this->maxBlocks; }
    public function getAddLabel(): string { return $this->addLabel !== null ? AdminLang::label($this->addLabel) : AdminLang::get('blocks.add'); }
    public function supportsTranslation(): bool { return false; }
    public function isLong(): bool { return true; }

    /** @return list<string> the list keys: the languages, or "_" */
    public function listKeys(): array
    {
        return $this->perLanguage ? Languages::all() : [self::SINGLE];
    }

    /**
     * The kinds of blocks this field offers.
     *
     * @return array<string, Block>
     */
    public function available(): array
    {
        $blocks = BlockRegistry::only($this->names);
        if ($this->categories !== []) {
            $blocks = array_filter($blocks, fn (Block $block): bool => in_array($block::category(), $this->categories, true));
        }
        return $blocks;
    }

    /** Whether people may add blocks of this kind (HTML needs its permission). */
    public function canAdd(Block $block): bool
    {
        return !$block instanceof Html || Html::allowed();
    }

    public function block(string $type): ?Block
    {
        return $this->available()[$type] ?? BlockRegistry::find($type);
    }

    public static function newId(): string { return 'b' . bin2hex(random_bytes(4)); }
    public static function isId(mixed $id): bool { return is_string($id) && preg_match('/^b[a-z0-9]{1,24}$/D', $id) === 1; }

    /**
     * A block's fields and the shared settings, as one form whose Tabs are
     * the inspector's tabs (the block's own tabs, then Settings).
     */
    public function blockForm(Block $block, bool $readOnly = false): Form
    {
        if ($readOnly) {
            // A new form, so the shared one keeps its fields writable.
            $form = $this->makeBlockForm($block);
            foreach ($form->fields() as $item) { $item->disabled(); }
            return $form;
        }
        return $this->forms[$block::class] ??= $this->makeBlockForm($block);
    }

    private function makeBlockForm(Block $block): Form
    {
        $own = $block->fields();
        $tabs = [];
        $loose = [];
        foreach ($own as $item) {
            if ($item instanceof Tab) { $tabs[] = $item; } else { $loose[] = $item; }
        }
        if ($loose !== []) { array_unshift($tabs, Tab::make('content')->label(AdminLang::get('blocks.tab_content'))->columns(2)->schema($loose)); }
        $tabs[] = Tab::make('settings')->label(AdminLang::get('blocks.tab_settings'))->columns(2)->schema(BlockSettings::fields($block));
        $form = (new Form())->columns(2)->schema([Tabs::make('block')->schema($tabs)]);
        foreach (array_keys($form->fields()) as $name) {
            if ($name === 'f' || str_starts_with($name, '_') && $name !== '_signed') { throw new \LogicException('Block "' . $block::type() . '": field names may not start with "_".'); }
        }
        foreach ($form->fields() as $field) {
            if ($field->isTranslatable()) { throw new \LogicException('Block "' . $block::type() . '": "' . $field->getName() . '" cannot be translatable; use ->translatable() on the Builder for one list per language.'); }
            if ($field instanceof self || $field instanceof FileUpload || $field instanceof BelongsToMany) {
                throw new \LogicException('Block "' . $block::type() . '": ' . (new \ReflectionClass($field))->getShortName() . ' fields cannot sit inside a block.');
            }
        }
        return $form;
    }

    /** @var array<string, list<string>> */
    private array $settingNames = [];

    /** @return list<string> the names of a block's shared settings (a Section's own "width" is not one) */
    public function settingNames(Block $block): array
    {
        return $this->settingNames[$block::class] ??= array_map(static fn ($field): string => $field->getName(), BlockSettings::fields($block));
    }

    /* ---------------------------------------------------------------
     * Values
     * ------------------------------------------------------------- */

    /**
     * The lists of a state or a stored value: list key => blocks, each
     * ['id', 'type', 'data' => [field => state], 'settings' => [...], 'column' => int, 'children' => [...]].
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function lists(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            $value = $value === '' ? [] : json_decode($value, true);
        }
        $value = is_array($value) ? $value : [];
        $keys = $this->listKeys();
        $lists = array_fill_keys($keys, []);
        if (($value['_state'] ?? null) === 1 && is_array($value['lists'] ?? null)) {
            foreach ($keys as $key) { $lists[$key] = $this->nodes(is_array($value['lists'][$key] ?? null) ? $value['lists'][$key] : [], '', true); }
            return $lists;
        }
        // Stored: a list, or one list per language.
        if (array_is_list($value)) {
            $lists[$keys[0]] = $this->nodes($value, '', false);
            return $lists;
        }
        foreach ($keys as $key) {
            if (is_array($value[$key] ?? null)) { $lists[$key] = $this->nodes($value[$key], '', false); }
        }
        // Lists stored per language, read after ->translatable() was taken off: the default language's.
        if (!$this->perLanguage && !isset($value[self::SINGLE])) { $lists[self::SINGLE] = $this->nodes(self::defaultList($value), '', false); }
        // A list stored before ->translatable(): the default language's.
        if ($this->perLanguage && isset($value[self::SINGLE]) && is_array($value[self::SINGLE]) && $lists[Languages::default()] === []) {
            $lists[Languages::default()] = $this->nodes($value[self::SINGLE], '', false);
        }
        return $lists;
    }

    /**
     * Blocks from a decoded list (state or stored), checked against the kinds
     * known and where each may sit; unknown kinds are kept as they are.
     *
     * @param array<array-key, mixed> $entries
     * @return list<array<string, mixed>>
     */
    private function nodes(array $entries, string $parentType, bool $isState, int &$count = 0, array &$seen = []): array
    {
        $nodes = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || $count >= self::BLOCKS_LIMIT) { continue; }
            $type = (string) ($entry['type'] ?? '');
            $block = $this->block($type);
            $id = $entry['id'] ?? null;
            if (!self::isId($id) || isset($seen[$id])) { $id = self::newId(); }
            $seen[$id] = true;
            $count++;
            $node = ['id' => $id, 'type' => $type, 'column' => max(0, (int) ($entry['column'] ?? 0)), 'data' => [], 'settings' => [], 'children' => []];
            if ($block === null) {
                // A kind that no longer exists: kept as stored, shown as "Unknown block".
                $node['raw'] = $isState && is_array($entry['raw'] ?? null) ? $entry['raw'] : $entry;
                $nodes[] = $node;
                continue;
            }
            $form = $this->blockForm($block);
            $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
            $settings = is_array($entry['settings'] ?? null) ? $entry['settings'] : [];
            foreach ($form->fields() as $name => $field) {
                $isSetting = in_array($name, $this->settingNames($block), true);
                $raw = $isSetting ? ($settings[$name] ?? null) : ($data[$name] ?? null);
                $state = $isState ? (is_string($raw) ? $raw : ($raw === null ? null : (string) json_encode($raw, JSON_UNESCAPED_UNICODE)))
                    : null;
                if ($state === null) {
                    $record = $raw === null ? null : (object) [$name => is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw];
                    $value = $field->stateFromRecord($record, Languages::default());
                    $state = is_string($value) ? $value : '';
                }
                if ($isSetting) { $node['settings'][$name] = $state; } else { $node['data'][$name] = $state; }
            }
            if ($block->holdsBlocks()) {
                $children = is_array($entry['children'] ?? null) ? $entry['children'] : [];
                $kids = $this->nodes($children, $block::type(), $isState, $count, $seen);
                // Blocks that may not sit here (a Section inside Columns) move out after their parent.
                $node['children'] = array_values(array_filter($kids, fn (array $kid): bool => $this->fits($kid, $block::type())));
                $max = $block->columnCount($node['data']) - 1;
                foreach ($node['children'] as &$kid) { $kid['column'] = min($kid['column'], $max); }
                unset($kid);
                $nodes[] = $node;
                foreach ($kids as $kid) { if (!$this->fits($kid, $block::type())) { $kid['column'] = 0; $nodes[] = $kid; } }
                continue;
            }
            $nodes[] = $node;
        }
        return $nodes;
    }

    private function fits(array $node, string $parentType): bool
    {
        $block = $this->block((string) $node['type']);
        return $block === null ? true : $block->allowedIn($parentType);
    }

    /** @param array<string, list<array<string, mixed>>> $lists */
    /**
     * @param array<string, list<array<string, mixed>>> $lists
     * @param array<string, int> $expected how many blocks the browser said it sent, per list
     */
    public function encodeState(array $lists, array $expected = []): string
    {
        return json_encode(['_state' => 1, 'lists' => $lists] + ($expected !== [] ? ['sent' => $expected] : []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function stateFromRecord(?object $record, string $defaultLang): string|array
    {
        if ($record === null || !property_exists($record, $this->name)) { return $this->defaultState($defaultLang); }
        return $this->encodeState($this->lists($record->{$this->name}));
    }

    protected function defaultState(string $defaultLang): string|array
    {
        return $this->encodeState(array_fill_keys($this->listKeys(), []));
    }

    /**
     * Input: content[<lang>][<id>][type|parent|column|order|f][…] from the form,
     * with content[_total][<lang>] (how many blocks the page sent, first, so a
     * cut-off request is noticed). A JSON value (an import's cell) is read as
     * stored blocks. Either way every block goes through its fields' own input
     * handling (rich text is cleaned) and Block::acceptInput() (HTML needs its
     * permission); nothing the browser sends is stored as it is.
     */
    public function stateFromInput(array $input, array $languages): string|array
    {
        $value = $input[$this->name] ?? [];
        if (is_string($value)) { $value = $this->flatFromStored($value); }
        $value = is_array($value) ? $value : [];
        $lists = [];
        $expected = [];
        foreach ($this->listKeys() as $key) {
            $entries = is_array($value[$key] ?? null) ? $value[$key] : [];
            $lists[$key] = $this->fromInput($entries, $languages);
            $total = $value['_total'][$key] ?? null;
            if (is_scalar($total) && is_numeric((string) $total)) { $expected[$key] = max(0, (int) $total); }
        }
        return $this->encodeState($lists, $expected);
    }

    /**
     * Stored blocks (JSON, or the editor's state) as the form's flat inputs, so
     * they are read like a submitted form.
     *
     * @return array<string, mixed>
     */
    private function flatFromStored(string $json): array
    {
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded)) { return []; }
        if (($decoded['_state'] ?? null) === 1 && is_array($decoded['lists'] ?? null)) { $decoded = $decoded['lists']; }
        $keys = $this->listKeys();
        $lists = array_is_list($decoded) ? [$keys[0] => $decoded] : $decoded;
        if (!$this->perLanguage && !array_key_exists(self::SINGLE, $lists)) { $lists = [self::SINGLE => self::defaultList($lists)]; }
        $flat = [];
        foreach ($keys as $key) {
            $entries = [];
            $walk = function (array $nodes, string $parent, int $depth) use (&$walk, &$entries): void {
                foreach (array_values($nodes) as $order => $node) {
                    if (!is_array($node) || $depth > self::DEPTH || count($entries) >= self::BLOCKS_LIMIT) { continue; }
                    $id = self::isId($node['id'] ?? null) && !isset($entries[$node['id']]) ? (string) $node['id'] : self::newId();
                    $fields = [];
                    foreach (array_merge(is_array($node['data'] ?? null) ? $node['data'] : [], is_array($node['settings'] ?? null) ? $node['settings'] : []) as $name => $item) {
                        $fields[$name] = is_bool($item) ? ($item ? '1' : '0') : (is_int($item) || is_float($item) ? (string) $item : $item);
                    }
                    $entries[$id] = ['type' => (string) ($node['type'] ?? ''), 'parent' => $parent, 'column' => (string) (int) ($node['column'] ?? 0), 'order' => (string) $order, 'f' => $fields];
                    if (is_array($node['children'] ?? null)) { $walk($node['children'], $id, $depth + 1); }
                }
            };
            $walk(is_array($lists[$key] ?? null) ? $lists[$key] : [], '', 0);
            $flat[$key] = $entries;
        }
        return $flat;
    }

    /** The default language's list of a value stored with one list per language (or the first one there is). */
    private static function defaultList(array $value): array
    {
        $default = $value[Languages::default()] ?? null;
        if (is_array($default) && $default !== []) { return $default; }
        foreach ($value as $list) { if (is_array($list) && array_is_list($list) && $list !== []) { return $list; } }
        return [];
    }

    /** The most levels of blocks inside blocks. */
    public const DEPTH = 4;

    /**
     * One list's blocks from its flat inputs: each block's fields through
     * their own input handling, then the tree from parent, column and order.
     *
     * @param array<array-key, mixed> $entries
     * @param list<string> $languages
     * @return list<array<string, mixed>>
     */
    private function fromInput(array $entries, array $languages): array
    {
        $flat = [];
        foreach ($entries as $id => $entry) {
            if (!self::isId($id) || !is_array($entry) || count($flat) >= self::BLOCKS_LIMIT) { continue; }
            $id = (string) $id;
            $type = (string) ($entry['type'] ?? '');
            $block = $this->block($type);
            $node = ['id' => $id, 'type' => $type, 'column' => max(0, min(11, (int) ($entry['column'] ?? 0))), 'data' => [], 'settings' => [], 'children' => [],
                'parent' => is_string($entry['parent'] ?? null) && self::isId($entry['parent']) ? $entry['parent'] : '',
                'order' => (int) ($entry['order'] ?? count($flat))];
            if ($block === null) {
                // A kind that no longer exists: kept only as the panel printed it (signed), never as the browser says.
                $raw = self::unsign(is_string($entry['raw'] ?? null) ? $entry['raw'] : '', 'raw|' . $id);
                $raw = $raw !== null ? json_decode($raw, true) : null;
                if (!is_array($raw) || ($raw['type'] ?? null) !== $type || $this->block($type) !== null) { continue; }
                $node['raw'] = $raw;
                $flat[$id] = $node;
                continue;
            }
            $fields = is_array($entry['f'] ?? null) ? $entry['f'] : [];
            $state = [];
            foreach ($this->blockForm($block)->fields() as $name => $field) {
                $item = $field->stateFromInput($fields, $languages);
                $state[$name] = is_string($item) ? $item : '';
            }
            $state = $block->acceptInput($state, $id);
            // Someone without the HTML permission cannot add an HTML block (an empty one is dropped).
            if ($block instanceof Html && !Html::allowed() && trim((string) ($state['html'] ?? '')) === '') { continue; }
            foreach ($state as $name => $item) {
                if (in_array($name, $this->settingNames($block), true)) { $node['settings'][$name] = $item; } else { $node['data'][$name] = $item; }
            }
            $flat[$id] = $node;
        }
        // The tree: children under parents that hold blocks and accept them; the rest at the top.
        uasort($flat, static fn (array $a, array $b): int => [$a['column'], $a['order']] <=> [$b['column'], $b['order']]);
        $byParent = [];
        $placed = [];
        foreach ($flat as $id => $node) {
            $parent = $node['parent'];
            $parentBlock = $parent !== '' && isset($flat[$parent]) ? $this->block((string) $flat[$parent]['type']) : null;
            $placed[$id] = $parentBlock !== null && $parentBlock->holdsBlocks() && $this->fits($node, $parentBlock::type()) && !self::reaches($flat, $parent, $id);
            if ($placed[$id]) { $byParent[$parent][] = $id; }
        }
        // A block that may not sit where it was put goes to the top, after the top block it was in.
        $topOf = static function (string $id) use ($flat, $placed): string {
            for ($i = 0; $i < 10 && $placed[$id]; $i++) { $id = $flat[$id]['parent']; }
            return $id;
        };
        $roots = array_values(array_filter(array_keys($flat), static fn (string $id): bool => !$placed[$id]));
        $key = static function (string $id) use ($flat, $topOf): array {
            $parent = $flat[$id]['parent'];
            if ($parent === '' || !isset($flat[$parent])) { return [$flat[$id]['order'], 0, 0]; }
            return [$flat[$topOf($parent)]['order'], 1, $flat[$id]['order']];
        };
        usort($roots, static fn (string $a, string $b): int => $key($a) <=> $key($b));
        $build = function (string $id, int $depth) use (&$build, $flat, $byParent, &$roots): array {
            $node = $flat[$id];
            unset($node['parent'], $node['order']);
            $block = $this->block((string) $node['type']);
            $max = $block !== null ? $block->columnCount($node['data']) - 1 : 0;
            foreach ($byParent[$id] ?? [] as $child) {
                $kid = $build($child, $depth + 1);
                $kid['column'] = min($kid['column'], $max);
                $node['children'][] = $kid;
            }
            return $node;
        };
        $tree = [];
        foreach ($roots as $id) { $node = $build($id, 0); $node['column'] = 0; $tree[] = $node; }
        return $tree;
    }

    /** Whether following parents up from $from leads to $id (a loop). */
    private static function reaches(array $flat, string $from, string $id): bool
    {
        for ($i = 0, $at = $from; $i < self::BLOCKS_LIMIT && $at !== '' && isset($flat[$at]); $i++, $at = $flat[$at]['parent']) {
            if ($at === $id) { return true; }
        }
        return false;
    }

    /**
     * A value signed with this session's key (the panel's own copy of something
     * a form carries back, such as a block that no longer exists).
     */
    public static function sign(string $value, string $purpose): string
    {
        return hash_hmac('sha256', $purpose . '|' . $value, self::key()) . '|' . base64_encode($value);
    }

    /** The value of sign(), or null when it was changed. */
    public static function unsign(string $signed, string $purpose): ?string
    {
        [$sig, $encoded] = array_pad(explode('|', $signed, 2), 2, '');
        $value = base64_decode($encoded, true);
        return is_string($value) && hash_equals(hash_hmac('sha256', $purpose . '|' . $value, self::key()), $sig) ? $value : null;
    }

    private static function key(): string
    {
        $key = \FloCMS\Core\Session::get('admin_blocks_key');
        if (!is_string($key) || strlen($key) < 32) { $key = bin2hex(random_bytes(32)); \FloCMS\Core\Session::set('admin_blocks_key', $key); }
        return $key;
    }

    public function submitted(array $input): bool
    {
        return array_key_exists($this->name, $input);
    }

    /**
     * The value written to the column: each list's blocks with their fields
     * as stored (lists as JSON arrays, toggles as "1"), or null when empty.
     */
    public function dehydrate(string|array $state): mixed
    {
        $lists = $this->lists(is_string($state) ? $state : '');
        $stored = [];
        foreach ($lists as $key => $nodes) {
            $list = array_map(fn (array $node): array => $this->storedNode($node), $nodes);
            if ($list !== []) { $stored[$key] = $list; }
        }
        if ($stored === []) { return $this->isNullable() ? null : ''; }
        $value = $this->perLanguage ? $stored : ($stored[self::SINGLE] ?? []);
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function storedNode(array $node): array
    {
        if (isset($node['raw']) && is_array($node['raw'])) { return $node['raw']; }
        $block = $this->block((string) $node['type']);
        $stored = ['id' => $node['id'], 'type' => $node['type']];
        if ((int) ($node['column'] ?? 0) > 0) { $stored['column'] = (int) $node['column']; }
        $data = [];
        $settings = [];
        if ($block !== null) {
            foreach ($this->blockForm($block)->fields() as $name => $field) {
                $isSetting = in_array($name, $this->settingNames($block), true);
                $state = (string) ($isSetting ? ($node['settings'][$name] ?? '') : ($node['data'][$name] ?? ''));
                $value = $field->dehydrate($state);
                if (($field instanceof Repeater || $field instanceof MediaPicker) && is_string($value) && str_starts_with(ltrim($value), '[')) {
                    $decoded = json_decode($value, true);
                    $value = is_array($decoded) ? $decoded : $value;
                }
                if ($isSetting && $name === 'anchor') { $value = BlockSettings::anchor((string) $value); }
                if ($isSetting && $name === 'class') { $value = BlockSettings::classes((string) $value); }
                if ($value === null || $value === '' || $value === []) { continue; }
                if ($isSetting) { $settings[$name] = $value; } else { $data[$name] = $value; }
            }
        }
        $stored['data'] = $data === [] ? new \stdClass() : $data;
        if ($settings !== []) { $stored['settings'] = $settings; }
        if ($block !== null && $block->holdsBlocks()) {
            $stored['children'] = array_map(fn (array $kid): array => $this->storedNode($kid), $node['children'] ?? []);
        }
        return $stored;
    }

    public function typeError(string $value): ?array
    {
        return strlen($value) > self::MAX_BYTES * max(1, count($this->listKeys())) ? ['validation.builder_size', ['max' => (string) intdiv(self::MAX_BYTES, 1000)]] : null;
    }

    /** How many blocks a list holds, nested ones included. */
    public static function count(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) { $count += 1 + self::count($node['children'] ?? []); }
        return $count;
    }

    /**
     * Errors of the blocks, keyed "content.<lang>.<id>.<field>" (the lists' own
     * limits under "content"), each field through $check like a form's field.
     *
     * @param \Closure(Field, string|array<string, string>, string): array<string, string> $check
     * @return array<string, string>
     */
    public function validateBlocks(string $state, \Closure $check): array
    {
        if (($size = $this->typeError($state)) !== null) {
            return [$this->name => AdminLang::get($size[0], ['attribute' => $this->getLabel()] + $size[1])];
        }
        $errors = [];
        $this->validated = $this->lists($state);
        // Fewer blocks arrived than the page sent: the server cut the request (max_input_vars).
        $decoded = json_decode($state, true);
        foreach (is_array($decoded['sent'] ?? null) ? $decoded['sent'] : [] as $key => $sent) {
            if (isset($this->validated[$key]) && self::count($this->validated[$key]) < (int) $sent) {
                return [$this->name => AdminLang::get('validation.builder_cut', ['attribute' => $this->getLabel(), 'max' => (string) ini_get('max_input_vars')])];
            }
        }
        foreach ($this->validated as $key => $nodes) {
            $walk = function (array $nodes) use (&$walk, &$errors, $key, $check): void {
                foreach ($nodes as $node) {
                    $block = $this->block((string) $node['type']);
                    if ($block !== null) {
                        $prefix = $this->name . '.' . $key . '.' . $node['id'] . '.';
                        $state = $node['data'] + $node['settings'];
                        foreach ($this->blockForm($block)->evaluate($state)->writableFields() as $name => $field) {
                            $value = $state[$name] ?? '';
                            if ($field instanceof Repeater) {
                                foreach ($field->validateItems(is_string($value) ? $value : '', $check) as $errorKey => $message) { $errors[$prefix . $errorKey] = $message; }
                                continue;
                            }
                            $errors += $check($field, $value, $prefix . $name);
                        }
                    }
                    $walk($node['children'] ?? []);
                }
            };
            $walk($nodes);
            $count = self::count($nodes);
            if ($count > $this->maxBlocks) {
                $errors[$this->name] = AdminLang::get('validation.builder_max', ['attribute' => $this->getLabel(), 'max' => (string) $this->maxBlocks]);
            }
        }
        $first = $this->validated[$this->perLanguage ? Languages::default() : self::SINGLE] ?? [];
        if ($this->isRequired() && $first === []) {
            $errors[$this->name] = AdminLang::get('validation.builder_required', ['attribute' => $this->getLabel()]);
        }
        return $errors;
    }

    /**
     * Where an error is, for the message above the form: "Page content → Call to action: Heading (Arabic)";
     * null for the field's own errors.
     */
    public function errorPlace(string $key, ?string $state = null): ?string
    {
        if (preg_match('/^[^.]+\.([^.]+)\.(b[a-z0-9]+)\.([A-Za-z0-9_]+)/', $key, $match) !== 1) { return null; }
        $lists = $state !== null ? $this->lists($state) : $this->validated;
        $node = self::findNode($lists[$match[1]] ?? [], $match[2]);
        $block = $node !== null ? $this->block((string) $node['type']) : null;
        $sub = $block !== null ? ($this->blockForm($block)->fields()[$match[3]] ?? null) : null;
        $language = $this->perLanguage && in_array($match[1], Languages::all(), true) && count(Languages::all()) > 1 ? ' (' . Languages::name($match[1]) . ')' : '';
        return AdminLang::get('blocks.error_place', ['field' => $this->getLabel(), 'block' => $node !== null ? $this->title($node) : $match[2], 'item' => $sub?->getLabel() ?? $match[3]]) . $language;
    }

    /** @return array<string, mixed>|null */
    private static function findNode(array $nodes, string $id): ?array
    {
        foreach ($nodes as $node) {
            if ($node['id'] === $id) { return $node; }
            if (($found = self::findNode($node['children'] ?? [], $id)) !== null) { return $found; }
        }
        return null;
    }

    /* ---------------------------------------------------------------
     * Changes without JavaScript: "content:block:add:<lang>:<type>:<parent>:<column>",
     * "content:block:remove|up|down:<lang>:<id>", "content:block:copy:<from>:<to>"
     * ------------------------------------------------------------- */

    public function applyOperation(string $state, string $operation): ?string
    {
        $parts = explode(':', $operation);
        if (($parts[0] ?? '') !== $this->name || ($parts[1] ?? '') !== 'block') { return null; }
        $verb = $parts[2] ?? '';
        $key = $parts[3] ?? '';
        $lists = $this->lists($state);
        if (!array_key_exists($key, $lists)) { return $this->encodeState($lists); }
        if ($verb === 'add') {
            $block = $this->block($parts[4] ?? '');
            if ($block === null || !$this->canAdd($block) || !isset($this->available()[$block::type()]) || self::count($lists[$key]) >= $this->maxBlocks) { return $this->encodeState($lists); }
            $node = $this->newNode($block);
            $parent = $parts[5] ?? '';
            $node['column'] = max(0, (int) ($parts[6] ?? 0));
            $lists[$key] = $parent !== '' && self::isId($parent) ? self::insertInto($lists[$key], $parent, $node, fn (string $type): bool => $block->allowedIn($type)) : array_merge($lists[$key], [$node]);
            return $this->encodeState($lists);
        }
        if ($verb === 'copy') {
            $to = $parts[4] ?? '';
            if (array_key_exists($to, $lists) && $to !== $key) { $lists[$to] = array_merge($lists[$to], self::withNewIds($lists[$key])); }
            return $this->encodeState($lists);
        }
        $id = $parts[4] ?? '';
        if (!self::isId($id)) { return $this->encodeState($lists); }
        if ($verb === 'item') {
            // A list inside a block: "content:block:item:_:b1:slides:add".
            $inner = implode(':', array_slice($parts, 5));
            $lists[$key] = $this->changeItems($lists[$key], $id, $inner);
            return $this->encodeState($lists);
        }
        if (!in_array($verb, ['remove', 'up', 'down'], true)) { return $this->encodeState($lists); }
        $lists[$key] = self::change($lists[$key], $id, $verb);
        return $this->encodeState($lists);
    }

    private function changeItems(array $nodes, string $id, string $operation): array
    {
        foreach ($nodes as $index => $node) {
            if ($node['id'] === $id) {
                $block = $this->block((string) $node['type']);
                if ($block === null) { return $nodes; }
                foreach ($this->blockForm($block)->fields() as $name => $field) {
                    if (!$field instanceof Repeater || !$field->isWritable()) { continue; }
                    $changed = $field->applyOperation((string) ($node['data'][$name] ?? ''), $operation);
                    if ($changed !== null) { $nodes[$index]['data'][$name] = $changed; return $nodes; }
                }
                return $nodes;
            }
            $nodes[$index]['children'] = $this->changeItems($node['children'] ?? [], $id, $operation);
        }
        return $nodes;
    }

    /** @return array<string, mixed> a new block of this kind with its defaults */
    public function newNode(Block $block, ?string $id = null): array
    {
        $node = ['id' => $id ?? self::newId(), 'type' => $block::type(), 'column' => 0, 'data' => [], 'settings' => [], 'children' => []];
        foreach ($this->blockForm($block)->fields() as $name => $field) {
            $value = $field->stateFromRecord(null, Languages::default());
            if (in_array($name, $this->settingNames($block), true)) { $node['settings'][$name] = is_string($value) ? $value : ''; } else { $node['data'][$name] = is_string($value) ? $value : ''; }
        }
        return $node;
    }

    private static function insertInto(array $nodes, string $parent, array $node, \Closure $fits): array
    {
        foreach ($nodes as &$item) {
            if ($item['id'] === $parent && $fits((string) $item['type'])) { $item['children'][] = $node; return $nodes; }
            $item['children'] = self::insertInto($item['children'] ?? [], $parent, $node, $fits);
        }
        unset($item);
        return $nodes;
    }

    private static function change(array $nodes, string $id, string $verb): array
    {
        foreach ($nodes as $index => $node) {
            if ($node['id'] === $id) {
                if ($verb === 'remove') { array_splice($nodes, $index, 1); }
                else {
                    // The block next to it in the same column (blocks of a Columns block share one list).
                    $column = (int) ($node['column'] ?? 0);
                    $step = $verb === 'up' ? -1 : ($verb === 'down' ? 1 : 0);
                    for ($other = $index + $step; $step !== 0 && $other >= 0 && $other < count($nodes); $other += $step) {
                        if ((int) ($nodes[$other]['column'] ?? 0) === $column) { [$nodes[$other], $nodes[$index]] = [$nodes[$index], $nodes[$other]]; break; }
                    }
                }
                return array_values($nodes);
            }
            $nodes[$index]['children'] = self::change($node['children'] ?? [], $id, $verb);
        }
        return $nodes;
    }

    /** A copy of blocks with new ids (Copy to another language). */
    public static function withNewIds(array $nodes): array
    {
        return array_map(static function (array $node): array {
            $node['id'] = self::newId();
            $node['children'] = self::withNewIds($node['children'] ?? []);
            return $node;
        }, $nodes);
    }

    /**
     * The library items a stored value uses: media pickers in blocks and in
     * their lists, and images in rich text.
     *
     * @return list<int>
     */
    public function mediaIds(mixed $stored): array
    {
        $ids = [];
        $walk = function (array $nodes) use (&$walk, &$ids): void {
            foreach ($nodes as $node) {
                $block = $this->block((string) $node['type']);
                if ($block !== null) {
                    $values = $node['data'] + $node['settings'];
                    foreach ($this->blockForm($block)->fields() as $name => $field) {
                        $value = (string) ($values[$name] ?? '');
                        if ($field instanceof MediaPicker) { array_push($ids, ...MediaPicker::ids($value)); }
                        elseif ($field instanceof RichEditor) { array_push($ids, ...\FloCMS\Admin\Media\MediaUsage::idsInText($value)); }
                        elseif ($field instanceof Repeater) {
                            foreach ($field->items($value) as $item) {
                                foreach ($field->fields() as $subName => $sub) {
                                    if ($sub instanceof MediaPicker) { array_push($ids, ...MediaPicker::ids($item[$subName] ?? '')); }
                                }
                            }
                        }
                    }
                }
                $walk($node['children'] ?? []);
            }
        };
        foreach ($this->lists($stored) as $nodes) { $walk($nodes); }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** A block's name in the structure. */
    public function title(array $node): string
    {
        $block = $this->block((string) $node['type']);
        if ($block === null) { return AdminLang::get('blocks.unknown', ['type' => mb_substr((string) $node['type'], 0, 40)]); }
        try { return $block->title($node['data'] ?? []); } catch (\Throwable) { return $block::label(); }
    }

    /** The rendering of the field's own control: never called (the renderer draws the editor). */
    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        return '';
    }
}
