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
            // 0.34
            'contact' => ['heading' => 'Visit or call us', 'intro' => 'We are in the city centre, five minutes from the main square.', 'address' => "[Street and number]\n[City, postcode]",
                'phone' => '+964 750 000 0000', 'email' => 'hello@example.com', 'whatsapp' => '964 750 000 0000', 'map' => 'google', 'location' => 'Erbil Citadel',
                'hours' => [['_key' => 'k0', 'days' => 'Sun–Thu', 'time' => '9:00–17:00'], ['_key' => 'k1', 'days' => 'Saturday', 'time' => '10:00–14:00'], ['_key' => 'k2', 'days' => 'Friday', 'time' => 'Closed']]],
            'faq' => ['heading' => 'Questions we hear often', 'items' => [
                ['_key' => 'k0', 'question' => 'How long does a website take?', 'answer' => "Most sites take four to eight weeks, from the first meeting to launch.\n\nWe agree the dates with you before we start."],
                ['_key' => 'k1', 'question' => 'Can I update the site myself?', 'answer' => 'Yes. You edit pages, blocks and pictures in the panel, in every language of the site.'],
                ['_key' => 'k2', 'question' => 'What happens after launch?', 'answer' => 'We keep the site updated and backed up. Write to hello@example.com any time.'],
            ]],
            'testimonials' => ['heading' => 'What our clients say', 'columns' => '3', 'items' => [
                ['_key' => 'k0', 'quote' => 'Clear plan, fast site, and a team that answers the phone.', 'name' => 'Sara Ahmed', 'role' => 'Director, Northwind', 'rating' => '5'],
                ['_key' => 'k1', 'quote' => 'We update our pages ourselves now, in Arabic and English.', 'name' => 'Karwan Jalal', 'role' => 'Owner, Atlas Build', 'rating' => '5'],
                ['_key' => 'k2', 'quote' => 'Launched a week early. Our enquiries doubled in a month.', 'name' => 'Rania Mahmoud', 'role' => 'Marketing, Kalo', 'rating' => '4'],
            ]],
            'stats' => ['heading' => 'Ten years of work in numbers', 'items' => [
                ['_key' => 'k0', 'number' => '250', 'suffix' => '+', 'label' => 'Websites launched'], ['_key' => 'k1', 'number' => '98', 'suffix' => '%', 'label' => 'Clients who come back'],
                ['_key' => 'k2', 'number' => '12', 'label' => 'People on the team'], ['_key' => 'k3', 'number' => '3', 'label' => 'Languages we build in'],
            ]],
            'team' => ['heading' => 'The people behind the work', 'people' => array_map(static fn (array $p, int $i): array => ['_key' => 'k' . $i] + (isset($images[$i]) ? ['photo' => $images[$i]] : []) + $p, [
                ['name' => 'Sara Ahmed', 'role' => 'Founder and designer', 'bio' => 'Plans each site with the client, from the first sketch.', 'email' => 'sara@example.com', 'link' => 'https://www.linkedin.com/in/example'],
                ['name' => 'Karwan Jalal', 'role' => 'Lead developer', 'bio' => 'Builds fast, accessible sites on FloCMS.', 'link' => 'https://github.com/example'],
                ['name' => 'Rania Mahmoud', 'role' => 'Project manager', 'bio' => 'Keeps dates and people in step.'],
                ['name' => 'Lana Hassan', 'role' => 'Content and SEO', 'bio' => 'Writes and translates the words on the page.'],
            ], [0, 1, 2, 3])],
            'logos' => count($images) >= 2 ? ['heading' => 'Trusted by teams across the region', 'logos' => array_map(static fn (string $id, int $i): array => ['_key' => 'k' . $i, 'image' => $id, 'name' => 'Partner ' . ($i + 1)], $images, array_keys($images))] : [],
            default => [],
        };
    }
}
