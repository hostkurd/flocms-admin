<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;
final class CreateAction extends Action
{
    protected string $kind = 'create';
    public static function make(string $name = 'create'): static { return parent::make($name)->color('primary')->icon('plus'); }
}
