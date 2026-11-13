<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Extension;

use FloCMS\Admin\Blocks\Block;

/** The site's own block taking over a package's type. */
final class SiteHero extends Block
{
    protected static string $type = 'site_hero';

    public function fields(): array { return []; }
}
