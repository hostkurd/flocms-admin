<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Search;

use FloCMS\Admin\Search\SearchResult;
use FloCMS\Admin\Search\SearchSource;

/** A site's own group: one good result, one with a script URL that must be dropped. */
final class HelpSource implements SearchSource
{
    public function label(): string { return 'Help'; }

    public function search(string $query, int $limit): array
    {
        if (stripos('Upload a gallery', $query) === false) { return []; }
        return [
            SearchResult::make('Upload a gallery', '/en/admin/help/gallery')->detail('Media')->icon('image'),
            SearchResult::make('Evil', 'javascript:alert(1)'),
        ];
    }
}
