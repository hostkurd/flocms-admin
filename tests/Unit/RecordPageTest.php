<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Actions\{Action, CreateAction, DeleteAction, EditAction};
use FloCMS\Admin\Form\{Form, FormRenderer};
use FloCMS\Admin\Form\Fields\{Select, TextInput, Toggle};
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Tests\Fixtures\Resources\{NewsResource, PostResource, ProjectResource, TagResource};
use FloCMS\Admin\Tests\TestCase;

/** 0.12: full-page forms by default, modal and slide-over on request, a form sidebar and folding sections. */
final class RecordPageTest extends TestCase
{
    public function testCreateAndEditOpenAsAPageUnlessTheResourceOrActionSaysOtherwise(): void
    {
        self::assertSame('page', NewsResource::getFormMode(), 'the default');
        self::assertSame('modal', PostResource::getFormMode());
        self::assertSame('slide-over', TagResource::getFormMode());
        self::assertSame('page', CreateAction::make()->getMode(new ProjectResource()));
        self::assertSame('page', EditAction::make()->getMode(new ProjectResource()));
        self::assertSame('modal', CreateAction::make()->modal()->getMode(new ProjectResource()));
        self::assertSame('slide-over', EditAction::make()->slideOver()->getMode(new ProjectResource()));
        self::assertSame('page', EditAction::make()->page()->getMode(new PostResource()));
        // Confirmations and custom actions stay dialogs; a custom action may slide over.
        self::assertSame('modal', DeleteAction::make()->getMode(new ProjectResource()));
        self::assertSame('modal', Action::make('publish')->getMode(new ProjectResource()));
        self::assertSame('slide-over', Action::make('publish')->slideOver()->getMode(new ProjectResource()));
        $this->expectException(\LogicException::class);
        Action::make('publish')->page();
    }

    public function testAnUnknownFormModeFallsBackToAPage(): void
    {
        $class = new class extends \FloCMS\Admin\Resource {
            protected static string $table = 'drafts'; protected static string $slug = 'drafts'; protected static string $formMode = 'drawer';
            public function form(Form $form): Form { return $form; }
            public function table(\FloCMS\Admin\Table\Table $table): \FloCMS\Admin\Table\Table { return $table; }
        };
        self::assertSame('page', $class::getFormMode());
        self::assertSame('drawer', $class::configuredFormMode());
        self::assertSame([], $class::configurationErrors(), 'a typo does not unload the resource; admin:doctor reports it');
    }

    public function testAsideFieldsJoinTheFormAndRenderAsASidebar(): void
    {
        $form = (new Form())->schema([TextInput::make('title')])->aside([
            Section::make('publishing')->schema([Select::make('status')->options(['draft' => 'Draft']), Toggle::make('featured')]),
        ]);
        self::assertSame(['title', 'status', 'featured'], array_keys($form->fields()));
        self::assertCount(1, $form->asideComponents());
        $html = (new FormRenderer(['title' => '', 'status' => 'draft', 'featured' => '0'], [], ['en'], 'en'))->render($form);
        self::assertMatchesRegularExpression('~^<div class="fa-form-layout"><div class="fa-form-main"><div class="fa-form-grid fa-grid-2">.*name="title".*</div></div><div class="fa-form-aside"><div class="fa-form-grid fa-grid-1">.*name="status"~s', $html);
        $this->expectException(\InvalidArgumentException::class);
        (new Form())->schema([TextInput::make('title')])->aside([TextInput::make('title')]);
    }

    public function testAFormWithoutAsideKeepsItsMarkup(): void
    {
        $html = (new FormRenderer(['title' => ''], [], ['en'], 'en'))->render((new Form())->schema([TextInput::make('title')]));
        self::assertStringStartsWith('<div class="fa-form-grid fa-grid-2">', $html);
        self::assertStringNotContainsString('fa-form-layout', $html);
    }

    public function testCollapsibleSectionsAreDetailsAndOpenWhenTheyHoldAnError(): void
    {
        $form = (new Form())->schema([
            Section::make('seo')->label('SEO')->description('Search engines')->collapsed()->schema([TextInput::make('meta_title')]),
            Section::make('extra')->collapsible()->schema([TextInput::make('note')]),
        ]);
        $closed = (new FormRenderer(['meta_title' => '', 'note' => ''], [], ['en'], 'en'))->render($form);
        self::assertStringContainsString('<details class="fa-form-section fa-span-full is-collapsible" id="layout-0-seo"><summary class="fa-section-summary"><span class="fa-section-heading"><span class="fa-section-title" id="layout-0-seo-title">SEO</span><p class="fa-help">Search engines</p></span>', $closed);
        self::assertStringContainsString('id="layout-1-extra" open>', $closed, 'collapsible but open by default');
        $error = (new FormRenderer(['meta_title' => '', 'note' => ''], ['meta_title' => 'Too long.'], ['en'], 'en'))->render($form);
        self::assertStringContainsString('id="layout-0-seo" open>', $error);
    }

    public function testContentWidthIsFullUnlessBoxed(): void
    {
        self::assertSame('full', Admin::config()->contentWidth());
        Admin::useConfig(['content_width' => 'boxed']);
        self::assertSame('boxed', Admin::config()->contentWidth());
        foreach (['wide', '', null, 1] as $invalid) {
            Admin::useConfig(['content_width' => $invalid]);
            self::assertSame('full', Admin::config()->contentWidth());
        }
    }
}
