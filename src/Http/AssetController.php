<?php
declare(strict_types=1);
namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Core\{Controller, HttpException};
use FloCMS\Core\Http\Response;

final class AssetController extends Controller
{
    /** Shipped fonts. A changed font gets a new file name (-v2), never new bytes under an old one. */
    public const FONTS = ['instrument-sans-latin-v1.woff2', 'instrument-sans-latin-ext-v1.woff2', 'noto-sans-arabic-v1.woff2'];

    public function asset(): Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { return Response::text('Method not allowed.', 405)->header('Allow', 'GET, HEAD'); }
        $name = $this->params[0] ?? '';
        if (count($this->params) !== 1 || !is_string($name)) { throw new HttpException(404); }
        $root = dirname(__DIR__, 2);
        if (in_array($name, ['admin-v' . Admin::VERSION . '.js', 'admin-v' . Admin::VERSION . '.css', 'admin-editor-v' . Admin::VERSION . '.js'], true)) {
            $file = $root . '/dist/' . $name;
            $type = str_ends_with($name, '.js') ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8';
        } elseif ($name === 'rich-text-v' . Admin::VERSION . '.css') {
            // Presentation of rich text on the website (widths, positions, captions, alignment).
            $file = $root . '/resources/assets/rich-text.css';
            $type = 'text/css; charset=utf-8';
        } elseif (($theme = \FloCMS\Admin\Support\Theme::fileName()) !== null && $name === $theme) {
            // Only the stylesheet for the currently configured colour exists.
            return Response::make(\FloCMS\Admin\Support\Theme::css((string) \FloCMS\Admin\Support\Appearance::primaryColor()))
                ->header('Content-Type', 'text/css; charset=utf-8')
                ->header('Cache-Control', 'public, max-age=31536000, immutable')->header('X-Content-Type-Options', 'nosniff');
        } elseif (preg_match('/^blocks-([a-z][a-z0-9-]{0,40})-v' . preg_quote(Admin::VERSION, '/') . '\.(css|js)$/D', $name, $block) === 1 && is_file($root . '/resources/site/' . $block[1] . '.' . $block[2])) {
            // The blocks' own styles and scripts (0.30), until the site copies them (php flo admin:blocks).
            $file = $root . '/resources/site/' . $block[1] . '.' . $block[2];
            $type = $block[2] === 'js' ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8';
        } elseif (preg_match('/^block-styles-([a-f0-9]{24})\.css$/D', $name, $styles) === 1) {
            // The blocks' own CSS and theme colours on a page (0.33): written by BlockCss, named by its content.
            $file = \FloCMS\Admin\Blocks\BlockCss::path($styles[1]) ?? '';
            $type = 'text/css; charset=utf-8';
        } elseif (in_array($name, self::FONTS, true)) {
            // Fonts sit next to the stylesheet's URL, so its url() references stay relative.
            $file = $root . '/resources/fonts/' . $name;
            $type = 'font/woff2';
        } else {
            throw new HttpException(404);
        }
        if (!is_file($file)) { throw new HttpException(404); }
        return Response::make((string) file_get_contents($file))
            ->header('Content-Type', $type)
            ->header('Cache-Control', 'public, max-age=31536000, immutable')->header('X-Content-Type-Options', 'nosniff');
    }
}
