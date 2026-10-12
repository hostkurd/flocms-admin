<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form;

use InvalidArgumentException;

/**
 * The fields of a resource's create and edit pages, in order.
 */
final class Form
{
    /** @var array<string, Field> */
    private array $fields = [];

    /** @param list<Field> $fields */
    public function schema(array $fields): self
    {
        foreach ($fields as $field) {
            if (!$field instanceof Field) {
                throw new InvalidArgumentException('Form::schema() expects Field objects.');
            }
            if (isset($this->fields[$field->getName()])) {
                throw new InvalidArgumentException('The form has two fields named "' . $field->getName() . '".');
            }
            $this->fields[$field->getName()] = $field;
        }

        return $this;
    }

    /** @return array<string, Field> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $name): ?Field
    {
        return $this->fields[$name] ?? null;
    }

    /** @return array<string, Field> fields whose value is written to the table */
    public function writableFields(): array
    {
        return array_filter($this->fields, static fn (Field $f): bool => $f->isWritable());
    }

    /** @return list<string> */
    public function translatableNames(): array
    {
        return array_values(array_keys(array_filter($this->fields, static fn (Field $f): bool => $f->isTranslatable())));
    }
}
