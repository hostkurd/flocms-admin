<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Activity\ActivityChanges;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Console\Stub;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormSubmission;
use FloCMS\Admin\Tests\Fixtures\Trash\NoteResource;
use FloCMS\Admin\Tests\Fixtures\Trash\OwnNoteResource;
use FloCMS\Admin\Tests\Fixtures\Trash\RuledNoteResource;
use FloCMS\Admin\Tests\Fixtures\Trash\VillaResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Translation\JsonColumnStore;
use FloCMS\Admin\Trash\Trash;
use FloCMS\Core\App;
use FloCMS\Core\Database;
use FloCMS\Core\ValidationException;

/**
 * The trash and the activity log on SQLite (always) and MySQL/MariaDB (when configured).
 */
abstract class TrashTestCase extends TestCase
{
    protected Database $db;
    protected RecordRepository $villas;

    abstract protected function connect(): Database;

    /** CREATE TABLE statements for villas, cities, tags, villa_tag and notes. */
    abstract protected function createTables(Database $db): void;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
        $this->db = $this->connect();
        $this->dropTables();
        $this->createTables($this->db);
        $file = sys_get_temp_dir() . '/flo-admin-trash-migration-' . getmypid() . '.php';
        file_put_contents($file, Stub::render('trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']));
        (require $file)->up($this->db);
        unlink($file);
        App::$db = $this->db;
        Admin::useConfig(['resources' => [VillaResource::class, NoteResource::class, OwnNoteResource::class, RuledNoteResource::class], 'media_library' => false], true, sys_get_temp_dir());
        Trash::newBatch();
        VillaResource::$restored = [];
        $this->villas = new RecordRepository(new VillaResource(), $this->db, new JsonColumnStore());

        $this->db->table('cities')->insert(['id' => 1, 'name' => 'Chania']);
        $this->db->table('cities')->insert(['id' => 2, 'name' => 'Rethymno']);
        foreach ([1 => 'Pool', 2 => 'Garden', 3 => 'Sea view'] as $id => $name) { $this->db->table('tags')->insert(['id' => $id, 'name' => $name]); }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) { $this->dropTables(); }
        App::$db = null;
        parent::tearDown();
    }

    private function dropTables(): void
    {
        foreach (['villa_tag', 'villas', 'cities', 'tags', 'notes', 'admin_trash', 'admin_activity'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /** Save a new villa through the form, as the panel does. */
    private function create(array $input): string
    {
        $resource = new VillaResource();
        $submission = new FormSubmission($resource, $this->villas);
        $form = $resource->form(new Form());
        $state = $submission->state($form, $input + ['title' => ['en' => 'Villa by the sea', 'ar' => 'فيلا على البحر'], 'slug' => 'villa-by-the-sea', 'status' => 'draft'], null);
        self::assertSame([], $submission->errors($form, $state, null));
        return $submission->save($form, $state, null);
    }

    private function edit(string $id, array $input): void
    {
        $resource = new VillaResource();
        $record = $this->villas->find($id);
        $submission = new FormSubmission($resource, $this->villas);
        $form = $resource->form(new Form());
        // A whole form is submitted: the current values, with these changes
        $current = [];
        foreach ($resource->form(new Form())->fields() as $name => $field) { $current[$name] = $field->stateFromRecord($record, 'en'); }
        $state = $submission->state($form, array_replace($current, $input), $record);
        self::assertSame([], $submission->errors($form, $state, $record));
        $submission->save($form, $state, $record);
    }

    /** @return list<string> */
    private function tagsOf(string $id): array
    {
        $rows = $this->db->table('villa_tag')->where('villa_id', '=', $id)->orderBy('tag_id', 'asc')->get();
        return array_map(static fn (object $row): string => (string) $row->tag_id, $rows);
    }

    /** @return list<string> */
    private function actions(): array
    {
        return array_map(static fn (object $row): string => (string) $row->action, $this->db->table('admin_activity')->orderBy('id', 'asc')->get());
    }

    public function testAResourceThatNarrowsItsQueryShowsEachPersonTheirOwnDeletes(): void
    {
        $session = $_SESSION ?? [];
        try {
            $own = new RecordRepository(new OwnNoteResource(), $this->db, new JsonColumnStore());
            $ruled = new RecordRepository(new RuledNoteResource(), $this->db, new JsonColumnStore());
            foreach (['mine', 'theirs', 'team plan', 'private'] as $title) { $this->db->table('notes')->insert(['title' => $title]); }

            $_SESSION = ['user_id' => 5, 'fullname' => 'Lana'];
            $own->delete('1');
            $ruled->delete('4');
            Trash::newBatch();
            $_SESSION = ['user_id' => 6, 'fullname' => 'Rawa'];
            $own->delete('2');
            $ruled->delete('3');

            // Rawa: her own delete of the narrowed resource, and the ruled one's "team" note
            self::assertSame(['theirs'], array_map(static fn ($entry): string => $entry->data['title'], Trash::entries(['own-notes'])['items']));
            self::assertSame(['team plan'], array_map(static fn ($entry): string => $entry->data['title'], Trash::entries(['ruled-notes'])['items']));
            self::assertSame(['own-notes' => 1, 'ruled-notes' => 1], Trash::counts(['own-notes', 'ruled-notes']));
            self::assertSame([6 => 'Rawa'], Trash::deleters(['own-notes']));
            self::assertFalse(Trash::visible(Trash::findRecord('own-notes', '1')));
            self::assertTrue(Trash::visible(Trash::findRecord('own-notes', '2')));

            $_SESSION = ['user_id' => 5, 'fullname' => 'Lana'];
            self::assertSame(['mine'], array_map(static fn ($entry): string => $entry->data['title'], Trash::entries(['own-notes'])['items']));
            self::assertSame(['team plan'], array_map(static fn ($entry): string => $entry->data['title'], Trash::entries(['ruled-notes'])['items']), 'the rule shows the team note to everyone and hides "private", even from Lana who deleted it');
        } finally {
            $_SESSION = $session;
        }
    }

    public function testDeleteMovesTheRecordWithItsLinksToTheTrash(): void
    {
        $id = $this->create(['tags' => ['2', '1'], 'city_id' => '1']);
        $this->villas->delete($id);

        self::assertNull($this->villas->find($id), 'the row leaves its table: the website never shows it');
        self::assertSame([], $this->tagsOf($id), 'pivot rows go with it');
        $entry = Trash::findRecord('villas', $id);
        self::assertNotNull($entry);
        self::assertSame('Villa by the sea', $entry->label());
        self::assertSame('villa-by-the-sea', $entry->data['slug']);
        self::assertEqualsCanonicalizing(['1', '2'], $entry->links['tags']);
        self::assertSame(1, Trash::count('villas'));
        self::assertSame(['created', 'deleted'], $this->actions());
    }

    public function testRestorePutsTheRecordBackWithTheSameIdAndLinks(): void
    {
        $id = $this->create(['tags' => ['1', '3'], 'city_id' => '2', 'featured' => '1']);
        $before = (array) $this->villas->find($id);
        $this->villas->delete($id);

        $restored = Trash::restore(Trash::findRecord('villas', $id));

        self::assertSame($id, (string) $restored->id);
        self::assertEquals($before, (array) $this->villas->find($id), 'every column comes back as it was');
        self::assertSame(['1', '3'], $this->tagsOf($id));
        self::assertNull(Trash::findRecord('villas', $id));
        self::assertSame([$id], VillaResource::$restored, 'afterRestore() runs');
        self::assertSame(['created', 'deleted', 'restored'], $this->actions());
    }

    public function testRestoreLeavesOutColumnsRemovedSince(): void
    {
        $id = $this->create([]);
        $this->villas->delete($id);
        $this->db->query('UPDATE admin_trash SET data = ? WHERE record_id = ?', [json_encode(Trash::findRecord('villas', $id)->data + ['dropped_column' => 'x']), $id]);

        Trash::restore(Trash::findRecord('villas', $id));
        self::assertNotNull($this->villas->find($id));
    }

    public function testRestoreSkipsLinksToRecordsDeletedMeanwhile(): void
    {
        $id = $this->create(['tags' => ['1', '2']]);
        $this->villas->delete($id);
        $this->db->table('tags')->where('id', '=', 2)->delete();

        Trash::restore(Trash::findRecord('villas', $id));

        self::assertSame(['1'], $this->tagsOf($id));
    }

    public function testRestoreIsRefusedWhenTheSlugIsTaken(): void
    {
        $id = $this->create([]);
        $this->villas->delete($id);
        $this->create(['title' => ['en' => 'New villa'], 'slug' => 'villa-by-the-sea']);

        try {
            Trash::restore(Trash::findRecord('villas', $id));
            self::fail('A taken slug must refuse the restore.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('Another villa now uses the slug “villa-by-the-sea”', Trash::message($error));
        }
        self::assertNotNull(Trash::findRecord('villas', $id), 'the record stays in the trash');
        self::assertNull($this->villas->find($id));
    }

    public function testRestoreIsRefusedWhenItsParentIsGone(): void
    {
        $id = $this->create(['city_id' => '2']);
        $this->villas->delete($id);
        $this->db->table('cities')->where('id', '=', 2)->delete();

        try {
            Trash::restore(Trash::findRecord('villas', $id));
            self::fail('A missing parent must refuse the restore.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('Its City (#2) no longer exists', Trash::message($error));
        }
    }

    public function testRestoreIsRefusedWhenTheIdIsUsedAgain(): void
    {
        $id = $this->create([]);
        $this->villas->delete($id);
        $this->villas->insert(['id' => $id, 'title' => '{"en":"Squatter"}', 'slug' => 'squatter', 'status' => 'draft', 'featured' => 0]);

        $this->expectException(ValidationException::class);
        Trash::restore(Trash::findRecord('villas', $id));
    }

    public function testUndoRestoresOneDeleteOperation(): void
    {
        $session = $_SESSION ?? [];
        $_SESSION = ['role' => 3, 'user_id' => 5, 'fullname' => 'Lana'];
        \FloCMS\Core\Config::set('permissions', [3 => ['*']]);
        try { $this->undoOneOperation(); } finally { $_SESSION = $session; }
    }

    private function undoOneOperation(): void
    {
        $a = $this->create(['slug' => 'a']);
        $b = $this->create(['slug' => 'b']);
        $c = $this->create(['slug' => 'c']);
        Trash::newBatch();
        $this->villas->delete($a);
        Trash::newBatch();
        $batch = Trash::batch();
        $this->villas->delete($b);
        $this->villas->delete($c);

        $result = Trash::undo($batch);

        self::assertCount(2, $result['restored'], 'a bulk delete undoes as one');
        self::assertSame([], $result['refused']);
        self::assertNotNull($this->villas->find($b));
        self::assertNotNull($this->villas->find($c));
        self::assertNull($this->villas->find($a), 'another delete stays in the trash');
        self::assertSame(['restored' => [], 'refused' => []], Trash::undo('not-a-batch'));
        self::assertSame('Lana', Trash::findRecord('villas', $a)->deletedByName);
        $_SESSION['user_id'] = 6;
        self::assertSame([], Trash::undo(Trash::findRecord('villas', $a)->batch)['restored'], 'only the person who deleted can undo');
    }

    public function testPurgeDeletesForGoodAndExpiredEntriesGo(): void
    {
        $old = $this->create(['slug' => 'old']);
        $new = $this->create(['slug' => 'new']);
        $this->villas->delete($old);
        $this->villas->delete($new);
        $this->db->query('UPDATE admin_trash SET deleted_at = ? WHERE record_id = ?', [date('Y-m-d H:i:s', time() - 31 * 86400), $old]);

        self::assertSame(1, Trash::purgeExpired());
        self::assertNull(Trash::findRecord('villas', $old));
        self::assertNotNull(Trash::findRecord('villas', $new));
        self::assertSame(0, Trash::purgeExpired(), 'nothing else is old enough');

        Trash::purge(Trash::findRecord('villas', $new));
        self::assertSame(0, Trash::count('villas'));
        self::assertSame(2, count(array_filter($this->actions(), static fn (string $a): bool => $a === 'purged')));
    }

    public function testResourcesWithoutTheTrashDeleteAtOnce(): void
    {
        $notes = new RecordRepository(new NoteResource(), $this->db, new JsonColumnStore());
        $id = $notes->insert(['title' => 'Call the plumber']);
        $notes->delete($id);

        self::assertNull($notes->find($id));
        self::assertSame(0, Trash::count('notes'));
        self::assertSame(['deleted'], $this->actions(), 'still logged');
    }

    public function testWithoutItsTableTheTrashIsOff(): void
    {
        $this->db->query('DROP TABLE admin_trash');
        Trash::reset();
        $id = $this->create([]);
        $this->villas->delete($id);

        self::assertFalse(Trash::enabled());
        self::assertNull($this->villas->find($id));
    }

    public function testEditsAreLoggedFieldByField(): void
    {
        $id = $this->create(['tags' => ['1'], 'city_id' => '1', 'body' => '<p>Short.</p>', 'owner_phone' => '555', 'notes' => 'a', 'secret' => 'old']);
        $this->edit($id, [
            'title' => ['en' => 'Villa by the sea', 'ar' => 'فيلا جديدة'],
            'city_id' => '2', 'tags' => ['1', '3'], 'status' => 'published', 'featured' => '1',
            'body' => '<p>' . str_repeat('Long text. ', 30) . '</p>', 'owner_phone' => '777', 'notes' => 'b', 'secret' => 'new',
        ]);

        $entry = Activity::forRecord('villas', $id)[0];
        self::assertSame('updated', $entry->action);
        $changes = [];
        foreach ($entry->changes as $change) { $changes[$change['field'] . ($change['lang'] !== null ? '.' . $change['lang'] : '')] = $change; }

        self::assertSame(['title.ar', 'city_id', 'tags', 'status', 'featured', 'body', 'owner_phone', 'notes'], array_keys($changes), 'passwords are never logged');
        self::assertSame(['فيلا على البحر', 'فيلا جديدة'], [$changes['title.ar']['before'], $changes['title.ar']['after']], 'translations per language');
        self::assertSame(['Chania', 'Rethymno'], [$changes['city_id']['before'], $changes['city_id']['after']], 'relations by name');
        self::assertSame(['Pool', 'Pool, Sea view'], [$changes['tags']['before'], $changes['tags']['after']]);
        self::assertSame(['Draft', 'Published'], [$changes['status']['before'], $changes['status']['after']], 'choices by their label');
        self::assertSame(['No', 'Yes'], [$changes['featured']['before'], $changes['featured']['after']]);
        self::assertSame('text', $changes['body']['type'], 'rich text by its length');
        self::assertSame(['6', (string) ActivityChanges::length(str_repeat('Long text. ', 30))], [$changes['body']['before'], $changes['body']['after']]);
        self::assertSame(['hidden', '', ''], [$changes['owner_phone']['type'], $changes['owner_phone']['before'], $changes['owner_phone']['after']], '->hiddenFromActivity()');
        self::assertSame('hidden', $changes['notes']['type'], 'Resource::$activityExcept');
        self::assertStringNotContainsString('777', (string) $this->db->table('admin_activity')->where('id', '=', $entry->id)->first()->changes);
    }

    public function testSavingWithoutChangesWritesNothing(): void
    {
        $id = $this->create(['tags' => ['2', '1']]);
        $this->edit($id, []);
        self::assertSame(['created'], $this->actions());
    }

    public function testActivityEntriesExpire(): void
    {
        $this->create([]);
        $this->db->query('UPDATE admin_activity SET created_at = ?', [date('Y-m-d H:i:s', time() - 400 * 86400)]);
        $this->create(['slug' => 'other']);

        self::assertSame(1, Activity::purgeExpired());
        self::assertSame(['created'], $this->actions());
    }

    public function testActivityFiltersAndPeople(): void
    {
        $id = $this->create([]);
        $this->edit($id, ['status' => 'published']);
        $this->villas->delete($id);

        self::assertSame(3, Activity::entries([])['total']);
        self::assertSame(['deleted'], array_map(static fn ($e): string => $e->action, Activity::entries(['group' => 'deleted'])['items']));
        self::assertSame(['updated'], array_map(static fn ($e): string => $e->action, Activity::entries(['group' => 'edited'])['items']));
        self::assertSame(0, Activity::entries(['group' => 'other'])['total']);
        self::assertSame(3, Activity::entries(['search' => 'Villa by'])['total']);
        self::assertSame(1, Activity::entries(['search' => 'Published'])['total'], 'changed values are searchable');
        self::assertSame(['villas'], Activity::resources());
        Activity::log('published', $this->villas->find($id) ?? (object) ['id' => $id, 'title' => 'x'], ['reason' => 'Scheduled'], VillaResource::class);
        self::assertSame(1, Activity::entries(['group' => 'other'])['total']);
    }
}
