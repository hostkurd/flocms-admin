<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Blocks\BlockColours;
use FloCMS\Admin\Blocks\BlockCss;
use FloCMS\Admin\Blocks\BlockRegistry;
use FloCMS\Admin\Blocks\BlockRenderer;
use FloCMS\Admin\Blocks\BlockSettings;
use FloCMS\Admin\Blocks\BlockStyles;
use FloCMS\Admin\Blocks\Core\Section;
use FloCMS\Admin\Form\Fields\Builder;
use FloCMS\Admin\Http\BlocksPreviewController;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Tests\TestCase;

/** A block's Display tab (0.33): spacing, styles, theme colours, hooks, its own CSS and the preview's blocks. */
final class BlockDisplayTest extends TestCase
{
    private const LANGS = ['en', 'ar', 'ku'];
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $_SESSION ?? [];
        BlockStyles::reset();
        BlockRenderer::reset(strtotime('2026-10-22 12:00'));
        @array_map('unlink', glob(Admin::root() . '/storage/cache/blocks/*') ?: []);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        BlockStyles::reset();
        BlockRenderer::reset();
        AdminLang::setLanguage(null);
        Admin::useConfig([], true, sys_get_temp_dir());
        BlockRegistry::reset();
        parent::tearDown();
    }

    private static function config(array $values): void
    {
        Admin::useConfig($values, true, sys_get_temp_dir());
        BlockRegistry::reset();
    }

    /** Signed in with a role that may (2) or may not (1) write CSS. */
    private static function role(bool $css): void
    {
        \FloCMS\Core\Config::set('permissions', [1 => [], 2 => [BlockCss::PERMISSION]]);
        $_SESSION = ['role' => $css ? 2 : 1];
    }

    public function testCustomCssIsScopedToTheBlock(): void
    {
        $scope = '.flo-block[data-block-id="b8f2c1"]';
        $css = "& { border-radius: 24px; }\n.flo-card, .flo-card:is(.a, .b) { gap: 2rem; }\n&.is-wide > .x:hover {}\n@media (max-width: 767px) {\n  & { padding-inline: 0; }\n  h2 { font-size: 1.5rem }\n}\ncolor: red;\n@keyframes pop { from { opacity: 0 } to { opacity: 1 } }\n/* a comment { */ p::before { content: \"{ ; }\"; }";
        self::assertNull(BlockCss::problem($css));
        self::assertSame($scope . '{border-radius: 24px;}'
            . $scope . ' .flo-card,' . $scope . ' .flo-card:is(.a, .b){gap: 2rem;}'
            . $scope . '.is-wide > .x:hover{}'
            . '@media (max-width: 767px){' . $scope . '{padding-inline: 0;}' . $scope . ' h2{font-size: 1.5rem}}'
            . $scope . '{color: red}'
            . '@keyframes pop{from { opacity: 0 } to { opacity: 1 }}'
            . $scope . ' p::before{content: "{ ; }";}', BlockCss::scope($css, 'b8f2c1'));
        self::assertSame('', BlockCss::scope($css, 'bad id'), 'only a block id');
        self::assertSame('', BlockCss::scope('', 'b1'));
    }

    public function testCustomCssThatCouldLoadFilesOrEscapeTheBlockIsRefused(): void
    {
        $cases = [
            "& { color: red; }\n& { background: url(https://x.example/a.png); }" => 'Line 2: “url(” isn’t allowed in custom CSS.',
            "& {\n  background: -webkit-image-set(\"a.png\" 1x);\n}" => 'Line 2: “image-set(” isn’t allowed in custom CSS.',
            '@import "https://x.example/a.css";' => 'Line 1: “@import” isn’t allowed in custom CSS.',
            '& { content: "\\75 rl(x)"; }' => 'Line 1: “\\” isn’t allowed in custom CSS.',
            '& { color: red } </style><script>' => 'Line 1: “<” isn’t allowed in custom CSS.',
            "& { color: red; }\n}\nbody { display: none }" => 'Line 2: a { or } has no partner.',
            "& {\n  color: red;\n" => 'Line 1: a { or } has no partner.',
            "& {\n  .x { color: red }\n}" => 'Line 2: a rule inside a rule isn’t supported; write it on its own.',
            "@font-face { font-family: x; }" => 'Line 1: only @media, @supports, @container and @keyframes are allowed.',
            "\n\n{ color: red }" => 'Line 3: a rule needs a selector before {.',
            '& { behavior: x; }' => 'Line 1: “behavior” isn’t allowed in custom CSS.',
            str_repeat('a', BlockCss::MAX + 1) => 'Custom CSS may have at most 5000 characters.',
            // Strings a browser ends where this parser wouldn't (review of 0.33): a CR, a form feed, a line break
            "& { content: 'x\r}body{background:red}z{a:'; }" => 'Line 1: “U+000D” isn’t allowed in custom CSS.',
            "& { content: 'x\f}body{background:red}z{a:'; }" => 'Line 1: “U+000C” isn’t allowed in custom CSS.',
            "& { content: 'x\n}\n[data-a='}body{background:red}'] { color: blue }" => 'Line 1: a text in quotes must close on the same line.',
            "@media 'x\n { & { color: red } }" => 'Line 1: a text in quotes must close on the same line.',
            // Brackets a browser would read past the rule
            '& { a: ( { ) }' => 'Line 1: a ( or [ has no partner, or holds {, } or ;.',
            ':is( ] { color: red }' => 'Line 1: a ( or [ has no partner, or holds {, } or ;.',
            // "&" only at the start of a selector, never reaching its neighbours or the page
            ':root:has(&) body { color: red }' => 'Line 1: & may only start a selector, and not be followed by + or ~.',
            "a,\nhtml:has(&) header { color: red }" => 'Line 1: & may only start a selector, and not be followed by + or ~.',
            '& ~ * { color: red }' => 'Line 1: & may only start a selector, and not be followed by + or ~.',
            '&.x + main { color: red }' => 'Line 1: & may only start a selector, and not be followed by + or ~.',
            '& & { color: red }' => 'Line 1: & may only start a selector, and not be followed by + or ~.',
            '&x { color: red }' => 'Line 1: & may only start a selector, and not be followed by + or ~.',
        ];
        foreach ($cases as $css => $message) {
            self::assertSame($message, BlockCss::problem($css), $css);
            self::assertSame('', BlockCss::scope($css, 'b1'), $css);
        }
        self::assertNull(BlockCss::problem('& { background-image: linear-gradient(red, blue); color: var(--x); }'), 'functions that load nothing are fine');
        self::assertNull(BlockCss::problem("& {\r\n  color: red;\r\n}\r\n.x[data-y=\"a;b\"] { gap: 1px }"), 'Windows line ends and ; in a string are fine');
        self::assertSame(5000, mb_strlen(str_repeat('ب', 5000)));
        self::assertNull(BlockCss::problem('/* ' . str_repeat('ب', 4990) . ' */'), 'the limit counts characters, not bytes');
    }

    public function testStylesComeFromTheConfigPhpAndTheBlock(): void
    {
        self::config(['block_styles' => ['cards' => ['shadow' => 'With shadow', 'Bad Name' => 'x'], '*' => ['highlight' => ['en' => 'Highlighted', 'ar' => 'مميّز']], 'nope' => ['a' => 'b']]]);
        BlockStyles::add('cards', 'plain', 'Plain');
        $cards = BlockRegistry::find('cards');
        self::assertSame(['highlight' => 'Highlighted', 'shadow' => 'With shadow', 'plain' => 'Plain'], BlockStyles::for($cards));
        self::assertSame(['highlight' => 'Highlighted'], BlockStyles::for(BlockRegistry::find('heading')));
        AdminLang::setLanguage('ar');
        self::assertSame('مميّز', BlockStyles::for($cards)['highlight']);
        AdminLang::setLanguage(null);
        self::assertSame(['block_styles: "nope" is not a block type (or "*").', 'block_styles.cards: "Bad Name" is not a valid style name (lower-case letters, digits and -).'], array_reverse(BlockStyles::problems()));
        $this->expectException(\InvalidArgumentException::class);
        BlockStyles::add('cards', 'With Shadow', 'x');
    }

    public function testThePaletteIsTheThemesColours(): void
    {
        self::assertFalse(BlockColours::configured());
        self::assertSame(['brand', 'light', 'dark', 'muted'], array_keys(BlockColours::all()));
        self::assertSame('', BlockCss::colourRule('brand'), 'blocks.css styles the default colours with its variables');
        self::config(['block_colours' => ['sand' => ['label' => 'Sand', 'value' => '#F4EDE1'], 'ink' => ['value' => '#101828', 'text' => 'light'], 'none' => ['value' => '#fff'], 'bad' => ['value' => 'red']]]);
        self::assertTrue(BlockColours::configured());
        self::assertSame(['sand' => ['label' => 'Sand', 'value' => '#f4ede1', 'text' => 'dark'], 'ink' => ['label' => 'Ink', 'value' => '#101828', 'text' => 'light']], BlockColours::all());
        self::assertSame('.has-bg-sand,.flo-section.has-bg-sand{background-color:#f4ede1;color:var(--flo-dark,#101828)}', BlockCss::colourRule('sand'));
        self::assertSame('.has-bg-ink,.flo-section.has-bg-ink{background-color:#101828;color:#fff}', BlockCss::colourRule('ink'));
        self::assertCount(2, BlockColours::problems());
        self::assertSame('light', BlockColours::textFor('#0b6e91'));
        self::assertSame('dark', BlockColours::textFor('#fc0'));
        // A Section offers the palette, and Light, Dark and Brand colour, which it has always had
        self::assertSame(['none', 'sand', 'ink', 'light', 'dark', 'brand', 'colour', 'image'], array_keys(Section::backgrounds()));
    }

    public function testTheWebsiteGetsTheStyleColourSpacingAndHooks(): void
    {
        self::config(['block_styles' => ['heading' => ['underlined' => 'Underlined']], 'block_colours' => ['sand' => ['value' => '#f4ede1']]]);
        $page = [
            ['id' => 'bh1', 'type' => 'heading', 'data' => ['text' => 'One'], 'settings' => ['_style' => 'underlined', '_bg' => 'sand', '_text' => 'light', 'space_above' => 'xlarge', 'space_below_phone' => 'none', '_css' => '& { color: red }']],
            ['id' => 'bh2', 'type' => 'heading', 'data' => ['text' => 'Two'], 'settings' => ['_style' => 'gone', '_bg' => 'unknown', 'space_above' => '', 'space_below_phone' => 'huge', '_css' => '& { background: url(x) }']],
            ['id' => 'bs1', 'type' => 'section', 'data' => ['background' => 'sand'], 'children' => [['id' => 'bh3', 'type' => 'heading', 'data' => ['text' => 'In']]]],
        ];
        $html = blocks(json_encode($page));
        self::assertStringContainsString('<div class="flo-block flo-block-heading is-boxed flo-space-above-xlarge flo-space-below-medium flo-space-below-phone-none is-style-underlined has-bg has-bg-sand has-text-light" data-block="heading" data-block-id="bh1">', $html);
        self::assertStringContainsString('<div class="flo-block flo-block-heading is-boxed flo-space-above-medium flo-space-below-medium" data-block="heading" data-block-id="bh2">', $html, 'unknown values are left out');
        self::assertStringContainsString('class="flo-section is-bg-sand has-bg-sand ', $html);
        self::assertStringNotContainsString('<style', $html, 'the CSS goes to blocks_assets()');
        $assets = blocks_assets();
        self::assertSame(1, preg_match('~<link rel="stylesheet" href="[^"]*/flo-admin/asset/block-styles-([a-f0-9]{24})\.css">~', $assets, $m), $assets);
        self::assertSame('.flo-block[data-block-id="bh1"]{color: red}.has-bg-sand,.flo-section.has-bg-sand{background-color:#f4ede1;color:var(--flo-dark,#101828)}', file_get_contents((string) BlockCss::path($m[1])));
        self::assertSame([], glob(Admin::root() . '/storage/cache/blocks/*.tmp') ?: [], 'written whole, then renamed');
        // From the cache, the same; blocks printed after blocks_assets() link their own CSS
        BlockRenderer::reset(strtotime('2026-10-22 12:00'));
        blocks_assets();
        self::assertStringStartsWith('<link rel="stylesheet" href="', blocks(json_encode($page)));
        // blocks_data(): style and colours without their "_", never the CSS
        $data = blocks_data(json_encode($page));
        self::assertSame(['style' => 'underlined', 'bg' => 'sand', 'text' => 'light'], array_intersect_key($data[0]['settings'], ['style' => 1, 'bg' => 1, 'text' => 1]));
        self::assertArrayNotHasKey('_css', $data[0]['settings']);
        self::assertSame([], array_intersect_key($data[1]['settings'], ['style' => 1, 'bg' => 1, 'text' => 1, '_style' => 1]), 'a style or colour the theme no longer has is left out, as on the page');
    }

    public function testSpacingDefaultsAreNamedAndSavedAsNothing(): void
    {
        $field = Builder::make('content');
        $heading = BlockRegistry::find('heading');
        $fields = $field->blockForm($heading)->fields();
        self::assertStringContainsString('<option value="">Default (Medium)</option>', $fields['space_above']->renderControl('x', 'x', ''));
        self::assertStringContainsString('<option value="">Default (None)</option>', $field->blockForm(BlockRegistry::find('slider'))->fields()['space_below']->renderControl('x', 'x', ''));
        self::assertStringContainsString('<option value="xlarge">Extra large</option>', $fields['space_above']->renderControl('x', 'x', ''));
        self::assertStringContainsString('<option value="">Same as on desktop</option>', $fields['space_above_phone']->renderControl('x', 'x', ''));
        $state = $field->stateFromInput(['content' => ['_' => ['bh' => ['type' => 'heading', 'f' => ['text' => 'Hi', 'space_above' => '', 'space_above_phone' => '', 'space_below' => 'large']]]]], self::LANGS);
        self::assertSame('[{"id":"bh","type":"heading","data":{"text":"Hi"},"settings":{"space_below":"large"}}]', $field->dehydrate($state));
        // A Section has no Background, Text or width of the shared ones; its own background uses the palette
        self::assertSame([], array_intersect(['_bg', '_text', 'width'], $field->settingNames(BlockRegistry::find('section'))));
    }

    public function testARemovedStyleIsShownAndGoesWhenSaved(): void
    {
        self::config(['block_styles' => ['heading' => ['underlined' => 'Underlined']]]);
        $field = Builder::make('content');
        $style = $field->blockForm(BlockRegistry::find('heading'))->fields()['_style'];
        self::assertStringContainsString('The style “glass” no longer exists.', $style->renderControl('x', 'x', 'glass'));
        self::assertStringNotContainsString('fa-choice-gone', $style->renderControl('x', 'x', 'underlined'));
        $state = $field->stateFromInput(['content' => ['_' => ['bh' => ['type' => 'heading', 'f' => ['text' => 'Hi', '_style' => 'glass', '_bg' => 'gone']], 'bk' => ['type' => 'heading', 'f' => ['text' => 'Ok', '_style' => 'underlined', '_bg' => 'dark']]]]], self::LANGS);
        $lists = $field->lists($state)['_'];
        self::assertSame('', $lists[0]['settings']['_style']);
        self::assertSame('', $lists[0]['settings']['_bg'], 'a colour the theme no longer has goes too');
        self::assertSame('dark', $lists[1]['settings']['_bg']);
        self::assertSame('underlined', $lists[1]['settings']['_style']);
        self::assertSame([], $field->validateBlocks($state, static fn (): array => []));
    }

    public function testOnlyPeopleWithThePermissionWriteCss(): void
    {
        $post = static fn (string $id, array $f): array => ['content' => ['_' => [$id => ['type' => 'heading', 'f' => ['text' => 'Hi'] + $f]]]];
        // Allowed: a code editor, checked when saved
        self::role(true);
        $field = Builder::make('content');
        self::assertInstanceOf(\FloCMS\Admin\Form\Fields\CodeEditor::class, $field->blockForm(BlockRegistry::find('heading'))->fields()['_css']);
        $state = $field->stateFromInput($post('bh', ['_css' => "& { color: red }\n& { background: url(x) }"]), self::LANGS);
        $check = static fn (\FloCMS\Admin\Form\Field $f, $value, string $key): array => [];
        self::assertSame(['content._.bh._css' => 'Line 2: “url(” isn’t allowed in custom CSS.'], $field->validateBlocks($state, $check));
        $state = $field->stateFromInput($post('bh', ['_css' => '& { color: red }']), self::LANGS);
        self::assertSame([], $field->validateBlocks($state, $check));
        self::assertStringContainsString('"_css":"& { color: red }"', (string) $field->dehydrate($state));

        // Not allowed: hidden, and the stored CSS is kept as signed for this block only
        self::role(false);
        $field = Builder::make('content');
        $fields = $field->blockForm(BlockRegistry::find('heading'))->fields();
        self::assertInstanceOf(\FloCMS\Admin\Form\Fields\Hidden::class, $fields['_css']);
        $signed = BlockSettings::prepareState(['_css' => '& { color: red }'], '_|bh')['_css_signed'];
        $kept = $field->stateFromInput($post('bh', ['_css' => '& { color: blue }', '_css_signed' => $signed]), self::LANGS);
        self::assertSame('& { color: red }', $field->lists($kept)['_'][0]['settings']['_css']);
        self::assertStringNotContainsString('_css_signed', (string) $field->dehydrate($kept));
        // No copy (a new block): no CSS
        self::assertSame('', $field->lists($field->stateFromInput($post('bh', ['_css' => '& { color: blue }']), self::LANGS))['_'][0]['settings']['_css']);
        // A copy that doesn't match (forged, another block's, an old tab): not saved, the page asks for a reload
        foreach ([$post('bh', ['_css' => 'x', '_css_signed' => 'x|' . base64_encode('x')]), $post('bnew', ['_css_signed' => $signed])] as $input) {
            $stale = $field->stateFromInput($input, self::LANGS);
            self::assertSame([], $field->lists($stale)['_']);
            self::assertStringContainsString('out of date', $field->validateBlocks($stale, static fn (): array => [])['content']);
        }
        // Their saves aren't checked against the CSS rules (it is the stored copy)
        self::assertSame([], $field->validateBlocks($kept, static fn (): array => []));
    }

    public function testTheDisplayTabIsGroupedAndTheRoleEditorListsThePermissions(): void
    {
        self::role(true);
        self::config(['block_styles' => ['heading' => ['underlined' => 'Underlined']], 'pages' => true]);
        $field = Builder::make('content');
        $html = (new \FloCMS\Admin\Form\FormRenderer([], [], self::LANGS, 'en'))->render($field->contentForm(BlockRegistry::find('heading')));
        foreach (['Look', 'Layout', 'Advanced'] as $group) { self::assertStringContainsString('>' . $group . '</h3>', $html); }
        self::assertStringContainsString('class="fa-form-section fa-span-full is-group"', $html);
        self::assertStringContainsString('data-swatch="#0b6e91"', $html);
        self::assertStringContainsString('data-language="css"', $html);
        $names = array_keys(\FloCMS\Admin\Roles\PermissionCatalogue::names());
        self::assertContains(BlockCss::PERMISSION, $names);
        self::assertContains(BlocksPreviewController::PERMISSION, $names);
    }

    public function testThePreviewShowsEveryKindInItsStylesAndColours(): void
    {
        self::config(['block_styles' => ['heading' => ['underlined' => 'Underlined']]]);
        $nodes = BlocksPreviewController::nodes(['heading' => BlockRegistry::find('heading'), 'section' => BlockRegistry::find('section'), 'image' => BlockRegistry::find('image')]);
        $labels = array_map(static fn (array $node): string => strip_tags((string) $node['data']['html']), array_values(array_filter($nodes, static fn (array $node): bool => $node['type'] === 'html')));
        self::assertSame(['Heading · Default .flo-block-heading', 'Heading · Underlined .flo-block-heading.is-style-underlined', 'Heading · Brand colour .flo-block-heading.has-bg-brand',
            'Heading · Light .flo-block-heading.has-bg-light', 'Heading · Dark .flo-block-heading.has-bg-dark', 'Heading · Muted .flo-block-heading.has-bg-muted',
            'Section · Default .flo-block-section', 'Image .flo-block-image No sample yet: the block’s class can return one from sample().'], $labels);
        $section = array_values(array_filter($nodes, static fn (array $node): bool => $node['type'] === 'section'))[0];
        self::assertSame(['heading', 'rich_text'], array_column($section['children'], 'type'));
        self::assertCount(8, array_filter($nodes, static fn (array $node): bool => $node['type'] === 'html'));
        $html = blocks(json_encode($nodes));
        self::assertStringContainsString('is-style-underlined', $html);
        self::assertStringContainsString('data-flo-preview-label', $html);
        self::assertSame(BlocksPreviewController::nodes(['heading' => BlockRegistry::find('heading')], false)[1]['settings'], []);
    }
}
