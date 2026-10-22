<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Media\MediaLibrary;

/**
 * Sample content for the blocks preview (0.33): the core blocks' samples
 * (Block::sample() of each kind), with the newest images of the media library
 * where a block shows pictures.
 */
final class BlockSamples
{
    /** @var array<string, list<string>> */
    private static array $media = [];

    /**
     * The ids of the newest images (or videos) in the media library, as blocks store them.
     *
     * @return list<string>
     */
    public static function media(int $count, string $kind = 'image'): array
    {
        if (!isset(self::$media[$kind])) {
            self::$media[$kind] = [];
            try {
                if (MediaLibrary::enabled()) {
                    self::$media[$kind] = array_map(static fn ($item): string => (string) $item->id, MediaLibrary::store()->newest(6, $kind));
                }
            } catch (\Throwable) {
                // No library, or no table yet: the blocks show without pictures.
            }
        }
        return array_slice(self::$media[$kind], 0, $count);
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$media = [];
    }

    /** @return array<string, mixed> */
    public static function for(string $type): array
    {
        $images = self::media(6);
        $cards = [
            ['title' => 'Strategy and design', 'text' => 'We listen first, then shape a plan you can act on.'],
            ['title' => 'Development', 'text' => 'Fast, accessible websites that are easy to update.'],
            ['title' => 'Support', 'text' => 'Updates, backups and help when you need it.'],
        ];
        return match ($type) {
            'heading' => ['text' => 'What we do', 'level' => 'h2', 'subtitle' => 'From the first sketch to the last line of code.'],
            'rich_text' => ['body' => '<p>We plan, design and build websites that are fast, clear and easy to update. <a href="#">Read more</a>.</p><ul><li>Strategy</li><li>Design</li><li>Development</li></ul>'],
            'cards' => ['heading' => 'Our services', 'columns' => '3', 'cards' => array_map(static fn (array $card, int $i): array => ['_key' => 'k' . $i] + (isset($images[$i]) ? ['media' => $images[$i]] : []) + $card, $cards, array_keys($cards))],
            'call_to_action' => ['heading' => 'Ready to start?', 'text' => 'Tell us about your project and we will get back to you.', 'button' => 'Contact us', 'link' => '#'],
            'hero' => ($images !== [] ? ['media' => $images[0]] : []) + ['heading' => 'Websites that work for you', 'text' => 'Design and development for growing teams.', 'button' => 'See our work', 'link' => '#', 'height' => 'medium'],
            'image' => $images !== [] ? ['media' => $images[0], 'caption' => 'A caption under the image.'] : [],
            'gallery' => count($images) >= 2 ? ['heading' => 'Gallery', 'images' => $images, 'columns' => '3'] : [],
            'slider' => count($images) >= 2 ? ['name' => 'Sample slider', 'height' => 'fixed', 'height_px' => '420', 'autoplay' => '0', 'slides' => array_map(static fn (string $id, int $i): array => ['_key' => 'k' . $i, 'media' => $id, 'heading' => $i === 0 ? 'Design that works' : 'Built to last', 'position' => 'bottom-start'], array_slice($images, 0, 3), array_keys(array_slice($images, 0, 3)))] : [],
            'video' => ($video = self::media(1, 'video')) !== [] ? ['video' => $video[0], 'caption' => 'A caption under the video.'] : [],
            'section' => ['name' => 'Sample section', 'background' => 'light', 'children' => [
                ['type' => 'heading', 'data' => ['text' => 'A section', 'level' => 'h2']],
                ['type' => 'rich_text', 'data' => ['body' => '<p>Blocks inside a section share its background and width.</p>']],
            ]],
            'columns' => ['layout' => '50-50', 'children' => [
                ['type' => 'rich_text', 'column' => 0, 'data' => ['body' => '<h3>First column</h3><p>Strategy and design.</p>']],
                ['type' => 'rich_text', 'column' => 1, 'data' => ['body' => '<h3>Second column</h3><p>Development and support.</p>']],
            ]],
            default => [],
        };
    }
}
