<?php
declare(strict_types=1);

namespace FloCMS\Admin\Validation;

use Closure;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Core\ValidationException;
use FloCMS\Core\Validator;
use InvalidArgumentException;

/**
 * Validates a form's state with FloCMS's own rules, plus the field types'
 * checks and `unique`, and returns messages in the admin's language.
 *
 * Core rules run one at a time (Validator::validate() with a single rule), so
 * the failing rule is known and its message can be translated; core itself is
 * unchanged. Translatable fields: every rule applies to the default language;
 * the other languages are optional, and every rule except `required` applies
 * to them when they are filled.
 */
final class FormValidator
{
    /** Rules handled by FloCMS\Core\Validator. */
    public const CORE_RULES = ['required', 'string', 'integer', 'numeric', 'min', 'max', 'in'];

    /** Rules handled by this package. */
    public const PACKAGE_RULES = ['unique', 'nullable'];

    /**
     * @param list<string> $languages default language first
     * @param Closure(Field, string, ?string): bool|null $isTaken returns true when the value exists in another row
     */
    public function __construct(
        private readonly array $languages,
        private readonly string $defaultLang,
        private readonly ?Closure $isTaken = null,
        private readonly bool $isEdit = false
    ) {
    }

    /**
     * @param array<string, string|array<string, string>> $state
     * @param list<string> $skip names of fields not being written (empty password on edit)
     * @return array<string, string> "title" or "title.ar" => message
     */
    public function validate(Form $form, array $state, array $skip = []): array
    {
        $form = $form->evaluate($state);
        $errors = [];

        foreach ($form->writableFields() as $name => $field) {
            if (in_array($name, $skip, true)) {
                continue;
            }

            $value = $state[$name] ?? '';

            if ($field->isTranslatable()) {
                $values = is_array($value) ? $value : [];
                foreach ($this->languages as $lang) {
                    $item = $values[$lang] ?? '';
                    $isDefault = $lang === $this->defaultLang;
                    if (!$isDefault && $item === '') {
                        continue;
                    }
                    $message = $this->check($field, $item, !$isDefault, $lang);
                    if ($message !== null) {
                        $errors[$isDefault ? $name : $name . '.' . $lang] = $message;
                    }
                }
                continue;
            }

            $message = $this->check($field, is_array($value) ? '' : $value, false);
            if ($message !== null) {
                $errors[$name] = $message;
            }
        }

        return $errors;
    }

    /** First failing check of one value, as a message, or null. */
    private function check(Field $field, string $value, bool $skipRequired, ?string $language = null): ?string
    {
        if ($field instanceof \FloCMS\Admin\Form\Fields\FileUpload && $value === '@remove') { $value = ''; }
        $rules = $field->rulesFor($this->isEdit);
        $names = array_map(static fn (string $rule): string => explode(':', $rule, 2)[0], $rules);
        $typeRules = array_values(array_filter($rules, static fn (string $r): bool => in_array(explode(':', $r, 2)[0], ['integer', 'numeric'], true)));
        $label = $field->getLabel();

        foreach ($rules as $i => $rule) {
            $ruleName = $names[$i];

            if (in_array($ruleName, self::PACKAGE_RULES, true)) {
                continue;
            }
            if (!in_array($ruleName, self::CORE_RULES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown rule "%s" on field "%s". Available: %s, plus ->unique() on the field.',
                    $ruleName,
                    $field->getName(),
                    implode(', ', self::CORE_RULES)
                ));
            }
            if ($ruleName === 'required' && $skipRequired) {
                continue;
            }
            // Optional and empty: only `required` can fail (core's min would)
            if ($value === '' && $ruleName !== 'required') {
                continue;
            }

            // min/max compare numbers when the field is numeric: send the type rule along.
            // It ran (and passed) before, so a failure here is the size rule.
            $ruleString = in_array($ruleName, ['min', 'max'], true) && $typeRules !== []
                ? implode('|', array_merge($typeRules, [$rule]))
                : $rule;

            if (!self::passes($value, $ruleString)) {
                return $this->message($ruleName, explode(':', $rule, 2)[1] ?? '', $label, $typeRules !== []);
            }
        }

        if ($value !== '' && ($typeError = $field->typeError($value)) !== null) {
            return AdminLang::get($typeError[0], ['attribute' => $label] + $typeError[1]);
        }

        if ($value !== '' && $field->isUnique() && $this->isTaken !== null && ($this->isTaken)($field, $value, $language)) {
            return AdminLang::get('validation.unique', ['attribute' => $label]);
        }

        return null;
    }

    private static function passes(string $value, string $rule): bool
    {
        try {
            Validator::validate(['value' => $value], ['value' => $rule]);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    private function message(string $rule, string $parameter, string $label, bool $numeric): string
    {
        $replace = ['attribute' => $label];

        return match ($rule) {
            'min' => AdminLang::get($numeric ? 'validation.min_numeric' : 'validation.min_string', $replace + ['min' => $parameter]),
            'max' => AdminLang::get($numeric ? 'validation.max_numeric' : 'validation.max_string', $replace + ['max' => $parameter]),
            'in' => AdminLang::get('validation.in', $replace + ['values' => str_replace(',', ', ', $parameter)]),
            default => AdminLang::get('validation.' . $rule, $replace),
        };
    }
}
