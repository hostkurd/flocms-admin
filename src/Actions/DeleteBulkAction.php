<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;
final class DeleteBulkAction extends BulkAction
{
    protected bool $deleting = true;
    public static function make(string $name = 'delete'): static { return parent::make($name)->label(\FloCMS\Admin\Support\AdminLang::get('bulk.delete'))->color('danger'); }
}
