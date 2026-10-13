<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Correctness;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\Fields\{TextInput, Placeholder};
use FloCMS\Admin\Table\Table;
use FloCMS\Core\App;

final class CorrectnessResource extends Resource
{
    protected static string $table = 'posts';
    protected static string $slug = 'correctness';

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->translatable()->required()->unique(),
            TextInput::make('slug')->required(),
            Placeholder::make('stored')->content(fn (?object $r): string => 'Stored: ' . ($r?->slug ?? 'MISSING')),
        ]);
    }

    public function table(Table $table): Table { return $table; }

    public function beforeSave(array $data, ?object $record): array
    {
        if (str_starts_with($data['slug'], 'fail-') || $data['slug'] === 'hooks-ok') {
            App::db()->table('tags')->insert(['name' => 'before hook']);
        }
        if ($data['slug'] === 'fail-before') {
            throw new \RuntimeException('before hook failed');
        }
        return $data;
    }

    public function afterSave(object $record): void
    {
        if ($record->slug === 'fail-after' || $record->slug === 'hooks-ok') {
            App::db()->table('tags')->insert(['name' => 'after hook']);
        }
        if ($record->slug === 'fail-after') {
            throw new \RuntimeException('after hook failed');
        }
    }
}
