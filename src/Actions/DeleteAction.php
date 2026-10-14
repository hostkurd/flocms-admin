<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;
final class DeleteAction extends Action
{
    protected string $kind = 'delete';
    public static function make(string $name = 'delete'): static { return parent::make($name)->color('danger'); }
}
