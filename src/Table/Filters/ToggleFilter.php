<?php
declare(strict_types=1);
namespace FloCMS\Admin\Table\Filters;
final class ToggleFilter extends SelectFilter
{
    protected function setUp(): void { $this->options(['1' => \FloCMS\Admin\Support\AdminLang::get('filters.yes'), '0' => \FloCMS\Admin\Support\AdminLang::get('filters.no')]); }
}
