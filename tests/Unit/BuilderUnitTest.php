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
}
