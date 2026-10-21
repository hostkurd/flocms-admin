<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Admin\Actions\Action;
use FloCMS\Admin\Actions\ActionContext;
use FloCMS\Admin\Actions\DeleteAction;
use FloCMS\Admin\Actions\EditAction;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Blocks\Core\Html;
use FloCMS\Admin\Form\Fields\BelongsTo;
use FloCMS\Admin\Form\Fields\Builder;
use FloCMS\Admin\Form\Fields\CodeEditor;
use FloCMS\Admin\Form\Fields\DateTime;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Placeholder;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Textarea;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Form\Layout\Tabs;
use FloCMS\Admin\Notifications\Notification;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Columns\BadgeColumn;
use FloCMS\Admin\Table\Columns\DateColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Columns\TranslationsColumn;
use FloCMS\Admin\Table\Filters\TabsFilter;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\App;
use FloCMS\Core\Auth;

/**
 * Site → Pages (0.30), at /admin/site-pages: the website's pages, each built
 * from blocks, written in HTML or in rich text, with its address, SEO,
 * publishing dates and template. On with 'pages' => true in config/admin.php.
 *
 * Permissions: content.site-pages.view/create/update/delete as for any
 * resource; pages.publish to publish and schedule (others save drafts);
 * blocks.html for HTML pages and HTML blocks.
 */
final class PagesResource extends Resource
{
    public const PUBLISH = 'pages.publish';
    public const SLUG = 'site-pages';

    protected static string $table = 'admin_pages';
    protected static string $slug = self::SLUG;
    protected static ?string $icon = 'file';
    protected static int $navigationSort = 900;
    protected static bool $timestamps = true;
    protected static bool $navigation = false;
    protected static string $rowOpens = 'edit';
    /** One language at a time for the whole page (the "Editing: language" switch), whatever the panel's mode. */
    protected static string $translationMode = 'page';

    public static function getTable(): string
    {
        return Admin::config()->pages()['table'];
    }

    public static function getLabel(): string { return AdminLang::get('pages.label'); }
    public static function getPluralLabel(): string { return AdminLang::get('pages.plural'); }

    public static function canPublish(): bool
    {
        return Auth::can(self::PUBLISH);
    }

    /**
     * Without pages.publish a page that visitors see (or the home page) is
     * read-only: changing it would publish the change. Drafts and scheduled
     * pages stay editable, and are saved as drafts.
     */
    public function canEdit(object $record): bool
    {
        return parent::canEdit($record) && (self::canPublish() || !self::isPublic($record));
    }

    public function canDelete(object $record): bool
    {
        return parent::canDelete($record) && (self::canPublish() || !self::isPublic($record));
    }

    private static bool $settled = false;

    /**
     * Scheduled pages whose time has come are stored as published, once per
     * request, so the tabs and the status filter agree with what visitors see.
     */
    public function query(\FloCMS\Core\Database $query): \FloCMS\Core\Database
    {
        if (!self::$settled) {
            self::$settled = true;
            try {
                App::db()->table(self::getTable())->where('status', '=', 'scheduled')->where('publish_at', '<=', date('Y-m-d H:i:s', Pages::now()))->update(['status' => 'published']);
            } catch (\Throwable $e) {
                error_log('flocms-admin: scheduled pages could not be published: ' . $e->getMessage());
            }
        }
        return parent::query($query);
    }

    private static function isPublic(object $record): bool
    {
        return (int) ($record->is_home ?? 0) === 1 || Pages::isLive($record);
    }

    /** A page brought back from the trash is not the home page any more, and is a draft unless a publisher restores it. */
    public function afterRestore(object $record): void
    {
        $data = ['is_home' => 0];
        if (!self::canPublish()) { $data['status'] = 'draft'; }
        App::db()->table(self::getTable())->where('id', '=', (int) $record->id)->update($data);
        Pages::reset();
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return ['draft' => AdminLang::get('pages.status_draft'), 'published' => AdminLang::get('pages.status_published'), 'scheduled' => AdminLang::get('pages.status_scheduled')];
    }

    /** @return array<string, string> the content types (HTML needs the HTML permission: validate() refuses it otherwise) */
    private static function types(): array
    {
        return ['builder' => AdminLang::get('pages.type_builder'), 'html' => AdminLang::get('pages.type_html'), 'rich_text' => AdminLang::get('pages.type_rich_text')];
    }

    public function form(Form $form): Form
    {
        $publish = self::canPublish();
        $html = Html::allowed();
        return $form->schema([
            Tabs::make('page')->schema([
                Tab::make('content')->label(AdminLang::get('pages.tab_content'))->columns(2)->schema([
                    TextInput::make('title')->label(AdminLang::get('pages.title'))->translatable()->required()->rules('max:190')->columnSpan('full'),
                    TextInput::make('slug')->label(AdminLang::get('pages.slug'))->translatable()->rules('max:190')->help(AdminLang::get('pages.slug_help'))->columnSpan('full'),
                    Select::make('type')->label(AdminLang::get('pages.type'))->options(self::types())
                        ->default('builder')->segmented()->required()->columnSpan('full'),
                    Builder::make('blocks')->label(AdminLang::get('pages.blocks'))->translatable()->maxBlocks(60)->shownWhen('type', 'builder'),
                    CodeEditor::make('html')->label(AdminLang::get('pages.html'))->translatable()->shownWhen('type', 'html')->disabled(!$html)
                        ->help(AdminLang::get($html ? 'pages.html_help' : 'pages.html_locked'))->columnSpan('full'),
                    RichEditor::make('body')->label(AdminLang::get('pages.body'))->translatable()->shownWhen('type', 'rich_text')->columnSpan('full'),
                ]),
                Tab::make('seo')->label(AdminLang::get('pages.tab_seo'))->columns(1)->schema([
                    TextInput::make('meta_title')->label(AdminLang::get('pages.meta_title'))->translatable()->maxLength(70)->help(AdminLang::get('pages.meta_title_help')),
                    Textarea::make('meta_description')->label(AdminLang::get('pages.meta_description'))->translatable()->rows(3)->rules('max:200')->help(AdminLang::get('pages.meta_description_help')),
                    MediaPicker::make('social_image')->label(AdminLang::get('pages.social_image'))->help(AdminLang::get('pages.social_image_help')),
                    Toggle::make('noindex')->label(AdminLang::get('pages.noindex'))->help(AdminLang::get('pages.noindex_help')),
                    Placeholder::make('seo_preview')->label(AdminLang::get('pages.seo_preview'))->html(fn (?object $record): string => PageEditor::seoPreview($record)),
                ]),
                Tab::make('settings')->label(AdminLang::get('pages.tab_settings'))->columns(2)->schema([
                    Select::make('template')->label(AdminLang::get('pages.template'))->options(fn (): array => Templates::all())->default(Templates::DEFAULT)->help(AdminLang::get('pages.template_help')),
                    BelongsTo::make('parent_id')->label(AdminLang::get('pages.parent'))->table(self::getTable(), 'title', 'id', true)->help(AdminLang::get('pages.parent_help')),
                    Toggle::make('menu')->label(AdminLang::get('pages.menu'))->help(AdminLang::get('pages.menu_help')),
                    TextInput::make('sort')->label(AdminLang::get('pages.sort'))->numeric()->rules('integer|min:-9999|max:9999')->default('0')->help(AdminLang::get('pages.sort_help')),
                    TextInput::make('css_class')->label(AdminLang::get('pages.css_class'))->rules('max:190')->help(AdminLang::get('pages.css_class_help'))->columnSpan('full'),
                ]),
            ]),
        ])->aside([
            Section::make('publish')->label(AdminLang::get('pages.section_publish'))->columns(1)->schema([
                Select::make('status')->label(AdminLang::get('pages.status'))->options(fn (): array => self::statuses())->default('draft')->disabled(!$publish)
                    ->help(AdminLang::get($publish ? 'pages.status_help' : 'pages.publish_locked')),
                DateTime::make('publish_at')->label(AdminLang::get('pages.publish_at'))->disabled(!$publish)->requiredWhen('status', 'scheduled')->help(AdminLang::get('pages.publish_at_help')),
                DateTime::make('unpublish_at')->label(AdminLang::get('pages.unpublish_at'))->disabled(!$publish),
                Placeholder::make('publish_buttons')->label(AdminLang::get('pages.section_publish'))->html(fn (?object $record): string => PageEditor::buttons($record)),
            ]),
            Section::make('page_card')->label(AdminLang::get('pages.section_page'))->columns(1)->schema([
                Placeholder::make('page_info')->label(AdminLang::get('pages.section_page'))->html(fn (?object $record): string => PageEditor::info($record)),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label(AdminLang::get('pages.title'))->searchable()->searchAlso(['slug'])->sortable()
                    ->description(static fn (object $row): string => rawurldecode(Pages::url($row, AdminLang::language())) . ((int) ($row->is_home ?? 0) === 1 ? ' · ' . AdminLang::get('pages.home') : '')),
                PageStatusColumn::make('status')->label(AdminLang::get('pages.status'))->labels(self::statuses())->dot()
                    ->colors(['draft' => 'gray', 'published' => 'green', 'scheduled' => 'blue']),
                TranslationsColumn::make('translations')->fields(['title']),
                DateColumn::make('updated_at')->label(AdminLang::get('pages.changed'))->sortable(),
            ])
            ->tabs(TabsFilter::make('status')->label(AdminLang::get('pages.status'))->options([
                'published' => AdminLang::get('pages.tab_published'), 'draft' => AdminLang::get('pages.tab_drafts'), 'scheduled' => AdminLang::get('pages.tab_scheduled'),
            ]))
            ->actions([
                EditAction::make(),
                Action::make('view_site')->label(AdminLang::get('pages.view_on_site'))->icon('external')->group('site')
                    ->disabled(static fn (?object $row): ?string => $row !== null && !Pages::isVisible($row) ? AdminLang::get('pages.reason_not_live') : null)
                    ->url(static fn (?object $row): string => $row === null ? '' : Pages::url($row, AdminLang::language())),
                Action::make('duplicate')->label(AdminLang::get('pages.duplicate'))->icon('copy')->permission(self::permission('create'))->group('page')
                    ->modalHeading(static fn (?object $row): string => AdminLang::get('pages.duplicate_title', ['title' => self::title($row)]))
                    ->modalDescription(AdminLang::get('pages.duplicate_text'))
                    ->action(static function (ActionContext $context): void {
                        $id = self::duplicate($context->record);
                        Notification::make()->title(AdminLang::get('pages.duplicated', ['title' => self::title($context->record)]))->success()->send();
                        unset($id);
                    }),
                Action::make('make_home')->label(AdminLang::get('pages.make_home'))->icon('home')->permission(self::PUBLISH)->group('page')
                    ->visible(static fn (?object $row): bool => Auth::can(self::permission('update')) && ($row === null || (new self())->canEdit($row)))
                    ->disabled(static fn (?object $row): ?string => $row === null ? null : ((int) ($row->is_home ?? 0) === 1 ? AdminLang::get('pages.reason_home') : (!Pages::isLive($row) ? AdminLang::get('pages.reason_not_live') : null)))
                    ->modalHeading(static fn (?object $row): string => AdminLang::get('pages.make_home_title', ['title' => self::title($row)]))
                    ->modalDescription(AdminLang::get('pages.make_home_text'))
                    ->action(static function (ActionContext $context): void {
                        // The live page only (a draft as the home page would take the site's away); one home page at a time.
                        if (!Pages::isLive($context->record) || !(new self())->canEdit($context->record)) { throw new \FloCMS\Core\ValidationException(['_form' => AdminLang::get('pages.reason_not_live')]); }
                        $id = (int) $context->record->id;
                        App::db()->transaction(static function (\FloCMS\Core\Database $db) use ($id): void {
                            $db->table(self::getTable())->where('is_home', '=', 1)->where('id', '!=', $id)->update(['is_home' => 0]);
                            $db->table(self::getTable())->where('id', '=', $id)->update(['is_home' => 1]);
                        });
                        Pages::reset();
                        Notification::make()->title(AdminLang::get('pages.made_home', ['title' => self::title($context->record)]))->success()->send();
                    }),
                DeleteAction::make(),
            ])
            // The editors' order (the menu's too): the Reorder button, and the list follows it.
            ->reorderable('sort');
    }

    public static function title(?object $row): string
    {
        return $row === null ? '' : Translations::get($row->title ?? '', AdminLang::language());
    }

    /** A copy of a page, as a draft with its own addresses ("about-copy"); returns its id. */
    public static function duplicate(object $row): int
    {
        $data = (array) $row;
        unset($data['id']);
        $titles = Translations::all($row->title ?? '');
        $slugs = Translations::all($row->slug ?? '');
        foreach ($titles as $lang => $title) { $titles[$lang] = AdminLang::get('pages.copy_of', ['title' => $title]); }
        foreach ($slugs as $lang => $slug) { $slugs[$lang] = self::freeSlug($slug . '-copy', $lang, (int) ($row->parent_id ?? 0), 0); }
        $data['title'] = json_encode($titles, JSON_UNESCAPED_UNICODE);
        $data['slug'] = json_encode($slugs, JSON_UNESCAPED_UNICODE);
        $data['status'] = 'draft';
        $data['is_home'] = 0;
        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');
        return App::$db->table(self::getTable())->insert($data);
    }

    /** $slug, or $slug-2, -3 … when another page under the same parent has it in that language. */
    private static function freeSlug(string $slug, string $lang, int $parent, int $except): string
    {
        Pages::reset();
        $taken = [];
        foreach (Pages::rows() as $other) {
            if ((int) $other->id === $except || (int) ($other->parent_id ?? 0) !== $parent) { continue; }
            $taken[mb_strtolower(Pages::slug($other, $lang))] = true;
        }
        $candidate = $slug;
        for ($n = 2; isset($taken[mb_strtolower($candidate)]) && $n < 1000; $n++) { $candidate = $slug . '-' . $n; }
        return $candidate;
    }

    /** Addresses filled from the title in every language that has one, and made address-safe. */
    public function normalizeState(array $state, ?object $record): array
    {
        $titles = is_array($state['title'] ?? null) ? $state['title'] : [];
        $slugs = is_array($state['slug'] ?? null) ? $state['slug'] : [];
        foreach (Languages::all() as $lang) {
            $slug = Str::slug((string) ($slugs[$lang] ?? ''));
            if ($slug === '' && trim((string) ($titles[$lang] ?? '')) !== '') { $slug = Str::slug((string) $titles[$lang]); }
            $slugs[$lang] = $slug;
        }
        $state['slug'] = $slugs;
        return $state;
    }

    /** @return array<string, string> */
    public function validate(array $state, ?object $record): array
    {
        $errors = [];
        $id = $record !== null ? (int) ($record->id ?? 0) : 0;
        $parent = (int) ($state['parent_id'] ?? 0);
        $slugs = is_array($state['slug'] ?? null) ? $state['slug'] : [];
        $isHome = $record !== null && (int) ($record->is_home ?? 0) === 1;
        Pages::reset();
        $rows = Pages::rows();
        $default = mb_strtolower(trim((string) ($slugs[Languages::default()] ?? '')));
        $titles = is_array($state['title'] ?? null) ? $state['title'] : [];
        foreach (Languages::all() as $lang) {
            $own = mb_strtolower(trim((string) ($slugs[$lang] ?? '')));
            $key = $lang === Languages::default() ? 'slug' : 'slug.' . $lang;
            if ($own === '' && $lang === Languages::default() && !$isHome) { $errors[$key] = AdminLang::get('pages.slug_required'); continue; }
            if ($isHome) { continue; }
            // The address the page answers at in this language: its own, else the default language's (Pages::slug()).
            $slug = $own !== '' ? $own : $default;
            $answers = trim((string) ($titles[$lang] ?? '')) !== '' || (Admin::config()->pages()['missing'] === 'default' && trim((string) ($titles[Languages::default()] ?? '')) !== '');
            if ($slug === '' || ($own === '' && !$answers)) { continue; }
            $base = $parent > 0 && isset($rows[$parent]) ? Pages::path($rows[$parent], $lang) : '';
            // A first segment must not be one the site or the panel already answers.
            if ($base === '' && ($taken = Pages::reservedSegment($slug)) !== null) {
                $errors[$own !== '' ? $key : 'slug'] ??= AdminLang::get('pages.slug_reserved', ['slug' => $slug, 'by' => $taken]);
                continue;
            }
            // No other page answers at the same address in this language.
            $path = ltrim($base . '/' . $slug, '/');
            foreach ($rows as $other) {
                if ((int) $other->id === $id || (int) ($other->is_home ?? 0) === 1 || !Pages::answersIn($other, $lang)) { continue; }
                if (mb_strtolower(Pages::path($other, $lang)) === $path) {
                    $errors[$key] = AdminLang::get('pages.slug_taken', ['title' => Translations::get($other->title ?? '', $lang) ?: Translations::get($other->title ?? '', Languages::default())]);
                    break;
                }
            }
        }
        // A page can't sit under itself or a page under it.
        $depth = 1;
        for ($at = $parent, $i = 0; $at > 0 && $i < Pages::MAX_DEPTH + 2; $i++) {
            if ($at === $id && $id > 0) { $errors['parent_id'] = AdminLang::get('pages.parent_loop'); break; }
            $depth++;
            $at = (int) ($rows[$at]->parent_id ?? 0);
        }
        // Addresses up to MAX_DEPTH levels (with the pages under this one).
        if (!isset($errors['parent_id']) && $depth + ($id > 0 ? self::levelsBelow($id, $rows) : 0) > Pages::MAX_DEPTH) {
            $errors['parent_id'] = AdminLang::get('pages.parent_too_deep', ['max' => (string) Pages::MAX_DEPTH]);
        }
        // HTML pages: only with the HTML permission (a page that is HTML already may keep it).
        if (($state['type'] ?? '') === 'html' && !Html::allowed() && (string) ($record->type ?? '') !== 'html') {
            $errors['type'] = AdminLang::get('pages.html_locked');
        }
        return $errors;
    }

    /** How many levels of pages sit under a page (0: none). @param array<int, object> $rows */
    private static function levelsBelow(int $id, array $rows, int $depth = 0): int
    {
        if ($depth > Pages::MAX_DEPTH) { return $depth; }
        $most = 0;
        foreach ($rows as $row) {
            if ((int) ($row->parent_id ?? 0) === $id && (int) $row->id !== $id) { $most = max($most, 1 + self::levelsBelow((int) $row->id, $rows, $depth + 1)); }
        }
        return $most;
    }

    /** Scheduled when the publishing time is still to come; published when it has come. */
    public function beforeSave(array $data, ?object $record): array
    {
        if (array_key_exists('status', $data)) {
            $from = Pages::time(is_string($data['publish_at'] ?? null) ? $data['publish_at'] : null);
            if ($data['status'] === 'published' && $from !== null && $from > Pages::now()) { $data['status'] = 'scheduled'; }
            elseif ($data['status'] === 'scheduled' && ($from === null || $from <= Pages::now())) { $data['status'] = 'published'; }
            elseif (!in_array($data['status'], Pages::STATUSES, true)) { $data['status'] = 'draft'; }
        }
        if (array_key_exists('parent_id', $data) && ((int) $data['parent_id'] <= 0)) { $data['parent_id'] = null; }
        if (array_key_exists('sort', $data)) { $data['sort'] = (int) ($data['sort'] ?? 0); }
        if (array_key_exists('template', $data) && Templates::name((string) $data['template']) === Templates::DEFAULT) { $data['template'] = null; }
        return $data;
    }

    public function afterSave(object $record): void
    {
        Pages::reset();
    }

    public function searchDetails(object $record): ?string
    {
        return rawurldecode(Pages::url($record, AdminLang::language()));
    }
}
