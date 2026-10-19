<?php
declare(strict_types=1);

namespace FloCMS\Admin\Search;

use FloCMS\Admin\Admin;
use FloCMS\Core\Auth;
use FloCMS\Core\Session;

/**
 * The last records a person opened (0.20), shown in Ctrl+K before typing. Kept in
 * the session, so they go at sign-out and nothing is stored in the browser.
 *
 * @internal
 */
final class RecentRecords
{
    public const LIMIT = 8;
    private const KEY = '_flo_admin_recent';

    /** Note that the edit page of a record was opened. */
    public static function remember(string $resource, string $id, string $title): void
    {
        if (!isset($_SESSION) || $id === '' || strlen($id) > 191) { return; }
        $list = array_values(array_filter(self::stored(), static fn (array $item): bool => !($item['r'] === $resource && $item['id'] === $id)));
        array_unshift($list, ['r' => $resource, 'id' => $id, 't' => SearchResult::clean($title, 200)]);
        Session::set(self::KEY, array_slice($list, 0, self::LIMIT));
    }

    /** Forget a record (it was deleted). */
    public static function forget(string $resource, string $id): void
    {
        if (!isset($_SESSION)) { return; }
        $list = self::stored();
        $kept = array_values(array_filter($list, static fn (array $item): bool => !($item['r'] === $resource && $item['id'] === $id)));
        if (count($kept) !== count($list)) { Session::set(self::KEY, $kept); }
    }

    /**
     * The remembered records the person may still open, newest first: read again
     * through each resource's query() (one query per resource), so a record now
     * outside it, deleted or no longer open to them is left out, with today's title.
     *
     * @return list<SearchResult>
     */
    public static function results(): array
    {
        $stored = self::stored();
        $db = \FloCMS\Core\App::db();
        if ($stored === [] || $db === null) { return []; }
        $fresh = [];
        foreach (array_unique(array_column($stored, 'r')) as $slug) {
            $class = Admin::registry()->find($slug);
            if ($class === null || (!Auth::can($class::permission('update')) && !($class::hasViewPage() && Auth::can($class::permission('view'))))) { continue; }
            $ids = array_values(array_map(static fn (array $item): string => $item['id'], array_filter($stored, static fn (array $item): bool => $item['r'] === $slug)));
            try {
                /** @var \FloCMS\Admin\Resource $resource */
                $resource = new $class();
                $primary = $resource->table(new \FloCMS\Admin\Table\Table())->primaryColumn();
                $repository = new \FloCMS\Admin\Data\RecordRepository($resource, $db, \FloCMS\Admin\Translation\Translations::store());
                foreach ($repository->newQuery()->whereIn($class::getPrimaryKey(), $ids)->get() as $row) {
                    $id = (string) ($row->{$class::getPrimaryKey()} ?? '');
                    // Edit or the view page, as the list's row link (0.27).
                    $url = $id === '' ? null : $resource->recordUrl($row);
                    if ($url === null) { continue; }
                    $fresh[$slug . "\0" . $id] = SearchResult::make(GlobalSearch::title($row, $primary, $id), $url)
                        ->type($class::getLabel())->icon($class::getIcon());
                }
            } catch (\Throwable $error) {
                error_log('flocms-admin: recent records of ' . $slug . ' failed: ' . $error->getMessage());
            }
        }
        $results = [];
        foreach ($stored as $item) {
            if (isset($fresh[$item['r'] . "\0" . $item['id']])) { $results[] = $fresh[$item['r'] . "\0" . $item['id']]; }
        }
        return $results;
    }

    /** @return list<array{r: string, id: string, t: string}> */
    private static function stored(): array
    {
        $list = isset($_SESSION) ? Session::get(self::KEY) : null;
        if (!is_array($list)) { return []; }
        $clean = [];
        foreach ($list as $item) {
            if (is_array($item) && is_string($item['r'] ?? null) && is_string($item['id'] ?? null) && is_string($item['t'] ?? null)) {
                $clean[] = ['r' => $item['r'], 'id' => $item['id'], 't' => $item['t']];
            }
        }
        return array_slice($clean, 0, self::LIMIT);
    }
}
