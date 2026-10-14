<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;
final class EditAction extends Action
{
    protected string $kind = 'edit';
    public static function make(string $name = 'edit'): static { return parent::make($name); }
}
