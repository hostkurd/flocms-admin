<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormRenderer;
use FloCMS\Admin\Tests\TestCase;

final class FormRendererTest extends TestCase
{
    private function render(array $state, array $errors = []): string
    {
        $form = (new Form())->schema([
            TextInput::make('title')->translatable()->required(),
            Textarea::make('body')->translatable(),
            TextInput::make('code'),
        ]);

        return (new FormRenderer($state, $errors, ['en', 'ar', 'ku'], 'en'))->render($form);
    }

    public function testTranslatableFieldShowsDefaultInputBadgeAndDialog(): void
    {
        $html = $this->render(['title' => ['en' => 'Villa', 'ar' => 'فيلا', 'ku' => ''], 'body' => [], 'code' => 'X1']);

        // Visible default-language input, submitted as title[en]
        self::assertStringContainsString('id="f-title" name="title[en]"', $html);
        self::assertMatchesRegularExpression('/name="title\[en\]"[^>]*dir="ltr" lang="en"[^>]*value="Villa"/', $html);
        // Mirror in the dialog has no name (it is copied back on Save)
        self::assertMatchesRegularExpression('/<input id="f-title-en"[^>]*data-mirror="f-title"/', $html);
        self::assertDoesNotMatchRegularExpression('/id="f-title-en" name=/', $html);
        // Other languages, with their direction
        self::assertMatchesRegularExpression('/name="title\[ar\]"[^>]*dir="rtl" lang="ar"[^>]*value="فيلا"/', $html);
        self::assertMatchesRegularExpression('/name="title\[ku\]"[^>]*dir="rtl" lang="ku"/', $html);
        // Badge: default, filled and empty languages
        self::assertStringContainsString('<span class="fa-code is-default">en</span><span class="fa-code is-filled">ar</span><span class="fa-code is-empty">ku</span>', $html);
        self::assertStringContainsString('title="Missing: ku"', $html);
        self::assertStringContainsString('<dialog class="fa-lang-dialog" id="d-title"', $html);
        self::assertStringContainsString('default language', $html);
        // Long fields get the wide dialog
        self::assertStringContainsString('<dialog class="fa-lang-dialog is-wide" id="d-body"', $html);
        // Plain field
        self::assertMatchesRegularExpression('/<input id="f-code" name="code"[^>]*value="X1"/', $html);
        self::assertStringContainsString('<span class="fa-required" aria-hidden="true">*</span>', $html);
    }

    public function testErrorsOnAnotherLanguageMarkTheBadgeAndOpenTheDialog(): void
    {
        $html = $this->render(['title' => ['en' => 'Villa', 'ar' => str_repeat('x', 30)], 'body' => [], 'code' => ''], ['title.ar' => 'Too long']);

        self::assertStringContainsString('fa-lang-badge is-invalid', $html);
        self::assertStringContainsString('data-open-on-load="1"', $html);
        self::assertStringContainsString('<span class="fa-code is-filled is-invalid">ar</span>', $html);
        self::assertStringContainsString('<p class="fa-error" role="alert">Too long</p>', $html);
    }

    public function testDefaultLanguageErrorShowsUnderTheField(): void
    {
        $html = $this->render(['title' => [], 'body' => [], 'code' => ''], ['title' => 'Title is required.']);

        self::assertStringContainsString('fa-field has-error', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringNotContainsString('data-open-on-load', $html);
    }

    public function testTranslationDialogPutsSaveLastAndLeadsRowsWithTheLanguageCode(): void
    {
        $form = (new \FloCMS\Admin\Form\Form())->schema([\FloCMS\Admin\Form\Fields\TextInput::make('title')->translatable()]);
        $html = (new FormRenderer(['title' => ['en' => 'Villa']], [], ['en', 'ar'], 'en'))->render($form);
        self::assertMatchesRegularExpression('~data-dialog-cancel>.*?</button><button type="button" class="fa-btn fa-btn-primary fa-btn-sm" data-dialog-save>~s', $html);
        self::assertStringContainsString('<code class="fa-lang-code is-default">en</code> <span class="fa-lang-name">', $html);
        self::assertStringContainsString('<code class="fa-lang-code">ar</code> <span class="fa-lang-name">', $html);
    }
}
