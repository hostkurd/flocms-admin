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
        if (in_array($name, ['admin-v' . Admin::VERSION . '.js', 'admin-v' . Admin::VERSION . '.css'], true)) {
            $file = $root . '/dist/' . $name;
            $type = str_ends_with($name, '.js') ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8';
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
