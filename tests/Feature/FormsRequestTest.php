<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Feature;
use FloCMS\Admin\Tests\Fixtures\Resources\PropertyResource;

final class FormsRequestTest extends RequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $pdo = self::pdo(); $pdo->exec('DROP TABLE IF EXISTS properties');
        $id = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec('CREATE TABLE properties (id ' . $id . ', title TEXT NULL, description TEXT NULL, country VARCHAR(20), city VARCHAR(40), listing_type VARCHAR(20), price DECIMAL(12,2) NULL, lease_months INT NULL, access_code VARCHAR(255) NULL, status VARCHAR(20), published_on DATE NULL)' . ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
        $pdo->exec("INSERT INTO properties (title, country, city, listing_type, price, status) VALUES ('{\"en\":\"Villa\"}', 'iq', 'sulaimani', 'sale', 100, 'draft')");
    }
    private function options(): array { return ['admin_config' => ['reactive' => true, 'resources' => [PropertyResource::class]]]; }
    private function mount(): array { return self::request('GET', '/en/admin/properties', $this->options()); }
    private function update(array $previous, string $action, array $state = [], array $options = []): array
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $previous['body'], $match); self::assertNotEmpty($match[1] ?? '', $previous['body']);
        return self::post('/en/flo-reactive/update', ['_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_action' => $action, 'state' => $state], $options + $this->options() + ['session' => $previous['session']]);
    }
    private function open(): array { return $this->update($this->mount(), 'edit', ['selectedId' => '1']); }
    private function draft(array $extra = []): string
    {
        return json_encode($extra + ['title' => ['en' => 'Villa'], 'country' => 'iq', 'city' => 'sulaimani', 'listing_type' => 'sale', 'price' => '100', 'status' => 'draft']);
    }
    public function testLiveRefreshChangesDependenciesWithoutSavingOrConsumingTheOperation(): void
    {
        $open = $this->open();
        $refresh = $this->update($open, 'refreshForm', ['draft' => $this->draft(['country' => 'gr', 'access_code' => 'must-not-return'])]);
        self::assertSame(200, $refresh['status']);
        self::assertStringContainsString('Athens', $refresh['body']); self::assertStringNotContainsString('must-not-return', $refresh['body']);
        self::assertSame('iq', self::row('properties', 1)['country']);
        $saved = $this->update($refresh, 'save', ['draft' => $this->draft(['country' => 'gr', 'city' => 'athens'])]);
        self::assertSame(200, $saved['status']); self::assertSame('athens', self::row('properties', 1)['city']);
    }
    public function testForgedDependentValuesAndConditionalRequiredFieldsCannotBeSaved(): void
    {
        foreach ([['country' => 'gr', 'city' => 'sulaimani'], ['status' => 'published', 'published_on' => '']] as $invalid) {
            $response = $this->update($this->open(), 'save', ['draft' => $this->draft($invalid)]);
            self::assertSame(422, $response['status']); self::assertSame('draft', self::row('properties', 1)['status']);
        }
    }
    public function testHiddenValuesArePreservedOnEditAndNotAcceptedOnCreate(): void
    {
        $saved = $this->update($this->open(), 'save', ['draft' => $this->draft(['lease_months' => '99', 'published_on' => '2026-10-14'])]);
        self::assertSame(200, $saved['status']); self::assertNull(self::row('properties', 1)['lease_months']); self::assertNull(self::row('properties', 1)['published_on']);
        $open = $this->update($this->mount(), 'create');
        $created = $this->update($open, 'save', ['draft' => $this->draft(['lease_months' => '99', 'published_on' => '2026-10-14'])]);
        self::assertSame(200, $created['status']); self::assertNull(self::row('properties', 2)['lease_months']);
    }
    public function testLargerUnicodeDraftSavesAndRemainsBounded(): void
    {
        $description = str_repeat('خانوو ', 2000);
        $saved = $this->update($this->open(), 'save', ['draft' => $this->draft(['description' => ['en' => $description]])]);
        self::assertSame(200, $saved['status']); self::assertSame(trim($description), json_decode(self::row('properties', 1)['description'], true)['en']);
        self::assertSame(200, $this->open()['status']);
    }
    public function testStandardRefreshDoesNotWriteAndRetainsTheFieldsForNoJavascript(): void
    {
        $response = self::post('/en/admin/properties/edit/1', ['_refresh' => '1', 'country' => 'gr', 'city' => 'sulaimani', 'listing_type' => 'sale', 'price' => '100', 'status' => 'draft', 'title' => ['en' => 'Unsaved']], $this->options());
        self::assertSame(200, $response['status']); self::assertFalse($response['redirected']);
        self::assertStringContainsString('Athens', $response['body']); self::assertStringContainsString('Unsaved', $response['body']);
        self::assertSame('iq', self::row('properties', 1)['country']);
    }
    public function testLiveRefreshRechecksPermissionAndStaleSaveStillRejects(): void
    {
        $open = $this->open();
        self::assertSame(403, $this->update($open, 'refreshForm', ['draft' => $this->draft()], ['permissions' => [1 => ['content.properties.view']]])['status']);
        self::pdo()->exec("UPDATE properties SET city='erbil' WHERE id=1");
        self::assertSame(200, $this->update($open, 'refreshForm', ['draft' => $this->draft()])['status']);
        self::assertSame(422, $this->update($open, 'save', ['draft' => $this->draft()])['status']);
        self::assertSame('erbil', self::row('properties', 1)['city']);
    }
    public function testCustomAndBulkActionFormsShareDependencyValidationAndLiveGuards(): void
    {
        $custom = $this->update($this->mount(), 'openAction', ['selection' => 'row:relocate:1']);
        $refreshed = $this->update($custom, 'refreshForm', ['draft' => '{"country":"gr","city":"sulaimani"}']);
        self::assertSame(200, $refreshed['status']); self::assertStringContainsString('Athens', $refreshed['body']);
        self::assertSame(422, $this->update($refreshed, 'execute', ['draft' => '{"country":"gr","city":"sulaimani"}'])['status']);
        $saved = $this->update($refreshed, 'execute', ['draft' => '{"country":"gr","city":"athens"}']);
        self::assertSame(200, $saved['status']); self::assertSame('athens', self::row('properties', 1)['city']);
        $bulk = $this->update($this->mount(), 'openBulk', ['selectedIds' => '["1"]', 'selectedBulk' => 'relocate']);
        $refreshed = $this->update($bulk, 'refreshForm', ['draft' => '{"country":"iq","city":"athens"}']);
        self::assertSame(200, $refreshed['status']);
        self::assertSame(403, $this->update($refreshed, 'refreshForm', [], ['permissions' => [1 => ['content.properties.view']]])['status']);
        $saved = $this->update($refreshed, 'execute', ['draft' => '{"country":"iq","city":"erbil"}']);
        self::assertSame(200, $saved['status']); self::assertSame('erbil', self::row('properties', 1)['city']);
    }
    public function testStandardActionAndBulkRefreshDoNotExecuteCallbacks(): void
    {
        foreach (['/en/admin/properties/action/row/relocate/1', '/en/admin/properties/bulk/relocate?ids[]=1'] as $url) {
            $open = self::request('GET', $url, $this->options());
            preg_match('/name="_operation" value="([^"]+)"/', $open['body'], $token); self::assertNotEmpty($token[1] ?? '');
            $refresh = self::post(strtok($url, '?'), ['_operation' => $token[1], '_ids' => '["1"]', '_refresh' => '1', 'country' => 'gr', 'city' => 'erbil'], $this->options() + ['session' => $open['session']]);
            self::assertSame(200, $refresh['status']); self::assertFalse($refresh['redirected']); self::assertStringContainsString('Athens', $refresh['body']);
            self::assertSame('sulaimani', self::row('properties', 1)['city']);
        }
    }

}
