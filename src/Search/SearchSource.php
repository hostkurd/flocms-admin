<?php
declare(strict_types=1);

namespace FloCMS\Admin\Search;

/**
 * A site's own group in Ctrl+K search (0.20), listed in config/admin.php:
 * 'search' => ['sources' => [App\Admin\Search\HelpArticles::class]].
 *
 * A source returns only what the current person may open: the panel checks
 * nothing more for it. It is created once per search request.
 */
interface SearchSource
{
    /** The group's heading ("Help"). */
    public function label(): string;

    /**
     * Up to $limit results for $query (never empty, at most 100 characters).
     *
     * @return list<SearchResult>
     */
    public function search(string $query, int $limit): array;
}
