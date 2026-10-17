<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Reorder;

use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Filters\SelectFilter;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Database;

/** A reorderable list: archived rows are outside the scope, each category can be ordered on its own. */
class FaqResource extends Resource
{
    protected static string $table = 'faqs';
    protected static string $slug = 'faqs';
    protected static bool $timestamps = true;
    protected static string $label = 'FAQ';
    protected static string $pluralLabel = 'FAQs';
    protected static ?string $icon = 'message';
    protected static string $formMode = 'modal';

    public function query(Database $query): Database { return $query->where('archived', '=', 0); }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('question')->translatable()->required(),
            Select::make('category')->options(['billing' => 'Billing', 'shipping' => 'Shipping'])->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')->searchable()->sortable(),
                TextColumn::make('category')->sortable(),
            ])
            ->filters([SelectFilter::make('category')->options(['billing' => 'Billing', 'shipping' => 'Shipping'])])
            ->reorderable('sort');
    }

    /** Leaves a trace in faq_events so request tests can see the hook ran. */
    public function afterReorder(array $ids): void
    {
        $db = \FloCMS\Core\App::db();
        if ($db !== null && $db->driver() !== '' && $this->hasEvents($db)) {
            $db->table('faq_events')->insert(['ids' => implode(',', $ids)]);
        }
    }

    private function hasEvents(Database $db): bool
    {
        try { $db->query('SELECT 1 FROM faq_events WHERE 1 = 0'); return true; } catch (\PDOException) { return false; }
    }
}
