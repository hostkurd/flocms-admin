<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Resources;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{Date, RichEditor, Select, Slug, Textarea, TextInput, Toggle};
use FloCMS\Admin\Form\Layout\Section;
use FloCMS\Admin\Table\{Table, Columns\TextColumn, Columns\BadgeColumn};

/** A complex record on the default full page: main fields, a sidebar and a folded SEO section. */
final class ProjectResource extends Resource
{
    protected static string $table = 'projects';
    protected static string $slug = 'projects';
    protected static bool $timestamps = true;
    protected static ?string $icon = 'briefcase';
    protected static string $label = 'Project';
    protected static string $pluralLabel = 'Projects';
    protected static ?string $description = 'Projects with a schedule, publishing and SEO.';

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('project')->label('Project')->description('Name, summary and the full description.')->schema([
                TextInput::make('name')->translatable()->required()->rules('max:190'),
                Slug::make('slug')->from('name')->unique()->rules('required|max:190'),
                Textarea::make('summary')->rows(3)->nullable()->columnSpan('full'),
                RichEditor::make('detail')->translatable()->nullable(),
            ]),
            Section::make('seo')->label('SEO')->collapsed()->schema([
                TextInput::make('meta_title')->nullable()->rules('max:70')->columnSpan('full'),
            ]),
        ])->aside([
            Section::make('publishing')->label('Publishing')->columns(1)->schema([
                Select::make('status')->options(['planned' => 'Planned', 'active' => 'Active', 'done' => 'Done'])->default('planned')->required(),
                Toggle::make('featured'),
            ]),
            Section::make('timeline')->label('Timeline')->columns(1)->schema([
                Date::make('starts_on')->label('Start date')->nullable(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            BadgeColumn::make('status'),
        ])->defaultSort('id', 'desc')->perPage(5);
    }
}
