<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Fields\{RichEditor, TextInput};
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Form\LanguageSwitch;
use FloCMS\Admin\Table\Columns\{TextColumn, TranslationsColumn};
use FloCMS\Admin\Table\Filters\MissingTranslationFilter;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\Fixtures\Translations\{RoomResource, VillaResource};
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Translation\EditingLanguage;

/** Page-level translation (0.23): modes, the switch, the editing language, counts and the list column. */
final class TranslationModeUnitTest extends TestCase
{
    public function testTheModeComesFromTheResourceOrThePanel(): void
    {
        self::assertSame('field', Admin::config()->translationMode(), 'missing key: sites from before 0.23 keep badges');
        self::assertSame('field', PostResource::getTranslationMode());
        self::assertSame('page', VillaResource::getTranslationMode());
        self::assertTrue(VillaResource::usesLanguageSwitch());
        self::assertFalse(PostResource::usesLanguageSwitch());

        Admin::useConfig(['translation_mode' => 'page'], true, sys_get_temp_dir());
        self::assertSame('page', PostResource::getTranslationMode());
        Admin::useConfig(['translation_mode' => 'sideways'], true, sys_get_temp_dir());
        self::assertSame('field', Admin::config()->translationMode(), 'an unknown value falls back to field');

        self::languages(['en'], 'en');
        self::assertFalse(RoomResource::usesLanguageSwitch(), 'one language: no switch');
    }

    public function testTheEditingLanguage(): void
    {
        self::assertSame('ar', EditingLanguage::resolve('AR ', true));
        self::assertSame('en', EditingLanguage::resolve('xx', false));
        self::assertSame('en', EditingLanguage::resolve(['ar'], true));
        self::assertNull(EditingLanguage::valid(null));

        self::assertSame('ku', EditingLanguage::forErrors([], 'ku'));
        self::assertSame('ar', EditingLanguage::forErrors(['title.ar' => 'Too long.'], 'en'));
        self::assertSame('ar', EditingLanguage::forErrors(['title.ku' => 'x', 'title.ar' => 'y'], 'ar'), 'the language being edited keeps its place');
        self::assertSame('en', EditingLanguage::forErrors(['title.ku' => 'x', 'price' => 'Required.'], 'ar'), 'the default language comes first');
        self::assertSame('ku', EditingLanguage::forErrors(['_form' => 'Deleted meanwhile.'], 'ku'), 'form-level messages name no language');
    }

    public function testTheRendererCountsTranslatableFieldsPerLanguage(): void
    {
        $form = (new Form())->schema([
            TextInput::make('title')->translatable()->required(),
            RichEditor::make('body')->translatable(),
            TextInput::make('price'),
        ]);
        $state = ['title' => ['en' => 'Villa', 'ar' => 'فيلا', 'ku' => ' '], 'body' => ['en' => '<p>Text</p>', 'ar' => '<p></p>'], 'price' => '100'];
        $renderer = new FormRenderer($state, ['title.ku' => 'Too long.'], ['en', 'ar', 'ku'], 'en', languageSwitch: true, editing: 'ar', switchActive: true, sameNote: 'Same everywhere.');
        $html = $renderer->render($form);
        self::assertSame(['total' => 2, 'filled' => ['en' => 2, 'ar' => 1, 'ku' => 0], 'errors' => ['ku' => 1]], $renderer->languageStats());
        self::assertTrue($renderer->hasSharedFields());
        // Rendered for a Reactive dialog: the editing language's panes current, shared fields locked
        self::assertStringContainsString('class="fa-lang-pane is-current" data-lang="ar" data-input="f-title-ar"', $html);
        self::assertStringContainsString('class="fa-field is-shared is-locked" data-field="price" data-same-all', $html);
        self::assertMatchesRegularExpression('~<p class="fa-same-note is-line">.*?Same everywhere\.</span></p>~', $html);
        // The rich text panel is a language part, and its reference line shows (empty in Arabic)
        self::assertStringContainsString('data-lang="ar" dir="rtl" lang="ar" data-input="f-body-ar"', $html);
        self::assertStringContainsString('data-ref-input="f-body-ar" data-ref-source="f-body" data-ref-limit="200">', $html);
        self::assertStringContainsString('data-ref-input="f-title-ku" data-ref-source="f-title" hidden>', $html, 'not the language being edited');

        // Settings pages (no note given) keep their pill above the input
        $settings = (new FormRenderer($state, [], ['en', 'ar', 'ku'], 'en', languageSwitch: true))->render($form);
        self::assertStringContainsString('<p class="fa-same-note" hidden><span>All languages</span></p>', $settings);
        // Field mode is untouched
        $field = new FormRenderer($state, [], ['en', 'ar', 'ku'], 'en');
        self::assertStringContainsString('class="fa-lang-badge', $field->render($form));
        self::assertSame(0, $field->languageStats()['total']);
    }

    public function testTheSwitchIsButtonsUpToFourLanguagesThenASelect(): void
    {
        $stats = ['total' => 3, 'filled' => ['en' => 3, 'ar' => 1], 'errors' => ['ar' => 2]];
        $buttons = LanguageSwitch::render('sw', ['en', 'ar', 'ku'], 'en', 'ar', $stats);
        self::assertStringContainsString('data-lang-switch data-admin-untracked data-total="3"', $buttons);
        self::assertStringEndsWith('</div>', $buttons);
        self::assertMatchesRegularExpression('~<div class="fa-lang-switch" id="sw"[^>]* hidden>~', $buttons);
        self::assertStringContainsString('class="fa-ring is-partial"', $buttons);
        self::assertStringContainsString('stroke-dasharray="33 100"', $buttons);
        self::assertStringContainsString('<span class="fa-seg-err" data-lang-errors aria-hidden="true">2</span>', $buttons);
        self::assertStringContainsString('<span class="fa-sr" data-lang-status>, 1 of 3 filled. Errors: 2</span>', $buttons);
        self::assertMatchesRegularExpression('~<button type="button" class="fa-next-missing" data-next-missing>~', $buttons);
        self::assertStringContainsString('aria-pressed="true"><svg class="fa-ring is-partial"', $buttons);

        $active = LanguageSwitch::render('sw', ['en', 'ar'], 'en', 'en', $stats, true);
        self::assertDoesNotMatchRegularExpression('~id="sw"[^>]* hidden>~', $active);
        self::assertStringContainsString('data-next-missing hidden>', $active, 'nothing to find in the default language');

        $select = LanguageSwitch::render('sw', ['en', 'ar', 'ku', 'fa', 'tr'], 'en', 'ku', $stats, selectAttributes: ['data-hook' => '']);
        self::assertStringContainsString('<select class="fa-input fa-select fa-editing-select" data-lang-select aria-describedby="sw-help" data-hook="">', $select);
        self::assertStringContainsString('<option value="ku" lang="ku" data-name="کوردی" selected>کوردی · 0/3</option>', $select);
        self::assertStringContainsString('· 1/3 · Errors: 2</option>', $select);
        self::assertSame('', LanguageSwitch::render('sw', ['en'], 'en', 'en', $stats));
    }

    public function testTheTranslationsColumnCountsAndBringsItsFilter(): void
    {
        $form = (new Form())->schema([TextInput::make('title')->translatable(), RichEditor::make('body')->translatable(), TextInput::make('price')]);
        $table = (new Table())->columns([TextColumn::make('title'), TranslationsColumn::make('translations')])->prepare($form);
        $column = $table->column('translations');
        self::assertInstanceOf(TranslationsColumn::class, $column);
        self::assertSame(['title', 'body'], $column->countedFields());
        self::assertSame(['en' => 2, 'ar' => 1, 'ku' => 0], $column->counts((object) ['title' => '{"en":"A","ar":"ب"}', 'body' => '{"en":"<p>x</p>","ar":"<p> </p>"}']));
        self::assertSame(['en' => 1, 'ar' => 0, 'ku' => 0], $column->counts((object) ['title' => 'Plain legacy text', 'body' => null]));
        self::assertFalse($column->isSortable());
        self::assertTrue($table->column('title')->isTranslatable());
        $filter = $table->getFilters()['missing_in'] ?? null;
        self::assertInstanceOf(MissingTranslationFilter::class, $filter);
        self::assertSame(['title', 'body'], $filter->getFields());
        self::assertSame(['ar' => 'العربية', 'ku' => 'کوردی'], $filter->getOptions(), 'the default language is never missing');
        self::assertSame('Missing in', $filter->getLabel());

        // Preparing twice keeps one filter; ->withoutFilter() and explicit fields
        $table->prepare($form);
        self::assertCount(1, $table->getFilters());
        $plain = (new Table())->columns([TranslationsColumn::make('translations')->fields(['title'])->withoutFilter()])->prepare($form);
        self::assertSame([], $plain->getFilters());
        self::assertSame(['title'], $plain->column('translations')->countedFields());
        // A declared filter gets the form's fields
        $declared = (new Table())->columns([TextColumn::make('title')])->filters([MissingTranslationFilter::make('todo')->label('To translate')])->prepare($form);
        self::assertSame(['title', 'body'], $declared->getFilters()['todo']->getFields());
        self::assertSame('To translate', $declared->getFilters()['todo']->getLabel());

        self::languages(['en'], 'en');
        $single = (new Table())->columns([TranslationsColumn::make('translations')])->prepare($form);
        self::assertSame([], $single->getFilters(), 'one language: nothing can be missing');

        $this->expectException(\LogicException::class);
        TranslationsColumn::make('translations')->sortable();
    }
}
