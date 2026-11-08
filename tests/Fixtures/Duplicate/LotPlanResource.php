<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Duplicate;

use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Table;

/** A lot's plans, managed on the lot's page only. */
final class LotPlanResource extends Resource
{
    protected static string $table = 'lot_plans';
    protected static string $slug = 'lot-plans';
    protected static string $label = 'Plan';
    protected static bool $navigation = false;
    protected static bool $timestamps = false;

    public function form(Form $form): Form { return $form->schema([TextInput::make('name')->required()]); }
    public function table(Table $table): Table { return $table->columns([TextColumn::make('name')]); }
}
