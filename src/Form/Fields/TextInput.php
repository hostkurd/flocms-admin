<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;

/**
 * <input type="text">, or email / url / tel / number / password.
 */
class TextInput extends Field
{
    protected string $type = 'text';
    protected ?int $maxLength = null;
    protected bool $hashed = true;

    public function email(): static
    {
        $this->type = 'email';
        return $this;
    }

    public function url(): static
    {
        $this->type = 'url';
        return $this;
    }

    public function tel(): static
    {
        $this->type = 'tel';
        return $this;
    }

    /** A number; also adds the FloCMS 'numeric' rule. */
    public function numeric(): static
    {
        $this->type = 'number';
        $this->rules('numeric');
        if ($this->nullable === null) {
            $this->nullable = true;
        }

        return $this;
    }

    /**
     * Never shown again after saving; left empty on edit it keeps the stored
     * value. Hashed with password_hash() unless $hashed is false.
     */
    public function password(bool $hashed = true): static
    {
        $this->type = 'password';
        $this->hashed = $hashed;
        return $this;
    }

    /** A password field: the activity log never records it (0.19). */
    public function isPassword(): bool
    {
        return $this->type === 'password';
    }

    public function maxLength(int $length): static
    {
        $this->maxLength = max(1, $length);
        $this->rules('max:' . $this->maxLength);
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function supportsTranslation(): bool
    {
        return in_array($this->type, ['text', 'url'], true);
    }

    public function translatable(bool $translatable = true): static
    {
        if ($translatable && !in_array($this->type, ['text', 'url'], true)) {
            throw new \LogicException('Only text inputs can be translatable.');
        }

        return parent::translatable($translatable);
    }

    public function stateFromRecord(?object $record, string $defaultLang): string|array
    {
        // A stored password (usually a hash) is never sent back to the browser
        return $this->type === 'password' ? '' : parent::stateFromRecord($record, $defaultLang);
    }

    public function skipsWrite(string|array $state, bool $isEdit): bool
    {
        return $this->type === 'password' && $isEdit && $state === '';
    }

    public function dehydrate(string|array $state): mixed
    {
        if ($this->type === 'password' && is_string($state) && $state !== '' && $this->hashed) {
            return password_hash($state, PASSWORD_DEFAULT);
        }

        return parent::dehydrate($state);
    }

    public function typeError(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        return match ($this->type) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? ['validation.email', []] : null,
            'url' => preg_match('#^https?://[^\s]+$#i', $value) !== 1 ? ['validation.url', []] : null,
            default => null,
        };
    }

    protected function normalizeInput(string $value): string
    {
        // Passwords are kept exactly as typed
        return $this->type === 'password' ? $value : parent::normalizeInput($value);
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $attrs = $this->baseAttributes($id, $name, $attributes) + [
            'type' => $this->type,
            'value' => $this->type === 'password' ? '' : $value,
            'class' => 'fa-input',
            'maxlength' => $this->maxLength === null ? null : (string) $this->maxLength,
            'step' => $this->type === 'number' ? 'any' : null,
            'autocomplete' => $this->type === 'password' ? 'new-password' : null,
        ];

        return '<input' . self::attributes($attrs) . '>';
    }
}
