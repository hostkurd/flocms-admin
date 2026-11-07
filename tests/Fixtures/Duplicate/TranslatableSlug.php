<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\Duplicate;

use FloCMS\Admin\Form\Fields\Slug;

/** A site's own Slug that allows one address per language (the package's Slug refuses translatable()). */
final class TranslatableSlug extends Slug
{
    public function supportsTranslation(): bool { return true; }
}
