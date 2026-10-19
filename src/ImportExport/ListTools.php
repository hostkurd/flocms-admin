<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Table\FilterSet;

/**
 * The list page's Export menu and Import button (0.25), for the full page and
 * the Reactive list alike.
 *
 * @internal
 */
final class ListTools
{
    /**
     * The Export menu: the download form's address and hidden fields (the list's
     * search, filters and sort), the row count, the template link; null when the
     * user may not export.
     *
     * @return array{url: string, parent: string, params: list<array{0: string, 1: string}>, count: int, label: string, disabled: bool, scope: string, templateUrl: ?string, icon: string, fileIcon: string, listIcon: string}|null
     */
    public static function export(Resource $resource, FilterSet $filters, string $search, ?string $sort, ?string $dir, int $count): ?array
    {
        if (!$resource::canExport()) { return null; }
        $scope = $resource->parentScope();
        $params = [];
        foreach (['parent' => $scope?->token(), 'q' => $search, 'sort' => $sort, 'dir' => $sort !== null && $sort !== '' ? $dir : null] as $name => $value) {
            if ($value !== null && $value !== '') { $params[] = [$name, (string) $value]; }
        }
        foreach ($filters->active() as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $part => $item) { $params[] = ['filter[' . $name . '][' . $part . ']', (string) $item]; }
            } else {
                $params[] = ['filter[' . $name . ']', (string) $value];
            }
        }
        $limit = (new Exporter($resource, \FloCMS\Core\App::db() ?? throw new \RuntimeException('Database required.')))->limit();
        return [
            'url' => Admin::url($resource::getSlug() . '/export'),
            'parent' => (string) ($scope?->token() ?? ''),
            'params' => $params,
            'count' => $count,
            'label' => $count > $limit
                ? AdminLang::get('export.too_many', ['count' => number_format($count), 'max' => number_format($limit)])
                : AdminLang::get($count === 1 ? 'export.download_one' : 'export.download', ['count' => number_format($count), 'label' => $count === 1 ? $resource::getLabel() : $resource::getPluralLabel()]),
            'disabled' => $count > $limit || $count === 0,
            'scope' => AdminLang::get($filters->active() !== [] || $search !== '' ? 'export.scope_filtered' : 'export.scope_all'),
            'templateUrl' => $scope === null && self::canImport($resource) ? Admin::url($resource::getSlug() . '/export', ['template' => 1]) : null,
            'icon' => Icons::svg('download'),
            'fileIcon' => Icons::svg('file'),
            'listIcon' => Icons::svg('list'),
        ];
    }

    /** The Import button's address, or null. */
    public static function importUrl(Resource $resource): ?string
    {
        return $resource->parentScope() === null && self::canImport($resource) ? Admin::url($resource::getSlug() . '/import') : null;
    }

    public static function canImport(Resource $resource): bool
    {
        return $resource::canImport() && $resource->canCreate() && ImportStore::status() === 'ready';
    }
}
