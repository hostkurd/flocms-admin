<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Preferences\Preferences;
use FloCMS\Admin\Table\Columns\DateColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\ColumnVisibility;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\App;

final class ColumnVisibilityTest extends TestCase
{
    private ?\FloCMS\Core\Database $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = App::$db;
        App::$db = null; // preferences in the session
        $_SESSION = ['user_id' => 7];
        Preferences::reset();
    }

    protected function tearDown(): void
    {
        App::$db = $this->previousDb;
        $_SESSION = [];
        Preferences::reset();
        parent::tearDown();
    }

    private function table(): Table
    {
        return (new Table())->columns([
            TextColumn::make('title')->searchable()->toggleable(),
            TextColumn::make('city')->toggleable(),
            TextColumn::make('price'),
            DateColumn::make('updated_at')->toggleable(hiddenByDefault: true),
        ]);
    }

    public function testDefaultsAndTheTitleColumnIsNeverToggleable(): void
    {
        $visibility = new ColumnVisibility($this->table(), 'homes');
        self::assertSame(['city' => true, 'updated_at' => false], $visibility->choices());
        self::assertSame(['title', 'city', 'price'], array_keys($visibility->visibleColumns()));
        self::assertTrue($visibility->isHidden('updated_at'));
        self::assertFalse($visibility->isHidden('title'));
        self::assertFalse($visibility->changed());
    }

    public function testChoicesAreStoredAsChangesFromTheDefaults(): void
    {
        (new ColumnVisibility($this->table(), 'homes'))->save(['updated_at', 'title']);
        self::assertSame(['city' => false, 'updated_at' => true], Preferences::current()->get('columns.homes'));
        $visibility = new ColumnVisibility($this->table(), 'homes');
        self::assertSame(['title', 'price', 'updated_at'], array_keys($visibility->visibleColumns()));

        // A column added later follows its own default
        $table = $this->table()->columns([TextColumn::make('rooms')->toggleable()]);
        self::assertTrue((new ColumnVisibility($table, 'homes'))->choices()['rooms']);

        // Choosing the defaults again stores nothing
        (new ColumnVisibility($this->table(), 'homes'))->save(['city']);
        self::assertNull(Preferences::current()->get('columns.homes'));
        (new ColumnVisibility($this->table(), 'homes'))->save([]);
        (new ColumnVisibility($this->table(), 'homes'))->reset();
        self::assertNull(Preferences::current()->get('columns.homes'));
    }
}
