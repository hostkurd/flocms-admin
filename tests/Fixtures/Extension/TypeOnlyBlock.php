<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Extension;

use FloCMS\Admin\Blocks\Block;

/** A component that only says its type: the label comes from it. */
final class TypeOnlyBlock extends Block
{
    public function __construct(private string $kind = 'price_table') {}

    public function blockType(): string { return $this->kind; }

    public function fields(): array { return []; }
}
