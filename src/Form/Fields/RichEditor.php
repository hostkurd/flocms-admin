<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\RichText;
use FloCMS\Admin\Support\Str;
use InvalidArgumentException;

/**
 * Formatted text (WYSIWYG), stored as clean HTML.
 *
 *   RichEditor::make('body')->translatable()
 *   RichEditor::make('body')->toolbar(['bold', 'italic', 'link', 'bulletList'])->images(false)
 *   RichEditor::make('body')->maxImageSize(4096)->maxVideoSize(300)->embeds(['youtube'])
 *
 * Images and videos are uploaded to the public media folder (config 'media');
 * YouTube and Vimeo videos are added from their link. Whatever the browser
 * sends, the value is cleaned on the server (RichText::clean()) before it is
 * validated and saved, so the stored HTML can be printed on a public page.
 */
final class RichEditor extends Field
{
    /** Toolbar buttons, in their default order. */
    public const BUTTONS = ['heading2', 'heading3', 'bold', 'italic', 'underline', 'strike', 'link',
        'bulletList', 'orderedList', 'blockquote', 'codeBlock', 'horizontalRule', 'image', 'video', 'embed', 'undo', 'redo'];

    /** @var list<string> */
    private array $toolbar = self::BUTTONS;
    private bool $images = true;
    private bool $videos = true;
    /** @var list<string> */
    private array $embeds = RichText::PROVIDERS;
    private int $maxImageBytes = 5 * 1024 * 1024;
    private int $maxVideoBytes = 200 * 1024 * 1024;
    private int $maxBytes = 262144;

    protected function setUp(): void
    {
        $this->columnSpan = 'full';
    }

    /** @param list<string> $buttons names from RichEditor::BUTTONS, in the order to show */
    public function toolbar(array $buttons): static
    {
        if ($buttons === [] || array_diff($buttons, self::BUTTONS) !== [] || count($buttons) !== count(array_unique($buttons))) {
            throw new InvalidArgumentException('Use toolbar buttons from RichEditor::BUTTONS: ' . implode(', ', self::BUTTONS) . '.');
        }
        $this->toolbar = array_values($buttons);
        return $this;
    }

    /** Allow images (uploaded or from a link). On by default. */
    public function images(bool $images = true): static
    {
        $this->images = $images;
        return $this;
    }

    /** Allow uploaded videos (mp4, webm). On by default. */
    public function videos(bool $videos = true): static
    {
        $this->videos = $videos;
        return $this;
    }

    /** Video sites that can be embedded from a link: 'youtube', 'vimeo'; [] or false for none. */
    public function embeds(array|bool $providers): static
    {
        $providers = $providers === true ? RichText::PROVIDERS : ($providers === false ? [] : $providers);
        if (array_diff($providers, RichText::PROVIDERS) !== []) {
            throw new InvalidArgumentException('Embeds can be: ' . implode(', ', RichText::PROVIDERS) . '.');
        }
        $this->embeds = array_values(array_unique($providers));
        return $this;
    }

    /** Largest image upload in KiB (default 5120, at most 16384). */
    public function maxImageSize(int $kib): static
    {
        if ($kib < 1 || $kib > 16384) { throw new InvalidArgumentException('Image size must be 1–16384 KiB.'); }
        $this->maxImageBytes = $kib * 1024;
        return $this;
    }

    /** Largest video upload in MiB (default 200, at most 2048). Videos upload in 1 MiB parts. */
    public function maxVideoSize(int $mib): static
    {
        if ($mib < 1 || $mib > 2048) { throw new InvalidArgumentException('Video size must be 1–2048 MiB.'); }
        $this->maxVideoBytes = $mib * 1024 * 1024;
        return $this;
    }

    /** Largest saved HTML per language in bytes (default 256 KiB, at most 1 MiB). */
    public function maxLength(int $bytes): static
    {
        if ($bytes < 1024 || $bytes > 1048576) { throw new InvalidArgumentException('Rich text length must be 1 KiB–1 MiB.'); }
        $this->maxBytes = $bytes;
        return $this;
    }

    public function supportsTranslation(): bool { return true; }

    public function isLong(): bool { return true; }

    /** Images are allowed when enabled and the toolbar has the Image button. */
    public function allowsImages(): bool { return $this->images && in_array('image', $this->toolbar, true); }

    public function allowsVideos(): bool { return $this->videos && in_array('video', $this->toolbar, true); }

    /** @return list<string> */
    public function getEmbeds(): array { return in_array('embed', $this->toolbar, true) ? $this->embeds : []; }

    public function getMaxImageBytes(): int { return $this->maxImageBytes; }

    public function getMaxVideoBytes(): int { return $this->maxVideoBytes; }

    /** Toolbar buttons, without those for media the field does not allow. */
    public function getToolbar(): array
    {
        return array_values(array_filter($this->toolbar, fn (string $button): bool => match ($button) {
            'image' => $this->allowsImages(), 'video' => $this->allowsVideos(), 'embed' => $this->getEmbeds() !== [], default => true,
        }));
    }

    public function clean(string $html): string
    {
        return RichText::clean($html, $this->allowsImages(), $this->allowsVideos(), $this->getEmbeds());
    }

    protected function normalizeInput(string $value): string
    {
        // Oversized input is reported by typeError(); do not spend time cleaning it.
        if (strlen($value) > $this->maxBytes * 4) { return str_repeat('x', $this->maxBytes + 1); }
        return $this->clean(str_replace("\r\n", "\n", $value));
    }

    protected function formatForInput(mixed $value): string
    {
        return is_string($value) ? $value : parent::formatForInput($value);
    }

    public function typeError(string $value): ?array
    {
        return strlen($value) > $this->maxBytes ? ['validation.rich_text_long', ['max' => (string) intdiv($this->maxBytes, 1024)]] : null;
    }

    /** The plain HTML source editor; the browser replaces it with the visual editor. */
    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        unset($attributes['data-rows']);
        $attrs = $this->baseAttributes($id, $name, $attributes) + ['class' => 'fa-input fa-textarea fa-rich-source', 'rows' => '12', 'data-rich-source' => true];
        return '<textarea' . self::attributes($attrs) . '>' . Str::e($value) . '</textarea>';
    }
}
