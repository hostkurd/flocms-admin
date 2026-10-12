<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Resources;

use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Table;

final class BadSlugResource extends Resource
{
    protected static string $table = 'bad';
    protected static string $slug = 'Bad_Slug';

    public function form(Form $form): Form
    {
        return $form;
    }

    public function table(Table $table): Table
    {
        return $table;
    }
}
