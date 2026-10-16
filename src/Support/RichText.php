<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/**
 * Cleans rich text HTML against a strict allow-list, so content saved from the
 * editor (or sent by anyone who bypasses it) is safe to print on a public page.
 *
 * Kept: paragraphs, h2–h4, bold/italic/underline/strike, links, lists, quotes,
 * code, rules, line breaks, images, videos and YouTube/Vimeo embeds. Everything
 * else is unwrapped (its text is kept) or, for scripts, styles, frames and form
 * controls, removed with its content. Every attribute is rebuilt: no event
 * handlers, no style or class, no unknown URL schemes. Embeds are re-created
 * from a validated provider and video ID; the submitted iframe is never kept.
 *
 *   echo RichText::clean($post->body);           // print stored content
 *   RichText::clean($html, images: false);       // a field without images
 */
final class RichText
{
    public const PROVIDERS = ['youtube', 'vimeo'];

    /** Elements kept as they are (attributes are rebuilt per element). */
    private const KEEP = ['p', 'h2', 'h3', 'h4', 'blockquote', 'ul', 'ol', 'li', 'pre', 'code', 'strong', 'em', 'u', 's', 'br', 'hr', 'a', 'img', 'video'];
    /** Older or equivalent markup mapped to the kept element. */
    private const RENAME = ['b' => 'strong', 'i' => 'em', 'del' => 's', 'strike' => 's', 'h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];
    /** Removed together with everything inside. */
    private const DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'template', 'noscript', 'svg', 'math',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'head', 'title', 'meta', 'link', 'base', 'audio', 'source', 'track', 'canvas', 'map', 'picture', 'dialog'];
    private const VOID = ['br', 'hr', 'img'];
    private const MAX_DEPTH = 48;

    private int $nodes = 0;

    /** @param list<string> $providers */
    private function __construct(private readonly bool $images, private readonly bool $videos, private readonly array $providers) {}

    /**
     * @param list<string> $embeds providers to keep: 'youtube', 'vimeo'
     */
    public static function clean(?string $html, bool $images = true, bool $videos = true, array $embeds = self::PROVIDERS): string
    {
        $html = (string) $html;
        if (trim($html) === '') { return ''; }
        if (!mb_check_encoding($html, 'UTF-8')) { $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8'); }
        $embeds = array_values(array_intersect(self::PROVIDERS, $embeds));
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            // The meta tag makes libxml read UTF-8; the wrapper collects the fragment.
            $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="flo-rich-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->getElementById('flo-rich-root');
        if ($root === null) { return ''; }
        $cleaner = new self($images, $videos, $embeds);
        // Editors keep an empty paragraph at the end; it is not content.
        $out = trim((string) preg_replace('~(?:<p>(?:<br>)?</p>)+$~', '', trim($cleaner->children($root, 0, false))));
        return self::isEmpty($out) ? '' : $out;
    }

    /** True when the HTML has no visible text and no image, video, embed or rule. */
    public static function isEmpty(?string $html): bool
    {
        $html = (string) $html;
        if (preg_match('/<(img|video|iframe|hr)\b/i', $html) === 1) { return false; }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace("\u{00A0}", ' ', $text)) === '';
    }

    /** Plain text for lists, excerpts and search results. */
    public static function plain(?string $html, int $limit = 0): string
    {
        $html = preg_replace('~<(br|/p|/h[2-4]|/li|/blockquote|/pre)\b[^>]*>~i', '$0 ', (string) $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
        return $limit > 0 && mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
    }

    /**
     * A YouTube or Vimeo address as an embed, or null when it is not one.
     *
     * @param list<string> $providers
     * @return array{provider: string, id: string, src: string, title: string}|null
     */
    public static function embed(string $url, array $providers = self::PROVIDERS): ?array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\\\\]/', $url) === 1) { return null; }
        if (!preg_match('~^https?://~i', $url)) { $url = 'https://' . $url; }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { return null; }
        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $fragment = (string) ($parts['fragment'] ?? '');

        if (in_array('youtube', $providers, true) && in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'], true)) {
            $id = null;
            if ($host === 'youtu.be') { $id = ltrim($path, '/'); }
            elseif ($path === '/watch') { $id = is_string($query['v'] ?? null) ? $query['v'] : null; }
            elseif (preg_match('~^/(?:embed|shorts|live|v)/([^/]+)/?$~', $path, $m) === 1) { $id = $m[1]; }
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) !== 1) { return null; }
            $start = self::seconds($query['t'] ?? $query['start'] ?? (str_starts_with($fragment, 't=') ? substr($fragment, 2) : null));
            return ['provider' => 'youtube', 'id' => $id . ($start > 0 ? '?start=' . $start : ''),
                'src' => 'https://www.youtube-nocookie.com/embed/' . $id . ($start > 0 ? '?start=' . $start : ''), 'title' => 'YouTube video'];
        }
        if (in_array('vimeo', $providers, true) && in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            $pattern = $host === 'player.vimeo.com' ? '~^/video/(\d{1,12})/?$~' : '~^/(?:channels/[A-Za-z0-9_-]+/|groups/[A-Za-z0-9_-]+/videos/)?(\d{1,12})(?:/([a-f0-9]{6,20}))?/?$~';
            if (preg_match($pattern, $path, $m) !== 1) { return null; }
            $hash = $m[2] ?? (is_string($query['h'] ?? null) ? $query['h'] : '');
            if ($hash !== '' && preg_match('/^[a-f0-9]{6,20}$/D', $hash) !== 1) { $hash = ''; }
            return ['provider' => 'vimeo', 'id' => $m[1] . ($hash !== '' ? '?h=' . $hash : ''),
                'src' => 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1' . ($hash !== '' ? '&h=' . $hash : ''), 'title' => 'Vimeo video'];
        }
        return null;
    }

    /** Canonical embed markup; the iframe is sandboxed and loads lazily. */
    public static function embedHtml(array $embed): string
    {
        return '<div data-embed="' . self::e($embed['provider']) . '" data-id="' . self::e($embed['id']) . '">'
            . '<iframe src="' . self::e($embed['src']) . '" title="' . self::e($embed['title']) . '" width="640" height="360" loading="lazy"'
            . ' allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen'
            . ' referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"></iframe></div>';
    }

    private function children(\DOMNode $parent, int $depth, bool $pre): string
    {
        $html = '';
        foreach ($parent->childNodes as $child) { $html .= $this->node($child, $depth, $pre); }
        return $html;
    }

    private function node(\DOMNode $node, int $depth, bool $pre): string
    {
        if (++$this->nodes > 50000) { return ''; }
        if ($node instanceof \DOMText) {
            $text = $node->data;
            return self::e($pre ? $text : (string) preg_replace('/[\r\n\t]+/', ' ', $text));
        }
        if (!$node instanceof \DOMElement) { return ''; }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP, true)) { return ''; }
        if ($tag === 'div' && $node->hasAttribute('data-embed')) { return $this->embedNode($node); }
        $tag = self::RENAME[$tag] ?? $tag;
        // Very deep nesting is flattened to its text.
        if ($depth >= self::MAX_DEPTH) { return self::e($node->textContent); }
        if (!in_array($tag, self::KEEP, true)) { return $this->children($node, $depth + 1, $pre); }
        if ($tag === 'img' && !$this->images) { return ''; }
        if ($tag === 'video') { return $this->videos ? $this->video($node) : ''; }

        $attributes = $this->attributes($tag, $node);
        if ($attributes === null) { return $this->children($node, $depth + 1, $pre); }
        if (in_array($tag, self::VOID, true)) { return '<' . $tag . $attributes . '>'; }
        $inner = $this->children($node, $depth + 1, $pre || $tag === 'pre');
        if ($inner === '' && in_array($tag, ['a', 'strong', 'em', 'u', 's', 'code', 'ul', 'ol', 'blockquote'], true)) { return ''; }
        return '<' . $tag . $attributes . '>' . $inner . '</' . $tag . '>';
    }

    /** @return string|null attribute HTML, or null to unwrap the element */
    private function attributes(string $tag, \DOMElement $node): ?string
    {
        switch ($tag) {
            case 'a':
                $href = self::url($node->getAttribute('href'), true);
                if ($href === null) { return null; }
                $html = ' href="' . self::e($href) . '"';
                if (strtolower($node->getAttribute('target')) === '_blank') { $html .= ' target="_blank" rel="noopener noreferrer"'; }
                return $html;
            case 'img':
                $src = self::url($node->getAttribute('src'), false);
                if ($src === null) { return null; }
                $html = ' src="' . self::e($src) . '" alt="' . self::e(mb_substr(trim($node->getAttribute('alt')), 0, 300)) . '"';
                foreach (['width', 'height'] as $name) {
                    $value = $node->getAttribute($name);
                    if (preg_match('/^[1-9]\d{0,3}$/D', $value) === 1) { $html .= ' ' . $name . '="' . $value . '"'; }
                }
                return $html . ' loading="lazy"';
            case 'ol':
                $start = $node->getAttribute('start');
                return preg_match('/^[1-9]\d{0,5}$/D', $start) === 1 && $start !== '1' ? ' start="' . $start . '"' : '';
            default:
                return '';
        }
    }

    private function video(\DOMElement $node): string
    {
        $src = $node->getAttribute('src');
        if ($src === '') {
            foreach ($node->getElementsByTagName('source') as $source) { $src = $source->getAttribute('src'); break; }
        }
        $src = self::url($src, false);
        if ($src === null || preg_match('~\.(mp4|webm)(?:[?#].*)?$~iD', $src) !== 1) { return ''; }
        return '<video src="' . self::e($src) . '" controls preload="metadata"></video>';
    }

    private function embedNode(\DOMElement $node): string
    {
        $provider = $node->getAttribute('data-embed');
        $id = $node->getAttribute('data-id');
        $url = match ($provider) {
            'youtube' => 'https://www.youtube.com/watch?v=' . (str_contains($id, '?start=') ? str_replace('?start=', '&t=', $id) : $id),
            'vimeo' => 'https://vimeo.com/' . (str_contains($id, '?h=') ? str_replace('?h=', '/', $id) : $id),
            default => '',
        };
        $embed = $url === '' ? null : self::embed($url, $this->providers);
        return $embed === null ? '' : self::embedHtml($embed);
    }

    private static function seconds(mixed $value): int
    {
        if (!is_string($value) || $value === '') { return 0; }
        if (preg_match('/^\d{1,6}s?$/D', $value) === 1) { return (int) $value; }
        if (preg_match('/^(?:(\d{1,2})h)?(?:(\d{1,2})m)?(?:(\d{1,2})s)?$/D', $value, $m) === 1) {
            return (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
        }
        return 0;
    }

    /** Allowed URL or null. Links may also use mailto:, tel: and #anchors. */
    private static function url(string $url, bool $link): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1) { return null; }
        if (str_starts_with($url, '//')) { return null; }
        if (str_starts_with($url, '/')) { return $url; }
        if ($link && str_starts_with($url, '#')) { return preg_match('/^#[A-Za-z0-9_\-:.]{1,100}$/D', $url) === 1 ? $url : null; }
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $url, $m) !== 1) { return null; }
        $scheme = strtolower($m[1]);
        if (in_array($scheme, ['http', 'https'], true)) {
            $parts = parse_url($url);
            return is_array($parts) && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) ? $url : null;
        }
        if ($link && $scheme === 'mailto') { return preg_match('/^mailto:[^\s<>"]{3,320}$/iD', $url) === 1 ? $url : null; }
        if ($link && $scheme === 'tel') { return preg_match('/^tel:\+?[0-9 ()\-.]{3,32}$/iD', $url) === 1 ? $url : null; }
        return null;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
