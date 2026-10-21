<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Blocks\BlockRegistry;
use FloCMS\Admin\Form\Fields\Builder;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Tests\TestCase;

/** The Builder field (0.29): its block forms, state, storage, nesting and the no-JavaScript buttons. */
final class BuilderUnitTest extends TestCase
{
    private const LANGS = ['en', 'ar', 'ku'];

    public function testEveryCoreBlockHasAFormWithTheSharedSettings(): void
    {
        $field = Builder::make('content');
        self::assertCount(12, $field->available());
        foreach ($field->available() as $type => $block) {
            $names = array_keys($field->blockForm($block)->fields());
            self::assertContains('status', $names, $type);
            self::assertContains('anchor', $names, $type);
            self::assertNotSame('', $block::label(), $type);
            self::assertNotSame('', $field->title($field->newNode($block)), $type);
        }
        self::assertNotContains('width', $field->settingNames(BlockRegistry::find('section')), "a Section's width is its own field");
        self::assertContains('width', $field->settingNames(BlockRegistry::find('heading')));
    }

    public function testTheBlockPanelHasTheSharedSettingsAndTheDialogTheBlocksOwn(): void
    {
        $field = Builder::make('content');
        $heading = BlockRegistry::find('heading');
        // 0.32.1: the panel keeps name, note, status and dates; Show on and Advanced are the dialog's Display tab
        self::assertSame(['_label', '_note', 'status', 'starts', 'ends'], array_keys($field->settingsForm($heading)->fields()));
        self::assertSame(['text', 'level'], array_values(array_intersect(['text', 'level'], array_keys($field->contentForm($heading)->fields()))));
        self::assertSame(['devices', 'width', 'space_above', 'space_below', 'anchor', 'class'], array_slice(array_keys($field->contentForm($heading)->fields()), -6));
        // A block with no fields of its own has no dialog: everything stays in the panel
        $bare = new class extends \FloCMS\Admin\Blocks\Block {
            protected static string $type = 'bare_test';
            public function fields(): array { return []; }
        };
        self::assertFalse($field->hasContent($bare));
        self::assertSame([], $field->contentForm($bare)->fields());
        self::assertSame(['_label', '_note', 'status', 'starts', 'ends', 'devices', 'width', 'space_above', 'space_below', 'anchor', 'class'], array_keys($field->settingsForm($bare)->fields()));
        self::assertNotContains('status', array_keys($field->contentForm($heading)->fields()));
        // Section, Columns and Slider: their own name in the panel, in place of the panel's
        $slider = BlockRegistry::find('slider');
        self::assertSame('name', array_key_first($field->settingsForm($slider)->fields()));
        self::assertNotContains('_label', array_keys($field->settingsForm($slider)->fields()));
        self::assertNotContains('name', array_keys($field->contentForm($slider)->fields()));
        self::assertContains('name', array_keys($field->blockForm($slider)->fields()), 'still checked and saved with the block');
        self::assertTrue($field->hasContent($heading));
    }

    public function testAnEditorsNameComesFirstAndIsStoredAsASetting(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromInput(['content' => ['_' => [
            'bh' => ['type' => 'heading', 'f' => ['text' => 'Our work', '_label' => 'Intro', '_note' => 'Ask Sara']],
        ]]], self::LANGS);
        $node = $field->lists($state)['_'][0];
        self::assertSame('Intro', $node['settings']['_label']);
        self::assertSame('Ask Sara', $node['settings']['_note']);
        self::assertArrayNotHasKey('_label', $node['data']);
        self::assertSame('Intro', $field->title($node));
        $stored = json_decode((string) $field->dehydrate($state), true);
        self::assertSame('Intro', $stored[0]['settings']['_label']);
        self::assertSame('Our work', $field->title(['type' => 'heading', 'data' => ['text' => 'Our work'], 'settings' => ['_label' => '  ']]));
    }

    public function testEditorsNameAndNoteNeverReachTheWebsite(): void
    {
        $json = json_encode([['id' => 'bh', 'type' => 'heading', 'data' => ['text' => 'Hello', 'level' => 'h2'], 'settings' => ['_label' => 'Intro', '_note' => 'Ask Sara', 'anchor' => 'top']]]);
        $data = blocks_data($json);
        self::assertSame(['anchor' => 'top'], $data[0]['settings']);
        $html = blocks($json);
        self::assertStringContainsString('Hello', $html);
        self::assertStringNotContainsString('Ask Sara', $html);
        self::assertStringNotContainsString('Intro', $html);
    }

    public function testABlockMayNotNameItsOwnFieldsLikeThePanels(): void
    {
        $block = new class extends \FloCMS\Admin\Blocks\Block {
            protected static string $type = 'clash';
            public function fields(): array { return [\FloCMS\Admin\Form\Fields\TextInput::make('_label')]; }
        };
        $this->expectException(\LogicException::class);
        Builder::make('content')->blockForm($block);
    }

    public function testTheEditorHasItsDialogsAndRowButtons(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromInput(['content' => ['_' => [
            'bh' => ['type' => 'heading', 'f' => ['text' => 'Our work', 'status' => 'draft']],
        ]]], self::LANGS);
        $html = (new FormRenderer(['content' => $state], [], self::LANGS, 'en'))->render((new Form())->schema([$field]));
        foreach (['data-builder-edit-dialog', 'data-builder-add-dialog', 'data-builder-confirm', 'data-builder-paste'] as $part) { self::assertStringContainsString($part, $html, $part); }
        foreach (['edit', 'duplicate', 'toggle', 'remove', 'insert'] as $action) { self::assertStringContainsString('data-builder-action="' . $action . '"', $html, $action); }
        self::assertStringContainsString('fa-builder-node is-draft', $html);
        self::assertStringContainsString('aria-label="Show on the website"', $html);
        self::assertStringContainsString('data-builder-fields="bh"', $html);
        self::assertStringContainsString('data-builder-settings', $html);
        // 0.32: one block per dialog; the ⋯ menu; a + between blocks
        self::assertStringNotContainsString('data-builder-dialog-nav', $html);
        self::assertStringContainsString('data-builder-action="more"', $html);
        self::assertStringContainsString('fa-builder-inserter', $html);
        // Containers fold; Columns are a grid as wide as their layout, each column with its width
        $state = $field->stateFromInput(['content' => ['_' => [
            'bc' => ['type' => 'columns', 'f' => ['layout' => '67-33']],
            'bt' => ['type' => 'heading', 'parent' => 'bc', 'column' => '1', 'f' => ['text' => 'Right']],
        ]]], self::LANGS);
        $html = (new FormRenderer(['content' => $state], [], self::LANGS, 'en'))->render((new Form())->schema([$field]));
        self::assertStringContainsString('data-builder-action="fold"', $html);
        self::assertStringContainsString('data-builder-cols data-count="2" data-layout="67-33"', $html);
        self::assertStringContainsString('data-builder-col-width>33%<', $html);
        self::assertStringContainsString('data-builder-col-width>67%<', $html);
        self::assertStringContainsString('aria-label="Add to column 2"', $html);
        // Read only: no Add, Delete or confirm; the dialog shows the fields
        $html = (new FormRenderer(['content' => $state], [], self::LANGS, 'en'))->render((new Form())->schema([Builder::make('content')->disabled()]));
        self::assertStringContainsString('data-builder-edit-dialog', $html);
        self::assertStringNotContainsString('data-builder-add-dialog', $html);
        self::assertStringNotContainsString('data-builder-action="remove"', $html);
    }

    public function testOnlyAndBlocksNarrowTheChoice(): void
    {
        self::assertSame(['section', 'columns'], array_keys(Builder::make('c')->only(['layout'])->available()));
        self::assertSame(['heading', 'image'], array_keys(Builder::make('c')->blocks(['heading', 'image'])->available()));
        $this->expectException(\InvalidArgumentException::class);
        Builder::make('c')->blocks(['nope']);
    }

    public function testInputBuildsTheTreeAndIsStoredAsJson(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromInput(['content' => ['_' => [
            'bsec' => ['type' => 'section', 'order' => '0', 'f' => ['name' => 'Intro', 'width' => 'narrow', 'status' => 'published']],
            'bcol' => ['type' => 'columns', 'parent' => 'bsec', 'order' => '0', 'f' => ['layout' => '33-33-33']],
            'bh1' => ['type' => 'heading', 'parent' => 'bcol', 'column' => '2', 'order' => '0', 'f' => ['text' => 'Right', 'level' => 'h2']],
            'bh2' => ['type' => 'heading', 'parent' => 'bcol', 'column' => '0', 'order' => '0', 'f' => ['text' => 'Left', 'anchor' => ' our team! ']],
            'bnest' => ['type' => 'section', 'parent' => 'bcol', 'order' => '1', 'f' => ['name' => 'Not here']],
            'btop' => ['type' => 'rich_text', 'order' => '1', 'f' => ['body' => '<p>Hi</p>']],
            '<x>' => ['type' => 'heading', 'f' => ['text' => 'forged id']],
        ]]], self::LANGS);
        $lists = $field->lists($state);
        $top = $lists['_'];
        self::assertSame(['bsec', 'bnest', 'btop'], array_column($top, 'id'), 'a Section inside Columns moves to the top, after the block it was in');
        $columns = $top[0]['children'][0];
        self::assertSame('bcol', $columns['id']);
        self::assertSame(['bh2', 'bh1'], array_column($columns['children'], 'id'), 'by column, then order');
        self::assertSame([0, 2], array_column($columns['children'], 'column'));

        $stored = $field->dehydrate($state);
        self::assertIsString($stored);
        $json = json_decode($stored, true);
        self::assertSame('section', $json[0]['type']);
        self::assertSame(['name' => 'Intro', 'width' => 'narrow'] + $json[0]['data'], $json[0]['data']);
        self::assertSame('our-team', $json[0]['children'][0]['children'][0]['settings']['anchor'], 'anchor cleaned');
        self::assertSame(2, $json[0]['children'][0]['children'][1]['column']);
        self::assertArrayNotHasKey('column', $json[0]['children'][0]['children'][0], 'column 0 is left out');

                // Stored values read back; fields left out of the stored JSON get their defaults
        $again = $field->stateFromRecord((object) ['content' => $stored], 'en');
        $full = $field->dehydrate($again);
        self::assertSame('large', json_decode((string) $full, true)[0]['data']['padding']);
        self::assertSame($full, $field->dehydrate($field->stateFromRecord((object) ['content' => $full], 'en')));
        self::assertSame($again, $field->stateFromInput(['content' => $again], self::LANGS), "a dialog's draft");
        self::assertNull($field->dehydrate($field->stateFromInput(['content' => []], self::LANGS)), 'no blocks: NULL');
    }

    public function testFewerColumnsMoveBlocksIntoTheLastOne(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromInput(['content' => ['_' => [
            'bcol' => ['type' => 'columns', 'f' => ['layout' => '50-50']],
            'bh' => ['type' => 'heading', 'parent' => 'bcol', 'column' => '3', 'f' => ['text' => 'Was in the 4th']],
        ]]], self::LANGS);
        self::assertSame(1, $field->lists($state)['_'][0]['children'][0]['column']);
    }

    public function testTranslatableKeepsOneListPerLanguage(): void
    {
        $field = Builder::make('content')->translatable();
        $state = $field->stateFromInput(['content' => [
            'en' => ['b1' => ['type' => 'heading', 'f' => ['text' => 'Hello']]],
            'ar' => ['b2' => ['type' => 'heading', 'f' => ['text' => 'مرحبا']]],
        ]], self::LANGS);
        $json = json_decode((string) $field->dehydrate($state), true);
        self::assertSame(['en', 'ar'], array_keys($json));
        self::assertSame('مرحبا', $json['ar'][0]['data']['text']);
        // A list stored before ->translatable() becomes the default language's
        $old = $field->lists('[{"id":"b1","type":"heading","data":{"text":"Old"}}]');
        self::assertSame('Old', $old['en'][0]['data']['text']);
        // Copy all from English
        $copied = $field->lists((string) $field->applyOperation($state, 'content:block:copy:en:ku'));
        self::assertSame('Hello', $copied['ku'][0]['data']['text']);
        self::assertNotSame('b1', $copied['ku'][0]['id'], 'new ids');
    }

    public function testUnknownBlocksAreKeptAsStored(): void
    {
        $field = Builder::make('content');
        $stored = '[{"id":"b1","type":"gone","data":{"x":1}},{"id":"b2","type":"heading","data":{"text":"Hi"}}]';
        $state = $field->stateFromRecord((object) ['content' => $stored], 'en');
        self::assertStringContainsString('Unknown', $field->title($field->lists($state)['_'][0]) . 'Unknown');
        self::assertSame('{"id":"b1","type":"gone","data":{"x":1}}', json_encode(json_decode((string) $field->dehydrate($state), true)[0]));
    }

    public function testOperationsWithoutJavaScript(): void
    {
        $field = Builder::make('content')->maxBlocks(3);
        $state = $field->stateFromInput(['content' => ['_' => []]], self::LANGS);
        $state = (string) $field->applyOperation($state, 'content:block:add:_:section');
        $section = $field->lists($state)['_'][0]['id'];
        $state = (string) $field->applyOperation($state, 'content:block:add:_:heading:' . $section . ':0');
        $state = (string) $field->applyOperation($state, 'content:block:add:_:section:' . $section . ':0');
        $lists = $field->lists($state);
        self::assertCount(1, $lists['_'], 'a Section is not added inside a Section');
        self::assertSame('heading', $lists['_'][0]['children'][0]['type']);
        $state = (string) $field->applyOperation($state, 'content:block:add:_:rich_text');
        $state = (string) $field->applyOperation($state, 'content:block:add:_:rich_text');
        self::assertSame(3, Builder::count($field->lists($state)['_']), 'maxBlocks');
        $text = $field->lists($state)['_'][1]['id'];
        $state = (string) $field->applyOperation($state, 'content:block:up:_:' . $text);
        self::assertSame('rich_text', $field->lists($state)['_'][0]['type']);
        $state = (string) $field->applyOperation($state, 'content:block:remove:_:' . $text);
        self::assertSame(['section'], array_column($field->lists($state)['_'], 'type'));
        self::assertNull($field->applyOperation($state, 'rooms:add'));
        self::assertNull($field->applyOperation($state, 'content:add'));
    }

    public function testHtmlNeedsItsPermission(): void
    {
        $field = Builder::make('content');
        self::assertFalse($field->canAdd(BlockRegistry::find('html')), 'nobody signed in');
        $state = (string) $field->applyOperation($field->stateFromInput(['content' => []], self::LANGS), 'content:block:add:_:html');
        self::assertSame([], $field->lists($state)['_']);
    }

    public function testValidationKeysPointAtTheBlocksFields(): void
    {
        $field = Builder::make('content')->required();
        $state = $field->stateFromInput(['content' => ['_' => [
            'b1' => ['type' => 'call_to_action', 'f' => ['heading' => '', 'button' => 'Go', 'link' => '/contact']],
        ]]], self::LANGS);
        $errors = $field->validateBlocks($state, static function ($inner, $value, string $key): array {
            return $inner->isRequired() && trim((string) $value) === '' ? [$key => 'required'] : [];
        });
        self::assertSame(['content._.b1.heading' => 'required'], $errors);
        $empty = $field->validateBlocks($field->stateFromInput(['content' => []], self::LANGS), static fn (): array => []);
        self::assertArrayHasKey('content', $empty);
    }

    public function testTheEditorNamesEveryBlocksFieldsAndPlace(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromInput(['content' => ['_' => [
            'bcol' => ['type' => 'columns', 'f' => ['layout' => '50-50']],
            'bh' => ['type' => 'heading', 'parent' => 'bcol', 'column' => '1', 'f' => ['text' => 'Our work']],
            'bs' => ['type' => 'slider', 'f' => ['name' => 'Home slider']],
        ]]], self::LANGS);
        $html = (new FormRenderer(['content' => $state], ['content._.bh.text' => 'Too long.'], self::LANGS, 'en'))->render((new Form())->schema([$field]));
        self::assertMatchesRegularExpression('/name="content\\[_\\]\\[bh\\]\\[f\\]\\[text\\]"[^>]*value="Our work"/', $html);
        self::assertStringContainsString('name="content[_][bh][parent]" value="bcol"', $html);
        self::assertStringContainsString('name="content[_][bh][column]" value="1"', $html);
        self::assertStringContainsString('value="content:block:item:_:bs:slides:add"', $html, "a slider's slides without JavaScript");
        self::assertStringContainsString('value="content:block:add:_:heading:bcol:1"', $html, 'add to the second column');
        self::assertStringContainsString('data-builder-template="slider"', $html);
        self::assertStringNotContainsString('data-builder-template="html"', $html, 'HTML needs its permission');
        self::assertStringContainsString('Too long.', $html);
        self::assertMatchesRegularExpression('/data-builder-node="bh"[^>]*>/', $html);
        self::assertStringContainsString('fa-builder-node has-error" data-builder-node="bh"', $html);
        // A slide added without JavaScript
        $form = (new Form())->schema([Builder::make('content')]);
        $values = ['content' => $state];
        self::assertTrue($form->applyRepeaterOperation($values, 'content:block:item:_:bs:slides:add'));
        self::assertCount(1, json_decode($field->lists($values['content'])['_'][1]['data']['slides'], true));
    }

    public function testLockedHtmlKeepsItsMarkupAndCannotBeChanged(): void
    {
        $session = $_SESSION ?? [];
        try {
            \FloCMS\Core\Config::set('permissions', [1 => [], 2 => ['blocks.html']]);
            $_SESSION = ['role' => 2];
            $html = BlockRegistry::find('html');
            $field = Builder::make('content');
            $allowed = $field->stateFromInput(['content' => ['_' => ['bh' => ['type' => 'html', 'f' => ['html' => '<iframe src="/map"></iframe>']]]]], self::LANGS);
            self::assertSame('<iframe src="/map"></iframe>', $field->lists($allowed)['_'][0]['data']['html']);

            $_SESSION['role'] = 1;
            $field = Builder::make('content');
            self::assertFalse($field->canAdd($html));
            $signed = $html->prepareState(['html' => '<iframe src="/map"></iframe>'], '_|bh')['_signed'];
            $post = static fn (string $id, array $f): array => ['content' => ['_' => [$id => ['type' => 'html', 'f' => $f]]]];
            // Sent back unchanged by the same block: kept
            $kept = $field->stateFromInput($post('bh', ['html' => '<script>x</script>', '_signed' => $signed]), self::LANGS);
            self::assertSame('<iframe src="/map"></iframe>', $field->lists($kept)['_'][0]['data']['html']);
            self::assertStringNotContainsString('_signed', (string) $field->dehydrate($kept));
            // Forged, or the copy of another block: the block is not kept
            foreach ([$post('bh', ['html' => '<script>x</script>', '_signed' => 'x|' . base64_encode('<script>x</script>')]), $post('bnew', ['_signed' => $signed]), $post('bnew', ['html' => '<script>x</script>'])] as $input) {
                self::assertSame([], $field->lists($field->stateFromInput($input, self::LANGS))['_']);
            }
            // An empty HTML block someone else saved is kept too; a changed copy asks for a reload
            $empty = $html->prepareState(['html' => ''], '_|be')['_signed'];
            $kept = $field->stateFromInput(['content' => ['_total' => ['_' => '1'], '_' => ['be' => ['type' => 'html', 'f' => ['_signed' => $empty]]]]], self::LANGS);
            self::assertCount(1, $field->lists($kept)['_']);
            self::assertSame([], $field->validateBlocks($kept, static fn (): array => []));
            $stale = $field->stateFromInput(['content' => ['_total' => ['_' => '1'], '_' => ['bh' => ['type' => 'html', 'f' => ['_signed' => 'x|' . base64_encode('y')]]]]], self::LANGS);
            self::assertStringContainsString('out of date', $field->validateBlocks($stale, static fn (): array => [])['content']);
            // The JSON path (an import, a forged draft) goes through the same checks
            foreach (['[{"id":"b1","type":"html","data":{"html":"<script>x</script>"}}]', '{"_state":1,"lists":{"_":[{"id":"b1","type":"html","data":{"html":"<script>x</script>"}}]}}'] as $json) {
                self::assertNull($field->dehydrate($field->stateFromInput(['content' => $json], self::LANGS)));
            }
            // A kind that no longer exists can't be made up by the browser
            $raw = $field->stateFromInput(['content' => ['_' => ['b2' => ['type' => 'gone', 'raw' => '{"id":"b2","type":"html","data":{"html":"<script>"}}']]]], self::LANGS);
            self::assertSame([], $field->lists($raw)['_']);
        } finally {
            $_SESSION = $session;
        }
    }

    public function testRichTextFromJsonIsCleaned(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromInput(['content' => '[{"id":"b1","type":"rich_text","data":{"body":"<p onmouseover=\"alert(1)\">Hi</p><script>alert(2)</script>"}}]'], self::LANGS);
        $stored = (string) $field->dehydrate($state);
        self::assertStringContainsString('Hi', $stored);
        self::assertStringNotContainsString('onmouseover', $stored);
        self::assertStringNotContainsString('<script', $stored);
    }

    public function testListsReadAfterTranslatableWasTakenOff(): void
    {
        $field = Builder::make('content');
        $lists = $field->lists('{"en":[{"id":"b1","type":"heading","data":{"text":"Kept"}}],"ar":[]}');
        self::assertSame('Kept', $lists['_'][0]['data']['text']);
    }

    public function testUpAndDownStayInTheirColumn(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromRecord((object) ['content' => json_encode([['id' => 'bc', 'type' => 'columns', 'data' => ['layout' => '50-50'], 'children' => [
            ['id' => 'ba', 'type' => 'heading', 'data' => ['text' => 'A']], ['id' => 'bb', 'type' => 'heading', 'column' => 1, 'data' => ['text' => 'B']], ['id' => 'bd', 'type' => 'heading', 'data' => ['text' => 'C']],
        ]]])], 'en');
        $moved = $field->lists((string) $field->applyOperation($state, 'content:block:up:_:bd'))['_'][0]['children'];
        self::assertSame(['bd', 'bb', 'ba'], array_column($moved, 'id'));
        self::assertSame(['C', 'A'], array_values(array_map(static fn (array $n): string => $n['data']['text'], array_filter($moved, static fn (array $n): bool => $n['column'] === 0))));
    }

    /** The names and values a browser would post for $input under "content", packed as the editor sends them (0.32). */
    private static function packed(array $input): string
    {
        $pairs = [];
        $walk = static function (array $values, string $prefix) use (&$walk, &$pairs): void {
            foreach ($values as $key => $value) {
                if (is_array($value)) { $walk($value, $prefix . '[' . $key . ']'); } else { $pairs[] = [$prefix . '[' . $key . ']', (string) $value]; }
            }
        };
        $walk($input, '');
        return json_encode(['_form' => 1, 'pairs' => $pairs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function testThePackedValueIsReadLikeTheFormFields(): void
    {
        $input = ['_sent' => '1', '_total' => ['en' => '4', 'ar' => '1'],
            'en' => [
                'bsec' => ['type' => 'section', 'order' => '0', 'f' => ['name' => 'Intro', 'width' => 'narrow']],
                'bcol' => ['type' => 'columns', 'parent' => 'bsec', 'order' => '0', 'f' => ['layout' => '33-33-33']],
                'bh1' => ['type' => 'heading', 'parent' => 'bcol', 'column' => '2', 'order' => '0', 'f' => ['text' => 'Right "quoted" & <b>', 'level' => 'h3']],
                'btxt' => ['type' => 'rich_text', 'order' => '1', 'f' => ['body' => '<p onclick="x()">Hi</p><script>alert(1)</script>']],
            ],
            'ar' => ['bar' => ['type' => 'heading', 'f' => ['text' => 'مرحبا']]],
        ];
        $field = Builder::make('content')->translatable();
        $fields = $field->stateFromInput(['content' => $input], self::LANGS);
        $packed = $field->stateFromInput(['content' => self::packed($input)], self::LANGS);
        self::assertSame($fields, $packed, 'the same state as the fields posted one by one');
        $stored = (string) $field->dehydrate($packed);
        self::assertStringContainsString('Right \"quoted\" & <b>', $stored);
        self::assertStringNotContainsString('<script', $stored, 'rich text is cleaned as before');
        self::assertStringContainsString('مرحبا', $stored);
        self::assertSame([], $field->validateBlocks($packed, static fn (): array => []));

        // "[]" appends, as PHP's own form parsing does
        self::assertSame(['a' => ['x', 'y'], 'b' => ['c' => '1']], Builder::unpack('{"_form":1,"pairs":[["[a][]","x"],["[a][]","y"],["[b][c]","1"]]}', 1000));
        // Not a packed value: an import's stored blocks, read as before
        self::assertNull(Builder::unpack('[{"id":"b1","type":"heading","data":{"text":"Hi"}}]', 1000));
        self::assertSame('Hi', $field->lists($field->stateFromInput(['content' => '[{"id":"b1","type":"heading","data":{"text":"Hi"}}]'], self::LANGS))['en'][0]['data']['text']);
    }

    public function testAPackedValueThatCantBeReadIsRefused(): void
    {
        $field = Builder::make('content');
        $deep = '[' . implode('][', array_fill(0, Builder::PACKED_DEPTH + 1, 'a')) . ']';
        $bad = [
            '{"_form":1,"pairs":[["[_][b1][type]","heading"]',          // cut off
            '{"_form":2,"pairs":[]}',                                    // unknown version
            '{"_form":1,"pairs":{"a":["[_]","x"]}}',                     // not a list
            '{"_form":1,"pairs":[["[_][b1][type]",1]]}',                 // not a string
            '{"_form":1,"pairs":[["_][b1","x"]]}',                       // not a name
            '{"_form":1,"pairs":[["' . $deep . '","x"]]}',               // too deep
            '{"_form":1,"pairs":[["[_][b1][f][text]","' . str_repeat('x', Builder::MAX_BYTES * 4) . '"]]}', // too large
            '{"_form":1,"pairs":[["[_][9223372036854775807]","x"],["[_][]","y"]]}', // an array that can't take another item
            '{"_form":1,"pairs":[["[_][][b1]","x"]]}',                     // "[]" before the end
        ];
        // Too many arrays for any real page: refused before it uses much memory
        $pairs = [];
        for ($i = 0; $i <= Builder::PACKED_ARRAYS; $i++) { $pairs[] = ['[_][b' . $i . '][f][x]', '1']; }
        $bad[] = json_encode(['_form' => 1, 'pairs' => $pairs]);
        foreach ($bad as $json) {
            $state = $field->stateFromInput(['content' => $json], self::LANGS);
            $errors = $field->validateBlocks($state, static fn (): array => []);
            self::assertStringContainsString("can't read", $errors['content'] ?? '', substr($json, 0, 40));
            self::assertNull($field->dehydrate($state));
        }
    }

    public function testPackedHtmlBlocksKeepTheirSignatureCheck(): void
    {
        $session = $_SESSION ?? [];
        try {
            \FloCMS\Core\Config::set('permissions', [1 => []]);
            $_SESSION = ['role' => 1];
            $field = Builder::make('content');
            $forged = ['_' => ['bh' => ['type' => 'html', 'f' => ['html' => '<script>x</script>']]]];
            self::assertSame([], $field->lists($field->stateFromInput(['content' => self::packed($forged)], self::LANGS))['_']);
            $raw = ['_' => ['b2' => ['type' => 'gone', 'raw' => '{"id":"b2","type":"html","data":{"html":"<script>"}}']]];
            self::assertSame([], $field->lists($field->stateFromInput(['content' => self::packed($raw)], self::LANGS))['_']);
        } finally {
            $_SESSION = $session;
        }
    }

    public function testChecksAcrossABlocksFields(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromInput(['content' => ['_' => [
            'bh' => ['type' => 'hero', 'f' => ['heading' => 'Hi', 'button' => 'Go', 'link2' => '/two']],
            'bs' => ['type' => 'slider', 'f' => ['slides' => ['k1' => ['media' => '1', 'button' => 'More'], 'k2' => ['media' => '2', 'link' => '/x', 'button' => 'Fine']]]],
            'bv' => ['type' => 'video', 'f' => []],
            'bw' => ['type' => 'video', 'f' => ['url' => 'https://example.com/clip.mp4']],
            'bok' => ['type' => 'video', 'f' => ['url' => 'https://youtu.be/abcdefghijk']],
        ]]], self::LANGS);
        $errors = $field->validateBlocks($state, static fn (): array => []);
        self::assertStringContainsString('Add a link', $errors['content._.bh.link'] ?? '');
        self::assertStringContainsString('Add the button', $errors['content._.bh.button2'] ?? '');
        self::assertStringContainsString('Add a link', $errors['content._.bs.slides.0.link'] ?? '');
        self::assertArrayNotHasKey('content._.bs.slides.1.link', $errors);
        self::assertStringContainsString('Choose a video file', $errors['content._.bv.video'] ?? '');
        self::assertStringContainsString('YouTube and Vimeo', $errors['content._.bw.url'] ?? '');
        self::assertSame([], array_filter(array_keys($errors), static fn (string $key): bool => str_contains($key, '.bok.')));
    }

    public function testFieldsShownForOneChoiceAreAlwaysOnThePage(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromInput(['content' => ['_' => [
            'bs' => ['type' => 'slider', 'f' => ['height' => 'screen', 'height_px' => '700', 'autoplay' => '1']],
            'bf' => ['type' => 'slider', 'f' => ['height' => 'fixed', 'autoplay' => '0']],
        ]]], self::LANGS);
        $html = (new FormRenderer(['content' => $state], [], self::LANGS, 'en'))->render((new Form())->schema([$field]));
        // Full screen: the pixel heights are there but hidden; Fixed: shown. The interval follows Play automatically.
        self::assertMatchesRegularExpression('/data-shown-when="content\[_\]\[bs\]\[f\]\[height\]" data-shown-values="fixed" hidden data-field="height_px"/', $html);
        self::assertMatchesRegularExpression('/data-shown-when="content\[_\]\[bf\]\[f\]\[height\]" data-shown-values="fixed" data-field="height_px"/', $html);
        self::assertMatchesRegularExpression('/data-shown-when="content\[_\]\[bf\]\[f\]\[autoplay\]" data-shown-values="1" hidden data-field="interval"/', $html);
        // Hidden or not, a field keeps its value
        self::assertSame('700', $field->lists($state)['_'][0]['data']['height_px']);
        // A hidden field with an error is shown, so the error can be fixed
        $html = (new FormRenderer(['content' => $state], ['content._.bs.height_px' => 'Too small.'], self::LANGS, 'en'))->render((new Form())->schema([$field]));
        self::assertMatchesRegularExpression('/data-shown-when="content\\[_\\]\\[bs\\]\\[f\\]\\[height\\]" data-shown-values="fixed" data-field="height_px"/', $html);
    }

    public function testACutRequestIsNotSaved(): void
    {
        $field = Builder::make('content');
        $state = $field->stateFromInput(['content' => ['_total' => ['_' => '3'], '_' => ['b1' => ['type' => 'heading', 'f' => ['text' => 'Only one arrived']]]]], self::LANGS);
        self::assertArrayHasKey('content', $field->validateBlocks($state, static fn (): array => []));
        $whole = $field->stateFromInput(['content' => ['_total' => ['_' => '1'], '_' => ['b1' => ['type' => 'heading', 'f' => ['text' => 'All']]]]], self::LANGS);
        self::assertSame([], $field->validateBlocks($whole, static fn (): array => []));
    }

    public function testMediaInBlocksIsCounted(): void
    {
        $field = Builder::make('content')->translatable();
        $stored = json_encode(['en' => [
            ['id' => 'b1', 'type' => 'image', 'data' => ['media' => '7']],
            ['id' => 'b2', 'type' => 'slider', 'data' => ['slides' => [['_key' => 'k1', 'media' => '8'], ['_key' => 'k2', 'media' => '7']]]],
        ], 'ar' => [['id' => 'b3', 'type' => 'gallery', 'data' => ['images' => ['9', '10']]]]]);
        $ids = $field->mediaIds($stored);
        sort($ids);
        self::assertSame([7, 8, 9, 10], $ids);
    }

    public function testTheActivityLogNamesBlocks(): void
    {
        $field = Builder::make('content');
        $form = (new Form())->schema([$field]);
        $old = $field->stateFromRecord((object) ['content' => '[{"id":"b1","type":"heading","data":{"text":"Welcome"}},{"id":"b2","type":"rich_text","data":{"body":"<p>Hi</p>"}}]'], 'en');
        $new = $field->stateFromRecord((object) ['content' => '[{"id":"b2","type":"rich_text","data":{"body":"<p>Hi</p>"}},{"id":"b1","type":"heading","data":{"text":"Hello"}},{"id":"b3","type":"call_to_action","data":{"heading":"Start"}}]'], 'en');
        $changes = \FloCMS\Admin\Activity\ActivityChanges::diff($form, ['content' => $old], ['content' => $new]);
        $types = array_column($changes, 'type');
        self::assertContains('order', $types);
        self::assertContains(['label' => 'Content', 'type' => 'item', 'after' => 'Start'], array_map(static fn (array $c): array => array_intersect_key($c, ['label' => 1, 'type' => 1, 'after' => 1]), $changes));
        $value = array_values(array_filter($changes, static fn (array $c): bool => $c['type'] === 'value'))[0];
        self::assertSame(['Content · Hello · Heading', 'Welcome', 'Hello'], [$value['label'], $value['before'], $value['after']]);
        self::assertSame([], \FloCMS\Admin\Activity\ActivityChanges::diff($form, ['content' => $old], ['content' => $old]));
    }

    public function testErrorSummaryNamesTheBlock(): void
    {
        $field = Builder::make('content')->label('Page content');
        $form = (new Form())->schema([$field]);
        $state = $field->stateFromRecord((object) ['content' => '[{"id":"b1","type":"call_to_action","data":{"heading":"Start"}}]'], 'en');
        self::assertSame('Page content → Start: Button text', $form->errorSummary(['content._.b1.button' => 'Required.'], ['content' => $state]));
    }
}
