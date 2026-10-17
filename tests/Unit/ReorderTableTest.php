<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Tests\Fixtures\Reorder\FaqResource;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\TestCase;
use InvalidArgumentException;

final class ReorderTableTest extends TestCase
{
    public function testTablesAreNotReorderableUntilAsked(): void
    {
        $table = new Table();
        self::assertFalse($table->isReorderable());
        self::assertNull($table->getReorderColumn());
        self::assertFalse((new PostResource())->table(new Table())->isReorderable());
    }

    public function testReorderableDefaultsAndOptions(): void
    {
        $table = (new Table())->reorderable();
        self::assertTrue($table->isReorderable());
        self::assertSame('sort', $table->getReorderColumn());
        self::assertFalse($table->addsNewRecordsFirst());
        self::assertSame(500, $table->getReorderLimit());

        $table->reorderable('position')->newRecordsFirst()->reorderLimit(1000);
        self::assertSame('position', $table->getReorderColumn());
        self::assertTrue($table->addsNewRecordsFirst());
        self::assertSame(1000, $table->getReorderLimit());
        self::assertSame(Table::MAX_REORDER_LIMIT, $table->reorderLimit(99999)->getReorderLimit());
        self::assertSame(1, $table->reorderLimit(0)->getReorderLimit());
        self::assertFalse($table->newRecordsFirst(false)->addsNewRecordsFirst());
    }

    public function testColumnMustBeAnIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Table())->reorderable('sort; DROP TABLE faqs');
    }

    public function testResourceHooksDefaultToAllowedAndSilent(): void
    {
        $resource = new class extends \FloCMS\Admin\Resource {
            public function form(\FloCMS\Admin\Form\Form $form): \FloCMS\Admin\Form\Form { return $form; }
            public function table(Table $table): Table { return $table; }
        };
        self::assertTrue($resource->canReorder());
        $resource->afterReorder(['1', '2']);
        self::assertSame('sort', (new FaqResource())->table(new Table())->getReorderColumn());
    }
}
