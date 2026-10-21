<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Blocks\BlockRenderer;
use FloCMS\Admin\Blocks\BlockSettings;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\Http\Response;

/**
 * One page of the website in one language (0.30), as a template uses it:
 *
 *     <h1>{{ $page->title() }}</h1>
 *     {!! $page->content() !!}
 */
final class Page
{
    public function __construct(private readonly object $row, private readonly string $lang, private readonly bool $preview = false)
    {
    }

    public function id(): int { return (int) ($this->row->id ?? 0); }
    public function lang(): string { return $this->lang; }
    public function row(): object { return $this->row; }
    public function isPreview(): bool { return $this->preview; }
    public function isHome(): bool { return (int) ($this->row->is_home ?? 0) === 1; }
    public function type(): string { return in_array($this->row->type ?? '', ['builder', 'html', 'rich_text'], true) ? (string) $this->row->type : 'builder'; }

    /** Whether this language shows the default language's page (it has no translation of its own). */
    public function isFallback(): bool
    {
        return !Pages::isTranslated($this->row, $this->lang);
    }

    /** The language the words come from: this one, or the default one standing in. */
    public function contentLang(): string
    {
        return $this->isFallback() ? Languages::default() : $this->lang;
    }

    private function words(string $column): string
    {
        return Translations::get($this->row->{$column} ?? '', $this->contentLang());
    }

    public function title(): string { return $this->words('title'); }
    public function metaTitle(): string { $title = trim($this->words('meta_title')); return $title !== '' ? $title : $this->title(); }
    public function metaDescription(): string { return trim($this->words('meta_description')); }
    public function noindex(): bool { return (int) ($this->row->noindex ?? 0) === 1 || $this->preview; }
    public function template(): string { return Templates::name((string) ($this->row->template ?? '')); }
    public function cssClass(): string { return BlockSettings::classes((string) ($this->row->css_class ?? '')); }

    public function url(): string
    {
        return Pages::url($this->row, $this->lang);
    }

    /** The social image's address, or ''. */
    public function socialImage(): string
    {
        $item = function_exists('media') ? media($this->row->social_image ?? null) : null;
        return $item !== null && $item->isImage() ? $item->url() : '';
    }

    /** The page's content as HTML, whichever kind it is (blocks, HTML or rich text). */
    public function content(): string
    {
        return match ($this->type()) {
            'html' => $this->words('html'),
            'rich_text' => ($body = $this->words('body')) === '' ? '' : '<div class="flo-rich-text">' . $body . '</div>',
            default => BlockRenderer::render($this->row->blocks ?? '', $this->contentLang()),
        };
    }

    /**
     * The tags for the layout's <head>: title, description, canonical, the
     * other languages (hreflang), Open Graph and robots.
     */
    public function meta(): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $title = $this->metaTitle();
        $description = $this->metaDescription();
        $url = $this->url();
        $html = '<title>' . $e($title) . '</title>' . "\n";
        if ($description !== '') { $html .= '<meta name="description" content="' . $e($description) . '">' . "\n"; }
        // A language without its own page points search engines at the default language's.
        $canonical = $this->isFallback() ? Pages::url($this->row, Languages::default()) : $url;
        if (!$this->preview) { $html .= '<link rel="canonical" href="' . $e($canonical) . '">' . "\n"; }
        $alternates = Pages::alternates($this->row);
        if (count($alternates) > 1) {
            foreach ($alternates as $lang => $href) { $html .= '<link rel="alternate" hreflang="' . $e($lang) . '" href="' . $e($href) . '">' . "\n"; }
            if (isset($alternates[Languages::default()])) { $html .= '<link rel="alternate" hreflang="x-default" href="' . $e($alternates[Languages::default()]) . '">' . "\n"; }
        }
        $html .= '<meta property="og:type" content="website">' . "\n"
            . '<meta property="og:title" content="' . $e($title) . '">' . "\n"
            . '<meta property="og:url" content="' . $e($canonical) . '">' . "\n";
        if ($description !== '') { $html .= '<meta property="og:description" content="' . $e($description) . '">' . "\n"; }
        if (($image = $this->socialImage()) !== '') {
            $html .= '<meta property="og:image" content="' . $e($image) . '">' . "\n" . '<meta name="twitter:card" content="summary_large_image">' . "\n";
        }
        if ($this->noindex()) { $html .= '<meta name="robots" content="noindex">' . "\n"; }
        return $html;
    }

    /** The whole page as a response: its template inside the site's layout. */
    public function response(int $status = 200): Response
    {
        Pages::setCurrent($this);
        $response = Response::html(PageRenderer::render($this), $status);
        if ($this->preview) {
            $response = $response->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
        return $response;
    }
}
