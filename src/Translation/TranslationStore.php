<?php
declare(strict_types=1);

namespace FloCMS\Admin\Translation;

use FloCMS\Core\Database;

/**
 * How translatable values are stored. v0.1 ships JsonColumnStore (one JSON
 * object per row in the field's own column); a store that keeps translations
 * in a separate table can implement the same contract later.
 */
interface TranslationStore
{
    /**
     * Raw column value → ['en' => '...', 'ar' => '...'].
     * A value that is not a JSON object is read as the default language.
     *
     * @return array<string, string>
     */
    public function decode(mixed $raw, string $defaultLang): array;

    /**
     * ['en' => '...', ...] → value to write; empty languages are dropped and
     * null is returned when nothing is filled.
     *
     * @param array<string, mixed> $values
     */
    public function encode(array $values): ?string;

    /**
     * Add one OR condition to $query (normally the search group): $column
     * contains $term in any of $languages.
     *
     * @param list<string> $languages
     */
    public function whereContains(Database $query, string $column, array $languages, string $term, string $defaultLang): Database;

    /** SQL expression that sorts $column by one language. */
    public function sortExpression(Database $query, string $column, string $lang, string $defaultLang): string;
}
