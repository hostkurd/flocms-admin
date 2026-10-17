<?php
declare(strict_types=1);

namespace FloCMS\Admin\Search;

use FloCMS\Admin\Support\Icons;

/**
 * One line in Ctrl+K search (0.20): a title, the place it opens, and optionally a
 * detail line, an icon name or a thumbnail.
 *
 *     SearchResult::make('Upload a gallery', Admin::url('help/gallery'))->detail('Media')->icon('file');
 */
final class SearchResult
{
    private ?string $detail = null;
    private ?string $icon = null;
    private ?string $type = null;
    private ?string $thumbnail = null;

    private function __construct(private readonly string $title, private readonly string $url)
    {
    }

    public static function make(string $title, string $url): self
    {
        return new self($title, $url);
    }

    /** The second line: a city, a slug, an email. */
    public function detail(?string $detail): self
    {
        $this->detail = $detail;
        return $this;
    }

    /** An icon of the panel's set (Icons::svg names), shown when there is no thumbnail. */
    public function icon(?string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    /** A short word on the right: "Listing", "Settings". Groups set it for their results. */
    public function type(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /** A small image URL (media items). */
    public function thumbnail(?string $url): self
    {
        $this->thumbnail = $url;
        return $this;
    }

    public function getTitle(): string { return $this->title; }
    public function getUrl(): string { return $this->url; }
    public function getType(): ?string { return $this->type; }

    /**
     * Only a site path or an http(s) address is a link; anything else (javascript:,
     * data:) is refused, so a source can never put a script behind a result.
     */
    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1) { return null; }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) { return $url; }
        return preg_match('#^https?://[^/\s]#i', $url) === 1 ? $url : null;
    }

    /** @return array{title: string, detail: string, type: string, url: string, icon: string, thumb: string}|null */
    public function toArray(): ?array
    {
        $url = self::safeUrl($this->url);
        $title = self::clean($this->title, 200);
        if ($url === null || $title === '') { return null; }
        $thumb = $this->thumbnail !== null ? self::safeUrl($this->thumbnail) : null;
        return [
            'title' => $title,
            'detail' => self::clean((string) $this->detail, 200),
            'type' => self::clean((string) $this->type, 60),
            'url' => $url,
            'icon' => $thumb === null ? Icons::svg($this->icon ?? 'file', 'fa-icon') : '',
            'thumb' => $thumb ?? '',
        ];
    }

    /** One line of plain text: tags out, spaces folded, cut at $max characters. */
    public static function clean(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
