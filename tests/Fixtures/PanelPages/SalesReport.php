<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\PanelPages;

use FloCMS\Admin\Actions\Action;
use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Database;

/** A report: filters, a widget, a view, a table, header actions, a route and a badge. */
final class SalesReport extends AdminPage
{
    protected static string $label = 'Sales report';
    protected static ?string $description = 'Bookings and income by month';
    protected static ?string $icon = 'chart';
    protected static ?string $group = 'Reports';
    protected static int $sort = 10;
    protected static array $routes = ['feed' => 'feed', 'year' => 'byYear'];

    public function filters(Form $form): Form
    {
        return $form->schema([
            Select::make('status')->options(['published' => 'Published', 'draft' => 'Draft'])->default($_SESSION['status_default'] ?? ''),
            TextInput::make('min_views')->numeric(),
        ]);
    }

    public function widgets(): array
    {
        return [PageStats::class];
    }

    public function view(): ?string
    {
        return 'admin/sales-report';
    }

    public function data(array $filters): array
    {
        return ['heading' => 'Monthly sales', 'status' => $filters['status'] ?? 'all'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static fn (Database $db, array $filters): Database => isset($filters['status']) ? $db->table('posts')->where('status', '=', $filters['status']) : $db->table('posts'))
            ->columns([
                TextColumn::make('slug')->searchable()->sortable(),
                TextColumn::make('status')->sortable()->toggleable()->summarize([\FloCMS\Admin\Table\Summaries\Count::make()->label('Rows')]),
                TextColumn::make('title')->translatable(),
            ])
            ->url(static fn (object $row): string => '/en/admin/posts/edit/' . $row->id)
            ->defaultSort('id', 'asc');
    }

    public function actions(): array
    {
        return [
            Action::make('send')->label('Email this report')->color('primary')
                ->form(static fn (Form $form): Form => $form->schema([TextInput::make('email')->email()->required()]))
                ->action(static function (array $data, AdminPage $page): void { $_SESSION['report_sent'] = $data['email'] . ' ' . $page::getSlug(); }),
            Action::make('recount')->requiresConfirmation(false)
                ->action(static function (): string { $_SESSION['recounted'] = ($_SESSION['recounted'] ?? 0) + 1; return 'Recounted.'; }),
            Action::make('secret')->permission('reports.secret')->action(static function (): void { $_SESSION['secret_ran'] = true; }),
        ];
    }

    public function badge(): string|int|null
    {
        return 3;
    }

    public function byYear(int $year): array
    {
        return ['year' => $year];
    }

    public function feed(): array
    {
        return ['ok' => true, 'filters' => $this->filterValues()];
    }
}
