<?php
declare(strict_types=1);
namespace FloCMS\Admin\Actions;

use FloCMS\Admin\{Form\Form, Form\Fields\TextInput, Translation\Languages, Validation\FormValidator};

/** Declared input extraction, validation and password-safe draft rendering. */
final class ActionForm
{
    public static function state(Action $action, Form $form, array $input, ?object $record): array
    {
        $state = [];
        foreach ($form->fields() as $name => $field) {
            $state[$name] = $field->isWritable() && $field->submitted($input) ? $field->stateFromInput($input, Languages::all()) : $field->stateFromRecord($record, Languages::default());
        }
        $form->evaluate($state, $record);
        foreach ($form->fields() as $field) { $state = $field->prepare($state, Languages::default()); }
        $state = $action->normalize($state, $record);
        $form->evaluate($state, $record);
        return $state;
    }
    public static function errors(Action $action, Form $form, array $state, ?object $record): array
    {
        $form = $form->evaluate($state, $record);
        $callback = $action->uniqueness();
        $unique = $callback !== null ? static fn ($field, $value, $language) => $callback($field, $value, $language, $record) : null;
        $errors = (new FormValidator(Languages::all(), Languages::default(), $unique))->validate($form, $state);
        return $errors !== [] ? $errors : $action->errors($state, $record);
    }
    public static function safe(Form $form, array $input): array
    {
        $form = $form->evaluate($input);
        $safe = [];
        foreach ($form->writableFields() as $name => $field) {
            if (!$field->submitted($input)) { continue; }
            $safe[$name] = $field instanceof TextInput && $field->getType() === 'password' ? '' : $field->stateFromInput($input, Languages::all());
        }
        return $safe;
    }
    public static function dehydrate(Form $form, array $state): array
    {
        $form = $form->evaluate($state);
        $data = [];
        foreach ($form->writableFields() as $name => $field) { $data[$name] = $field->dehydrate($state[$name]); }
        return $data;
    }
}
