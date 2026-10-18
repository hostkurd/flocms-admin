<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;

use FloCMS\Admin\{Resource, Table\Table};
use FloCMS\Core\HttpException;

/** Allowlist built from the resource's PHP definitions on every request. */
final class ActionRegistry
{
    private array $actions = [];
    public function __construct(Resource $resource, ?Table $table = null)
    {
        $table ??= $resource->table(new Table());
        $manager = $resource->parentScope()?->manager;
        foreach (['page' => $resource->headerActions(), 'table' => $table->getHeaderActions(), 'row' => $table->getActions(), 'bulk' => $table->getBulkActions()] as $placement => $definitions) {
            $this->actions[$placement] = self::index($definitions, $placement);
            // A read-only relation manager has no actions; canCreate(false) has no Create (0.22).
            if ($manager !== null) {
                $this->actions[$placement] = array_filter($this->actions[$placement], static fn (Action $action): bool =>
                    !$manager->isReadOnly() && ($action->getKind() !== 'create' || $manager->allowsCreate()));
            }
        }
    }
    public static function index(array $definitions, string $placement): array
    {
        $result = [];
        foreach ($definitions as $action) {
            if (!$action instanceof Action) { throw new \InvalidArgumentException('Actions must be Action objects.'); }
            if (($placement === 'bulk') !== ($action instanceof BulkAction)) { throw new \InvalidArgumentException('BulkAction objects belong only in Table::bulkActions().'); }
            $name = $action->getName();
            if (isset($result[$name])) { throw new \InvalidArgumentException('Duplicate action: ' . $placement . '/' . $name); }
            if (($action->getKind() === 'create' && $placement === 'row')
                || (in_array($action->getKind(), ['edit', 'delete'], true) && $placement !== 'row')) {
                throw new \InvalidArgumentException('Invalid placement for CRUD action ' . $name); }
            $result[$name] = $action;
        }
        return $result;
    }
    public function find(string $placement, string $name): Action
    {
        return $this->actions[$placement][$name] ?? throw new HttpException(404);
    }
    public function all(string $placement): array { return $this->actions[$placement] ?? []; }
}
