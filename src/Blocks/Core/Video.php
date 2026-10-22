<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks\Core;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Form\Fields\MediaPicker;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Support\AdminLang;

/** A video file from the media library, or a YouTube or Vimeo address (0.29). */
final class Video extends Block
{
    protected static string $type = 'video';
    protected static string $category = 'media';
    protected static string $icon = 'video';
    protected static ?string $label = 'blocks.video';
    protected static ?string $description = 'blocks.video_help';

    public function fields(): array
    {
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        return [
            MediaPicker::make('video')->label($t('video_file'))->videos()->help($t('video_file_help'))->columnSpan('full'),
            TextInput::make('url')->label($t('video_url'))->rules('max:300')->placeholder('https://www.youtube.com/watch?v=…')->help($t('video_url_help'))->columnSpan('full'),
            MediaPicker::make('poster')->label($t('video_poster'))->columnSpan('full'),
            TextInput::make('caption')->label($t('caption'))->rules('max:300')->columnSpan('full'),
            Toggle::make('autoplay')->label($t('video_autoplay'))->help($t('video_autoplay_help')),
            Toggle::make('loop')->label($t('video_loop')),
            // 0.34: the shape of a YouTube or Vimeo video (a file keeps its own).
            Select::make('aspect')->label($t('video_aspect'))->help($t('video_aspect_help'))->options(['16-9' => '16:9', '4-3' => '4:3', '1-1' => '1:1', '9-16' => '9:16', '21-9' => '21:9'])->default('16-9')->segmented()->columnSpan('full'),
        ];
    }

    /** A YouTube or Vimeo address as an embed address, or null. */
    public static function embedUrl(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $url, $m) === 1) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }
        if (preg_match('~^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{5,12})~', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        return null;
    }

    public function data(array $fields): array
    {
        $embed = self::embedUrl((string) ($fields['url'] ?? ''));
        // Autoplay in an embed needs sound off: YouTube's "mute", Vimeo's "muted" (0.32).
        $autoplay = $embed !== null && str_contains($embed, 'vimeo.com') ? '?autoplay=1&muted=1' : '?autoplay=1&mute=1';
        $url = trim((string) ($fields['url'] ?? ''));
        return ['embed' => $embed, 'autoplay_query' => $autoplay,
            // 0.34: a YouTube or Vimeo video loads only when clicked (unless it plays by itself).
            'provider' => $embed === null ? '' : (str_contains($embed, 'vimeo.com') ? 'Vimeo' : 'YouTube'),
            'watch' => $embed === null ? '' : \FloCMS\Admin\Blocks\BlockRenderer::safeUrl($url),
            'play_query' => $embed !== null && str_contains($embed, 'vimeo.com') ? '?autoplay=1' : '?autoplay=1&rel=0',
            'aspect' => in_array($fields['aspect'] ?? '', ['16-9', '4-3', '1-1', '9-16', '21-9'], true) ? (string) $fields['aspect'] : '16-9'];
    }

    public function assets(): array { return ['motion', 'video']; }

    /** A file or a YouTube or Vimeo address (0.32). */
    public function check(array $fields): array
    {
        $url = trim((string) ($fields['url'] ?? ''));
        if ($url !== '' && self::embedUrl($url) === null) { return ['url' => AdminLang::get('blocks.video_url_unknown')]; }
        if ($url === '' && trim((string) ($fields['video'] ?? '')) === '' ) { return ['video' => AdminLang::get('blocks.video_missing')]; }
        return [];
    }
}
