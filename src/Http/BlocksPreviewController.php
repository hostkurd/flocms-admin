<?php
declare(strict_types=1);

namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Blocks\BlockColours;
use FloCMS\Admin\Blocks\BlockRegistry;
use FloCMS\Admin\Blocks\BlockStyles;
use FloCMS\Admin\Blocks\Core\Section;
use FloCMS\Admin\Pages\PagePreview;
use FloCMS\Admin\Pages\Pages;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\HttpException;

/**
 * Pages › Blocks preview (0.33), /admin/blocks-preview: every kind of block,
 * in each of its styles and theme colours, as the website shows them. The
 * blocks are a page preview (/<lang>/flo-preview/<token>, the site's layout
 * and stylesheets) in a frame whose width the toolbar changes; each one is
 * labelled with its selector. Permission settings.blocks_preview, so the
 * Admin role (settings.*) has it.
 */
final class BlocksPreviewController extends PanelController
{
    public const PERMISSION = 'settings.blocks_preview';

    protected array $actionPermissions = ['admin_index' => self::PERMISSION];

    public function admin_index(): string|\FloCMS\Core\Http\Response
    {
        if (!Pages::enabled()) { throw new HttpException(404); }
        if (!$this->request->isMethod('GET', 'HEAD')) { header('Allow: GET'); throw new HttpException(405); }
        $t = static fn (string $key, array $replace = []): string => AdminLang::get('blocks.' . $key, $replace);
        $kinds = BlockRegistry::all();
        $kind = $this->request->queryValue('block', '');
        $kind = is_string($kind) && isset($kinds[$kind]) ? $kind : '';
        $lang = $this->request->queryValue('lang', '');
        $lang = is_string($lang) && in_array($lang, Languages::all(), true) ? $lang : Languages::default();
        $variants = $this->request->queryValue('variants', '1') !== '0';

        $nodes = self::nodes($kind === '' ? $kinds : [$kind => $kinds[$kind]], $variants);
        $row = (object) ['id' => 0, 'is_home' => 0, 'parent_id' => null, 'type' => 'builder', 'template' => '', 'noindex' => 1,
            'title' => json_encode([$lang => $t('preview_title')], JSON_UNESCAPED_UNICODE), 'slug' => json_encode([$lang => 'blocks-preview']),
            'blocks' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        $frame = PagePreview::url(PagePreview::store($row, $lang), $lang);

        $options = '<option value="">' . Str::e($t('preview_all')) . '</option>';
        foreach ($kinds as $type => $block) {
            $options .= '<option value="' . Str::e($type) . '"' . ($type === $kind ? ' selected' : '') . '>' . Str::e($block::label()) . '</option>';
        }
        $languages = '';
        if (count(Languages::all()) > 1) {
            $languages = '<label class="fa-select-inline"><span class="fa-select-label">' . Str::e($t('preview_language')) . '</span><select name="lang" class="fa-input fa-select" data-admin-autosubmit>';
            foreach (Languages::all() as $code) { $languages .= '<option value="' . Str::e($code) . '"' . ($code === $lang ? ' selected' : '') . '>' . Str::e(Languages::name($code)) . '</option>'; }
            $languages .= '</select></label>';
        }
        $widths = '';
        foreach (['desktop' => 'monitor', 'tablet' => 'tablet', 'phone' => 'smartphone'] as $width => $icon) {
            $widths .= '<button type="button" class="fa-btn fa-btn-sm' . ($width === 'desktop' ? ' is-active' : '') . '" data-preview-width="' . $width . '" aria-pressed="' . ($width === 'desktop' ? 'true' : 'false') . '">'
                . Icons::svg($icon) . '<span>' . Str::e($t('preview_' . $width)) . '</span></button>';
        }

        $html = '<div class="fa-page-head"><div><h1 class="fa-page-title">' . Str::e($t('preview_title')) . '</h1><p class="fa-page-description">' . Str::e($t('preview_intro')) . '</p></div></div>'
            . '<div class="fa-card fa-blocks-preview" data-blocks-preview data-copied="' . Str::e($t('preview_copied')) . '" data-copy="' . Str::e($t('preview_copy')) . '">'
            . '<form class="fa-list-toolbar fa-blocks-preview-bar" method="get" action="' . Str::e(Admin::url('blocks-preview')) . '">'
            . '<label class="fa-select-inline"><span class="fa-select-label">' . Str::e($t('preview_block')) . '</span><select name="block" class="fa-input fa-select" data-admin-autosubmit>' . $options . '</select></label>'
            . $languages
            . '<input type="hidden" name="variants" value="0"><label class="fa-check-inline"><input type="checkbox" name="variants" value="1"' . ($variants ? ' checked' : '') . ' data-admin-autosubmit><span>' . Str::e($t('preview_variants')) . '</span></label>'
            . '<noscript><button type="submit" class="fa-btn fa-btn-sm">' . Str::e($t('preview_show')) . '</button></noscript>'
            . '<span class="fa-toolbar-spacer"></span>'
            . '<span class="fa-blocks-preview-widths" role="group" aria-label="' . Str::e($t('preview_width')) . '" data-preview-widths hidden>' . $widths . '</span>'
            . '<a class="fa-btn fa-btn-sm fa-btn-ghost" href="' . Str::e($frame) . '" target="_blank" rel="noopener">' . Icons::svg('external') . '<span>' . Str::e($t('preview_open')) . '</span></a>'
            . '</form>'
            . '<div class="fa-blocks-preview-stage"><iframe class="fa-blocks-preview-frame" src="' . Str::e($frame) . '" title="' . Str::e($t('preview_frame')) . '" data-preview-frame></iframe></div>'
            . '</div>';
        return $this->panel($t('preview_title'), $html, 'blocks-preview');
    }

    /**
     * The blocks of the preview: for each kind its sample, then (with variants)
     * once per style and, except for a Section, once per theme colour; each
     * after a label naming it and its selector.
     *
     * @param array<string, Block> $kinds
     * @return list<array<string, mixed>>
     */
    public static function nodes(array $kinds, bool $variants = true): array
    {
        $nodes = [];
        $n = 0;
        $t = static fn (string $key): string => AdminLang::get('blocks.' . $key);
        foreach ($kinds as $type => $block) {
            $sample = $block->sample();
            $base = '.flo-block-' . str_replace('_', '-', $type);
            if ($sample === []) {
                $nodes[] = self::label($block::label(), $base, ++$n, $t('preview_no_sample'));
                continue;
            }
            $looks = [[[], $t('preview_default'), $base]];
            if ($variants) {
                foreach (BlockStyles::for($block) as $name => $label) { $looks[] = [['_style' => $name], $label, $base . '.is-style-' . $name]; }
                if (!$block instanceof Section) {
                    foreach (BlockColours::all() as $name => $colour) { $looks[] = [['_bg' => $name], $colour['label'], $base . '.has-bg-' . $name]; }
                }
            }
            foreach ($looks as [$settings, $name, $selector]) {
                $nodes[] = self::label($block::label() . ' · ' . $name, $selector, ++$n);
                $nodes[] = self::node($type, $sample, $settings, ++$n);
            }
        }
        return $nodes;
    }

    /** @param array<string, mixed> $sample @param array<string, string> $settings @return array<string, mixed> */
    private static function node(string $type, array $sample, array $settings, int $n): array
    {
        $children = is_array($sample['children'] ?? null) ? $sample['children'] : [];
        unset($sample['children']);
        $node = ['id' => 'bpreview' . $n, 'type' => $type, 'data' => $sample, 'settings' => $settings];
        if ($children !== []) {
            $node['children'] = array_values(array_map(static fn (array $kid, int $i): array => ['id' => 'bpreview' . $n . 'c' . $i, 'type' => (string) ($kid['type'] ?? ''), 'data' => is_array($kid['data'] ?? null) ? $kid['data'] : []]
                + ((int) ($kid['column'] ?? 0) > 0 ? ['column' => (int) $kid['column']] : []), array_values(array_filter($children, 'is_array')), array_keys(array_values(array_filter($children, 'is_array')))));
        }
        return $node;
    }

    /** A label before a block: an HTML block the panel's script styles and gives a Copy button. */
    private static function label(string $name, string $selector, int $n, string $note = ''): array
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return ['id' => 'bpreview' . $n, 'type' => 'html', 'data' => ['html' => '<div data-flo-preview-label><strong>' . $e($name) . '</strong> <code data-flo-preview-selector>' . $e($selector) . '</code>'
            . ($note !== '' ? ' <em>' . $e($note) . '</em>' : '') . '</div>'], 'settings' => ['space_above' => 'large', 'space_below' => 'small']];
    }
}
