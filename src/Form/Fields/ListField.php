<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

/**
 * A field whose value is a list of strings kept in one column as a JSON list
 * (0.37: CheckboxList, TagsInput). Settings pages, page forms, repeater items
 * and the activity log keep and compare it as a list.
 */
interface ListField
{
    /**
     * The items of a form state (or a stored value), in order.
     *
     * @return list<string>
     */
    public function values(mixed $state): array;

    /**
     * The items as people read them: option labels for CheckboxList keys.
     *
     * @param list<string> $values
     * @return list<string>
     */
    public function readable(array $values): array;

    /**
     * The items the record being saved already has (0.37), set before a save
     * is checked: an option or suggestion removed since stays valid there.
     *
     * @param list<string> $stored
     */
    public function allowStored(array $stored): void;
}
