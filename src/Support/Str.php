<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/** @internal */
final class Str
{
    /** "blog_posts" or "blog-posts" → "Blog posts". */
    public static function headline(string $value): string
    {
        $value = trim(preg_replace('/[\s_\-]+/', ' ', $value) ?? $value);

        return $value === '' ? '' : mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    /** "BlogPost" → "blog-post". */
    public static function kebab(string $value): string
    {
        $value = preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', $value) ?? $value;

        return strtolower(str_replace(['_', ' '], '-', $value));
    }

    /** "blog_post" → "blog_posts" (simple English plural, only used for suggestions). */
    public static function plural(string $value): string
    {
        if (preg_match('/[^aeiou]y$/', $value)) {
            return substr($value, 0, -1) . 'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/', $value)) {
            return $value . 'es';
        }

        return $value . 's';
    }

    /**
     * URL slug: lower case, letters and digits of any script kept, everything
     * else collapsed to single hyphens. Latin accents are removed when iconv
     * can transliterate them.
     */
    public static function slug(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        if (function_exists('transliterator_transliterate')) {
            $latin = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
            // Only keep the transliteration for Latin text: other scripts stay readable
            if (is_string($latin) && preg_match('/^[\p{Latin}\p{N}\p{P}\p{Z}\p{S}]*$/u', $value)) {
                $value = strtolower($latin);
            }
        }

        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';

        return trim($value, '-');
    }

    /**
     * The application controller class FloCMS would use for a URL segment
     * (mirrors FloCMS\Core\App::buildControllerClassName()).
     */
    public static function appControllerClass(string $segment): string
    {
        $segment = str_replace(['-', '.'], '_', $segment);
        $segment = str_replace(' ', '', ucwords(str_replace('_', ' ', $segment)));

        return 'FloCMS\\Controllers\\' . $segment . 'Controller';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
