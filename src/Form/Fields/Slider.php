<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Blocks\Core\Slider as SliderBlock;

/**
 * The slides of a slider on their own (0.29), for a record that has one
 * slider and no blocks (a home page setting, a property's photos):
 *
 *     Slider::make('slides')
 *
 * Stored like a repeater: [{"media":"42","heading":"…","position":"bottom-start",…}].
 * The website prints it with slider($record->slides).
 */
class Slider extends Repeater
{
    protected function setUp(): void
    {
        parent::setUp();
        SliderBlock::slides($this);
    }
}
