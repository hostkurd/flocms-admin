<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Reorder;

/** The same reorderable list, but canReorder() refuses. */
final class LockedFaqResource extends FaqResource
{
    protected static string $slug = 'locked-faqs';

    public function canReorder(): bool { return false; }
}
