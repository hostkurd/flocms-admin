<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Blocks\BlockRegistry;
use FloCMS\Admin\Blocks\BlockRenderer;
use FloCMS\Admin\Blocks\Core\Contact;
use FloCMS\Admin\Blocks\Core\Faq;
use FloCMS\Admin\Blocks\Core\Hero;
use FloCMS\Admin\Blocks\Core\Stats;
use FloCMS\Admin\Blocks\Core\Team;
use FloCMS\Admin\Blocks\Core\Testimonials;
use FloCMS\Admin\Form\Fields\Builder;
use FloCMS\Admin\Tests\TestCase;

/** The blocks of 0.34 (Contact, FAQ, Testimonials, Stats, Team, Logos) and the Video, Hero and Gallery changes. */
final class BlocksGroupCTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BlockRenderer::reset(strtotime('2026-10-22 12:00'));
        @array_map('unlink', glob(Admin::root() . '/storage/cache/blocks/*') ?: []);
    }

    protected function tearDown(): void
    {
        BlockRenderer::reset();
        parent::tearDown();
    }

    private static function render(string $type, array $data, string $id = 'b1'): string
    {
        return blocks(json_encode([['id' => $id, 'type' => $type, 'data' => $data]]));
    }

    public function testEveryNewKindHasItsFormSampleAndPlace(): void
    {
        $field = Builder::make('content');
        $kinds = ['contact' => 'site', 'faq' => 'text', 'testimonials' => 'marketing', 'stats' => 'marketing', 'team' => 'marketing', 'logos' => 'marketing'];
        foreach ($kinds as $type => $category) {
            $block = BlockRegistry::find($type);
            self::assertNotNull($block, $type);
            self::assertSame($category, $block::category(), $type);
            self::assertTrue($field->hasContent($block), $type);
            self::assertContains('_bg', $field->settingNames($block), $type . ' takes the theme colours');
            if ($type !== 'logos') { self::assertNotSame([], $block->sample(), $type . ' has a sample'); }
            self::assertStringNotContainsString('blocks.', $block::label() . $block::description(), $type . ' has words');
        }
    }

    public function testContactLinksAndAMapThatLoadsOnClick(): void
    {
        $html = self::render('contact', BlockRegistry::find('contact')->sample());
        self::assertStringContainsString('<a class="flo-contact-link" href="tel:+9647500000000" dir="ltr">+964 750 000 0000</a>', $html);
        self::assertStringContainsString('href="mailto:hello@example.com"', $html);
        self::assertStringContainsString('href="https://wa.me/9647500000000"', $html);
        self::assertStringContainsString('href="https://www.google.com/maps/dir/?api=1&amp;destination=Erbil%20Citadel"', $html);
        self::assertStringContainsString('<dt>Sun–Thu</dt><dd>9:00–17:00</dd>', $html);
        // The map waits for a click: no iframe, its address in data-src
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringContainsString('data-flo-map data-src="https://www.google.com/maps?q=Erbil%20Citadel&amp;z=15&amp;output=embed"', $html);
        self::assertStringContainsString('Nothing is loaded from it until you click.', $html);
        // At once, or OpenStreetMap by coordinates
        self::assertStringContainsString('<iframe src="https://www.google.com/maps?q=', self::render('contact', ['address' => 'Main St 1', 'map_now' => '1'], 'b2'));
        $osm = (new Contact())->data(['map' => 'osm', 'location' => '36.19, 44.01', 'zoom' => '15']);
        self::assertStringStartsWith('https://www.openstreetmap.org/export/embed.html?bbox=', $osm['map_src']);
        self::assertStringContainsString('&marker=36.19,44.01', $osm['map_src']);
        self::assertSame('https://www.openstreetmap.org/?mlat=36.19&mlon=44.01#map=15/36.19/44.01', $osm['map_link']);
        // Checks
        $contact = new Contact();
        self::assertSame(['email' => 'Enter an email address such as name@example.com.', 'location' => 'OpenStreetMap needs coordinates, e.g. 36.19, 44.01.'], $contact->check(['email' => 'nope', 'map' => 'osm', 'location' => 'Erbil']));
        self::assertSame(['location' => 'Add an address, or the place on the map.'], $contact->check(['map' => 'google']));
        self::assertSame([], $contact->check(['map' => 'none']));
        self::assertNull(Contact::coordinates('91, 10'));
        $near = (new Contact())->data(['map' => 'osm', 'location' => '0.00001, -0.00001', 'zoom' => '15', 'address' => '   ', 'phone' => 'ask us', 'email' => 'a?b=c@x.io']);
        self::assertStringContainsString('&marker=0.00001,-0.00001', $near['map_src'], 'never 1.0E-5');
        self::assertSame(['', '', '', ''], [$near['address'], $near['tel'], $near['phone'], $near['mailto']]);
    }

    public function testFaqIsDetailsWithOneOpenAndSearchData(): void
    {
        $html = self::render('faq', BlockRegistry::find('faq')->sample(), 'bfaq');
        self::assertSame(3, substr_count($html, '<details class="flo-faq-item"'));
        self::assertStringContainsString('<details class="flo-faq-item" id="faq-bfaq-1" name="faq-bfaq" open>', $html);
        self::assertStringContainsString('<details class="flo-faq-item" id="faq-bfaq-2" name="faq-bfaq">', $html);
        self::assertStringContainsString('<p>Most sites take four to eight weeks, from the first meeting to launch.</p><p>We agree the dates with you before we start.</p>', $html);
        self::assertStringContainsString('<a href="mailto:hello@example.com">hello@example.com</a>', $html);
        self::assertStringContainsString('<script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage"', $html);
        // Answers are text: markup is escaped, links only for web and mail addresses, never </script> in the data
        self::assertSame('<p>&lt;b&gt;Hi&lt;/b&gt; see <a href="https://example.com/a?b=1&amp;c=2">https://example.com/a?b=1&amp;c=2</a>.<br>javascript:alert(1)</p>', Faq::answerHtml("<b>Hi</b> see https://example.com/a?b=1&c=2.\njavascript:alert(1)"));
        // Addresses in brackets or quotes (review of 0.34): the link stops before them
        self::assertSame('<p>&lt;<a href="https://a.com/x">https://a.com/x</a>&gt; and &quot;<a href="https://b.com/y">https://b.com/y</a>&quot;</p>', Faq::answerHtml('<https://a.com/x> and "https://b.com/y"'));
        self::assertSame('<p>(<a href="https://a.com">https://a.com</a>), <a href="mailto:me@x.io">me@x.io</a>.</p>', Faq::answerHtml('(https://a.com), me@x.io.'));
        $data = (new Faq())->data(['items' => [['question' => 'Q', 'answer' => '</script><script>alert(1)</script>']], 'schema' => '1']);
        self::assertStringNotContainsString('</script>', $data['schema_json']);
        $plain = self::render('faq', ['items' => [['question' => 'Q', 'answer' => 'A']], 'one_open' => '0', 'open_first' => '0', 'schema' => '0'], 'bplain');
        self::assertStringContainsString('<details class="flo-faq-item" id="faq-bplain-1">', $plain);
        self::assertStringNotContainsString('ld+json', $plain);
    }

    public function testTestimonialsInAGridACarouselOrOneQuote(): void
    {
        $sample = BlockRegistry::find('testimonials')->sample();
        $grid = self::render('testimonials', $sample);
        self::assertSame(3, substr_count($grid, '<figure class="flo-testimonial">'));
        self::assertStringContainsString('<p class="flo-stars" role="img" aria-label="Rated 4 out of 5">', $grid);
        self::assertStringContainsString('<span class="flo-avatar" aria-hidden="true">SA</span>', $grid);
        self::assertStringContainsString('flo-testimonials is-grid is-cols-3"', $grid, 'columns as a class, fine under a strict CSP');
        $carousel = self::render('testimonials', ['layout' => 'carousel'] + $sample, 'b2');
        self::assertStringContainsString('data-flo-slider data-autoplay="0" role="region" aria-roledescription="carousel" aria-label="What our clients say"', $carousel);
        self::assertSame(3, substr_count($carousel, 'aria-roledescription="slide"'));
        self::assertStringContainsString('data-flo-dot="2"', $carousel);
        $single = self::render('testimonials', ['layout' => 'single', 'columns' => '2'] + $sample, 'b3');
        self::assertStringContainsString('<div class="flo-testimonials is-single">', $single, 'no column class but for the grid');
        self::assertSame(1, substr_count($single, '<figure'));
        self::assertSame('KJ', Testimonials::initials(' karwan  jalal  x'));
        self::assertSame('سأ', Testimonials::initials('سارا أحمد'));
    }

    public function testStatsKeepTheNumberInTheHtml(): void
    {
        $html = self::render('stats', ['items' => [['number' => '1,250', 'suffix' => '+', 'label' => 'Projects'], ['number' => '98.5', 'prefix' => '$', 'label' => 'Price'], ['number' => 'lots', 'label' => 'Bad']], 'count_up' => '1']);
        self::assertStringContainsString('<dt class="flo-stat-label">Projects</dt>', $html);
        self::assertStringContainsString('<span data-flo-count="1250" data-decimals="0" data-flo-count-up>1250</span><span>+</span>', $html);
        self::assertStringContainsString('<span>$</span><span data-flo-count="98.5" data-decimals="1" data-flo-count-up>98.5</span>', $html);
        self::assertStringNotContainsString('Bad', $html, 'a number that is not one is left out');
        self::assertSame(['items.1.number' => 'Enter a number, e.g. 250 or 98.5.'], (new Stats())->check(['items' => json_encode([['number' => '12'], ['number' => '12abc']])]));
        self::assertStringNotContainsString('data-flo-count-up', self::render('stats', ['items' => [['number' => '3', 'label' => 'x']], 'count_up' => '0'], 'b2'));
        // A decimal comma, or commas between thousands
        self::assertSame(['98.5', '1250000', '1.25', null, null], array_map([Stats::class, 'number'], ['98,5', '1,250,000', '1,25', '1,2345', '12,50,0']));
    }

    public function testTeamLinksAreLabelledAndSafe(): void
    {
        $html = self::render('team', ['people' => [['name' => 'Sara Ahmed', 'role' => 'Designer', 'email' => 'sara@example.com', 'link' => 'https://www.linkedin.com/in/sara', 'link2' => 'javascript:alert(1)', 'link3' => 'https://example.com']]]);
        self::assertStringContainsString('aria-label="Email Sara Ahmed"', $html);
        self::assertStringContainsString('href="https://www.linkedin.com/in/sara" aria-label="Sara Ahmed on LinkedIn"', $html);
        self::assertStringContainsString('aria-label="Sara Ahmed on their website"', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<div class="flo-person-photo is-initials" aria-hidden="true">SA</div>', $html);
        self::assertSame(['kind' => 'x', 'name' => 'X'], Team::network('https://twitter.com/sara'));
        // Stored values never take the place of the computed links (review of 0.34); no empty or parameter-adding links
        $forged = self::render('team', ['people' => [['name' => 'A', 'phone' => 'call me', 'email' => 'x?cc=boss@evil.com', 'links' => [['url' => 'javascript:alert(1)', 'kind' => 'x', 'label' => 'l']]]]], 'b2');
        self::assertStringNotContainsString('javascript:', $forged);
        self::assertStringNotContainsString('tel:', $forged);
        self::assertStringNotContainsString('mailto:', $forged);
        self::assertStringContainsString('<h2 class="flo-person-name">A</h2>', $forged, 'without the block\'s heading, names are h2');
    }

    public function testHeroPositionReplacesAlignmentAndKeepsOldPages(): void
    {
        self::assertSame('bottom-start', Hero::position([]));
        self::assertSame('bottom-center', Hero::position(['align' => 'center']));
        self::assertSame('top-end', Hero::position(['position' => 'top-end', 'align' => 'center']));
        self::assertSame(['position' => 'bottom-center'], array_intersect_key((new Hero())->prepareState(['align' => 'center']), ['position' => 1]));
        $html = self::render('hero', ['heading' => 'Hi', 'position' => 'middle-end']);
        self::assertStringContainsString('flo-hero is-large is-pos-middle-end is-align-end', $html);
        self::assertStringContainsString('is-pos-bottom-center is-align-center', self::render('hero', ['heading' => 'Old', 'align' => 'center'], 'b2'));
    }

    public function testVideoLoadsWhenClickedInItsShape(): void
    {
        $html = self::render('video', ['url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'caption' => 'Our studio', 'aspect' => '9-16']);
        self::assertStringContainsString('<figure class="flo-video is-9-16">', $html);
        self::assertStringNotContainsString('<iframe', $html, 'nothing from YouTube before the click');
        self::assertStringContainsString('data-flo-video-embed data-src="https://www.youtube-nocookie.com/embed/abcdefghijk?autoplay=1&amp;rel=0" data-title="Our studio"', $html);
        self::assertStringContainsString('<a class="flo-video-play" href="https://www.youtube.com/watch?v=abcdefghijk" target="_blank" rel="noopener" aria-label="Play the video: Our studio">', $html);
        self::assertStringContainsString('Plays from YouTube', $html);
        // Autoplay loads at once, muted, as before
        self::assertStringContainsString('<iframe src="https://player.vimeo.com/video/123456?autoplay=1&amp;muted=1"', self::render('video', ['url' => 'https://vimeo.com/123456', 'autoplay' => '1'], 'b2'));
        self::assertContains('video', BlockRegistry::find('video')->assets());
    }
}
