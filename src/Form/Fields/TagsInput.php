<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use Closure;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\ListValues;
use FloCMS\Admin\Support\Str;
use InvalidArgumentException;

/**
 * Free words typed as chips (0.37): Enter or the separator (a comma by default;
 * the Arabic comma counts too) adds one, stored as a JSON list of strings:
 *
 *     TagsInput::make('keywords')->suggestions(['villa', 'sea view'])->max(10)
 *
 * On save tags are trimmed, inner spaces collapsed, empty ones dropped and
 * duplicates removed regardless of capitals (the first spelling is kept).
 * Without JavaScript it is one text box ("villa, sea view").
 */
class TagsInput extends Field implements ListField
{
    /** More tags than any form keeps. */
    public const LIMIT = 100;
    public const DEFAULT_LENGTH = 50;

    /** @var list<string>|Closure */
    private array|Closure $suggestions = [];
    /** @var list<string>|null */
    private ?array $resolvedSuggestions = null;
    private bool $onlySuggestions = false;
    private ?int $max = null;
    private int $maxLength = self::DEFAULT_LENGTH;
    private string $separator = ',';

    protected function setUp(): void
    {
        $this->nullable = true;
    }

    /**
     * Words offered while typing, or a closure returning them (called once per request).
     *
     * @param list<string>|Closure(): list<string> $suggestions
     */
    public function suggestions(array|Closure $suggestions): static
    {
        $this->suggestions = $suggestions;
        $this->resolvedSuggestions = null;
        return $this;
    }

    /** @return list<string> */
    public function getSuggestions(): array
    {
        if ($this->resolvedSuggestions === null) {
            $list = $this->suggestions instanceof Closure ? ($this->suggestions)() : $this->suggestions;
            $this->resolvedSuggestions = $this->clean(is_array($list) ? array_values(array_filter($list, 'is_string')) : []);
        }
        return $this->resolvedSuggestions;
    }

    /** Refuse words that are not among the suggestions. */
    public function onlySuggestions(bool $only = true): static
    {
        $this->onlySuggestions = $only;
        return $this;
    }

    /** At most this many tags. */
    public function max(int $max): static
    {
        if ($max < 1 || $max > self::LIMIT) { throw new InvalidArgumentException('TagsInput max must be 1–' . self::LIMIT . '.'); }
        $this->max = $max;
        return $this;
    }

    /** At most this many characters per tag (default 50). */
    public function maxLength(int $length): static
    {
        if ($length < 1 || $length > 255) { throw new InvalidArgumentException('TagsInput maxLength must be 1–255.'); }
        $this->maxLength = $length;
        return $this;
    }

    /** The key that adds a tag besides Enter (default a comma); a tag cannot contain it. */
    public function separator(string $separator): static
    {
        if (mb_strlen($separator) !== 1 || preg_match('/[\p{L}\p{N}\s"\\\\]/u', $separator) === 1) {
            throw new InvalidArgumentException('TagsInput separator must be one character that is not a letter, digit, space, quote or backslash.');
        }
        $this->separator = $separator;
        return $this;
    }

    public function getSeparator(): string { return $this->separator; }
    public function getMax(): ?int { return $this->max; }
    public function getMaxLength(): int { return $this->maxLength; }
    public function isOnlySuggestions(): bool { return $this->onlySuggestions; }

    /* ---------------------------------------------------------------
     * Values
     * ------------------------------------------------------------- */

    public function values(mixed $state): array
    {
        return ListValues::decode($state);
    }

    public function readable(array $values): array
    {
        return $values;
    }

    /**
     * Tags as stored: trimmed, one space between words, without duplicates
     * (compared regardless of capitals; the first spelling stays).
     *
     * @param list<string> $tags
     * @return list<string>
     */
    public function clean(array $tags): array
    {
        $clean = [];
        $seen = [];
        foreach ($tags as $tag) {
            $tag = trim((string) preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $tag));
            if ($tag === '') { continue; }
            $folded = ListValues::fold($tag);
            if (isset($seen[$folded])) { continue; }
            $seen[$folded] = true;
            $clean[] = $tag;
            if (count($clean) >= self::LIMIT + 1) { break; }
        }
        return $clean;
    }

    /** @return list<string> */
    private function parse(mixed $value): array
    {
        if (is_array($value)) { return ListValues::decode($value); }
        if (!is_string($value)) { return []; }
        $text = trim($value);
        if ($text === '') { return []; }
        if ($text[0] === '[') {
            $decoded = json_decode($text, true);
            if (is_array($decoded) && array_is_list($decoded)) { return ListValues::decode($decoded); }
        }
        return ListValues::split($text, [$this->separator]);
    }

    /** @return list<string> the tags with a suggestion's spelling where one matches */
    private function spelled(array $tags): array
    {
        $suggestions = [];
        foreach ($this->getSuggestions() as $suggestion) { $suggestions[ListValues::fold($suggestion)] = $suggestion; }
        return array_map(static fn (string $tag): string => $suggestions[ListValues::fold($tag)] ?? $tag, $tags);
    }

    protected function formatForInput(mixed $value): string
    {
        return ListValues::encode($this->clean(ListValues::decode($value)));
    }

    public function stateFromInput(array $input, array $languages): string|array
    {
        $tags = $this->clean($this->parse($input[$this->name] ?? null));
        return ListValues::encode($this->onlySuggestions ? $this->spelled($tags) : $tags);
    }

    public function dehydrate(string|array $state): mixed
    {
        $tags = is_string($state) ? $this->clean(ListValues::decode($state)) : [];
        if ($tags === []) { return $this->isNullable() ? null : '[]'; }
        return ListValues::encode($tags);
    }

    public function typeError(string $value): ?array
    {
        $tags = ListValues::decode($value);
        $max = $this->max ?? self::LIMIT;
        if (count($tags) > $max) { return ['validation.tags_max', ['max' => (string) $max]]; }
        foreach ($tags as $tag) {
            if (mb_strlen($tag) > $this->maxLength) { return ['validation.tag_length', ['max' => (string) $this->maxLength, 'tag' => mb_strimwidth($tag, 0, 40, '…')]]; }
            if (str_contains($tag, $this->separator) || ($this->separator === ',' && str_contains($tag, '،'))) { return ['validation.tag_separator', ['tag' => $tag, 'separator' => $this->separator]]; }
        }
        if ($this->onlySuggestions) {
            $allowed = array_map([ListValues::class, 'fold'], $this->getSuggestions());
            foreach ($tags as $tag) {
                if (!in_array(ListValues::fold($tag), $allowed, true)) { return ['validation.tag_unknown', ['tag' => $tag]]; }
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------- */

    /**
     * One text box with the tags joined by the separator: what is sent without
     * JavaScript, and what the chips (resources/js/choices.ts) keep up to date.
     */
    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $tags = ListValues::decode($value);
        $text = implode($this->separator . ' ', $tags);
        $attrs = $this->baseAttributes($id, $name, $attributes) + ['type' => 'text', 'value' => $text, 'class' => 'fa-input', 'autocomplete' => 'off'];
        $attrs['placeholder'] ??= AdminLang::get('tags.separate', ['separator' => $this->separator]);
        $data = [
            'separator' => $this->separator,
            'max' => (string) ($this->max ?? self::LIMIT),
            'max-length' => (string) $this->maxLength,
            'only' => $this->onlySuggestions ? '1' : null,
            'suggestions' => json_encode($this->getSuggestions(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'placeholder' => $this->getPlaceholder() ?? AdminLang::get('tags.placeholder'),
            'remove' => AdminLang::get('tags.remove'),
            'add' => AdminLang::get('tags.add'),
            'new' => AdminLang::get('tags.new'),
            'added' => AdminLang::get('tags.added'),
            'removed' => AdminLang::get('tags.removed'),
            'full' => AdminLang::get('tags.full', ['max' => (string) ($this->max ?? self::LIMIT)]),
            'suggestions-label' => AdminLang::get('tags.suggestions'),
        ];
        $html = '<div class="fa-tags" data-admin-tags';
        foreach ($data as $key => $text) {
            if ($text !== null) { $html .= ' data-tags-' . $key . '="' . Str::e($text) . '"'; }
        }
        return $html . '><input' . self::attributes($attrs) . '></div>';
    }
}
