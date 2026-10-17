<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\Fixtures\Resources\TagResource;
use FloCMS\Admin\Tests\Fixtures\Search\HelpSource;
use FloCMS\Admin\Tests\Fixtures\Search\HiddenPostResource;
use FloCMS\Admin\Tests\Fixtures\Search\ReadOnlyPostResource;

/**
 * Ctrl+K search (0.20) through whole requests: the JSON the dialog reads, the page
 * without JavaScript, permissions, recent records and a site's own source.
 */
final class SearchRequestTest extends RequestTestCase
{
    private const JSON = ['headers' => ['Accept' => 'application/json']];

    /** @return array<string, mixed> */
    private static function search(string $query, array $options = []): array
    {
        $result = self::request('GET', '/admin/search?q=' . rawurlencode($query), $options + self::JSON);
        self::assertSame(200, $result['status'], $result['body']);
        $data = json_decode($result['body'], true);
        self::assertIsArray($data, $result['body']);
        return $data;
    }

    /** @return array<string, array<string, mixed>> label => group */
    private static function groups(array $data): array
    {
        return array_column($data['groups'], null, 'label');
    }

    /** @return list<string> */
    private static function titles(array $group): array
    {
        return array_column($group['items'], 'title');
    }

    public function testRecordsAreFoundByTheirSearchableColumnsInEveryLanguage(): void
    {
        $data = self::search('sea vi');
        $posts = self::groups($data)['Posts'];
        self::assertSame(['Sea view villa'], self::titles($posts));
        $item = $posts['items'][0];
        self::assertSame('https://example.test/en/admin/posts/edit/1', $item['url']);
        self::assertSame('sea-view-villa', $item['detail'], 'the next searchable column');
        self::assertSame('Post', $item['type']);
        self::assertStringContainsString('<svg', $item['icon']);
        self::assertSame('', $posts['more']);
        self::assertFalse($data['partial']);

        self::assertSame(['Sea view villa'], self::titles(self::groups(self::search('فيلا'))['Posts']), 'the Arabic title');
        self::assertSame(['Mountain cabin'], self::titles(self::groups(self::search('#2'))['Posts']), '#2 is the record with id 2');
    }

    public function testGroupsShowFiveAndLinkToTheListForMore(): void
    {
        $insert = self::pdo()->prepare("INSERT INTO posts (title, slug, status) VALUES (?, ?, 'draft')");
        foreach (range(1, 6) as $n) { $insert->execute(['{"en":"Seaside ' . $n . '"}', 'seaside-' . $n]); }

        $posts = self::groups(self::search('seaside'))['Posts'];
        self::assertCount(5, $posts['items']);
        self::assertSame(['Seaside 6', 'Seaside 5', 'Seaside 4', 'Seaside 3', 'Seaside 2'], self::titles($posts), 'newest first');
        self::assertSame('https://example.test/en/admin/posts?q=seaside', $posts['more']);

        // A title that starts with the words comes before a newer one that only contains them
        self::pdo()->exec("INSERT INTO posts (title, slug, status) VALUES ('{\"en\":\"Old seaside barn\"}', 'old-seaside-barn', 'draft')");
        $ranked = self::titles(self::groups(self::search('seaside'))['Posts']);
        self::assertSame('Seaside 6', $ranked[0]);
        self::assertNotContains('Old seaside barn', $ranked);

        $three = self::groups(self::search('seaside', ['admin_config' => ['search' => ['per_group' => 3]]]))['Posts'];
        self::assertCount(3, $three['items']);
    }

    public function testPagesAndActionsComeFirst(): void
    {
        $data = self::search('new');
        self::assertSame('Pages and actions', $data['groups'][0]['label']);
        self::assertContains('New Post', self::titles($data['groups'][0]));
        self::assertSame('https://example.test/en/admin/posts/create', $data['groups'][0]['items'][0]['url']);

        $super = self::search('user', ['session' => self::SUPER]);
        self::assertSame('Users', $super['groups'][0]['items'][0]['title']);
        self::assertSame('https://example.test/en/admin/accounts', $super['groups'][0]['items'][0]['url']);
        self::assertSame([], self::search('users')['groups'], 'editors do not have users.manage');
    }

    public function testOnlyWhatThePersonMayOpen(): void
    {
        $tagsOnly = [1 => ['taxonomy.tags.view', 'taxonomy.tags.update']];
        $data = self::search('ga', ['permissions' => $tagsOnly]);
        self::assertSame(['Post tags'], array_column($data['groups'], 'label'));
        self::assertSame(['Garden'], self::titles($data['groups'][0]));

        $config = ['admin_config' => ['resources' => [PostResource::class, TagResource::class, ReadOnlyPostResource::class, HiddenPostResource::class]]];
        $groups = self::groups(self::search('cabin', $config));
        self::assertArrayNotHasKey('Hidden posts', $groups, '$globallySearchable = false');
        $archive = $groups['Archive']['items'][0];
        self::assertSame('https://example.test/en/admin/archive?q=Mountain%20cabin', $archive['url'], 'not editable: the list, searched for it');
        self::assertSame('Status: draft', $archive['detail'], 'searchDetails()');
    }

    public function testRecentRecordsBeforeTyping(): void
    {
        $session = self::request('GET', '/admin/posts/edit/2')['session'];
        $session = self::request('GET', '/admin/posts/edit/1', ['session' => $session])['session'];
        $data = self::search('', ['session' => $session]);
        self::assertSame('Recent', $data['groups'][0]['label']);
        self::assertSame(['Sea view villa', 'Mountain cabin'], self::titles($data['groups'][0]), 'newest first');
        self::assertSame('Pages', $data['groups'][1]['label']);
        self::assertSame('Dashboard', $data['groups'][1]['items'][0]['title']);
        self::assertNotContains('New Post', self::titles($data['groups'][1]), 'actions only while typing');

        // A deleted record leaves the list; without the permission the rest go too
        $session = self::post('/admin/posts/delete/2', [], ['session' => $session])['session'];
        self::assertSame(['Sea view villa'], self::titles(self::search('', ['session' => $session])['groups'][0]));
        $data = self::search('', ['session' => $session, 'permissions' => [1 => ['taxonomy.tags.view']]]);
        self::assertNotSame('Recent', $data['groups'][0]['label'] ?? null);
    }

    public function testASitesOwnSourceAndUnsafeLinks(): void
    {
        $data = self::search('gallery', ['admin_config' => ['search' => ['sources' => [HelpSource::class, \stdClass::class]]]]);
        $help = self::groups($data)['Help'];
        self::assertSame(['Upload a gallery'], self::titles($help), 'a javascript: link is dropped');
        self::assertSame('Help', $help['items'][0]['type']);
        self::assertSame('/en/admin/help/gallery', $help['items'][0]['url']);
    }

    public function testThePageWithoutJavaScriptAndTheTopBar(): void
    {
        $page = self::request('GET', '/admin/search?q=cabin');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<a class="fa-search-item" href="https://example.test/en/admin/posts/edit/2">', $page['body']);
        self::assertStringContainsString('Mountain cabin', $page['body']);
        self::assertStringContainsString('<h1 class="fa-page-title">Search</h1>', $page['body']);

        $none = self::request('GET', '/admin/search?q=zzz');
        self::assertStringContainsString('No results for “zzz”', $none['body']);

        $list = self::request('GET', '/admin/posts')['body'];
        self::assertStringContainsString('<form class="fa-topsearch" method="get" action="https://example.test/en/admin/search"', $list);
        self::assertStringContainsString('id="fa-search-dialog"', $list);
        self::assertStringContainsString('aria-keyshortcuts="Control+K Meta+K"', $list);

        self::assertSame(405, self::post('/admin/search', ['q' => 'x'])['status']);
        self::assertSame(400, self::request('GET', '/admin/search?q=' . str_repeat('a', 401))['status']);
    }

    public function testSearchCanBeTurnedOff(): void
    {
        $off = ['admin_config' => ['search' => false]];
        self::assertSame(404, self::request('GET', '/admin/search?q=sea', $off + self::JSON)['status']);
        $list = self::request('GET', '/admin/posts', $off)['body'];
        self::assertStringNotContainsString('fa-topsearch', $list);
        self::assertStringNotContainsString('fa-search-dialog', $list);

        $noKeys = self::request('GET', '/admin/posts', ['admin_config' => ['search' => ['shortcut' => false]]])['body'];
        self::assertStringContainsString('data-admin-search-shortcut="0"', $noKeys);
        self::assertStringNotContainsString('aria-keyshortcuts', $noKeys);
    }
}
