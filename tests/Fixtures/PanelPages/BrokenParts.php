<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\PanelPages;

use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Table\Table;

/** Every part throws: the page still opens and says which parts could not be shown. */
final class BrokenParts extends AdminPage
{
    public function filters(Form $form): Form { throw new \RuntimeException('filters broke'); }
    public function table(Table $table): Table { throw new \RuntimeException('table broke'); }
    public function form(Form $form): Form { throw new \RuntimeException('form broke'); }
    public function submit(array $data): void {}
    public function view(): ?string { return 'admin/sales-report'; }
    public function data(array $filters): array { return ['heading' => 'Still here', 'status' => 'ok']; }
}
