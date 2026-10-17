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
     * The remembered records the person may still open, newest first.
     *
     * @return list<SearchResult>
     */
    public static function results(): array
    {
        $results = [];
        foreach (self::stored() as $item) {
            $class = Admin::registry()->find($item['r']);
            if ($class === null || !Auth::can($class::permission('update'))) { continue; }
            $results[] = SearchResult::make($item['t'] !== '' ? $item['t'] : '#' . $item['id'], Admin::url($item['r'] . '/edit/' . rawurlencode($item['id'])))
                ->type($class::getLabel())->icon($class::getIcon());
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
