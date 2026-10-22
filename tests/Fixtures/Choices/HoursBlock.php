<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Choices;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\{CheckboxList, TagsInput, TimePicker};

/** A site block with the 0.37 fields: opening days, notes and a time. */
final class HoursBlock extends Block
{
    protected static string $type = 'hours_test';
    protected static string $category = 'site';
    protected static string $icon = 'clock';
    protected static ?string $label = 'Opening hours';

    public function fields(): array
    {
        return [
            CheckboxList::make('days')->options(['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday'])->required(),
            TagsInput::make('notes')->max(3),
            TimePicker::make('opens')->step(30),
        ];
    }
}
