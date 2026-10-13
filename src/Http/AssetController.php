<?php
declare(strict_types=1);
namespace FloCMS\Admin\Http;

use FloCMS\Admin\Admin;
use FloCMS\Core\{Controller, HttpException};
use FloCMS\Core\Http\Response;

final class AssetController extends Controller
{
    public function asset(): Response
    {
        if (!$this->request->isMethod('GET', 'HEAD')) { return Response::text('Method not allowed.', 405)->header('Allow', 'GET, HEAD'); }
        $name = $this->params[0] ?? '';
        if (count($this->params) !== 1 || !in_array($name, ['admin-v' . Admin::VERSION . '.js', 'admin-v' . Admin::VERSION . '.css'], true)) { throw new HttpException(404); }
        $file = dirname(__DIR__, 2) . '/dist/' . $name;
        if (!is_file($file)) { throw new HttpException(404); }
        return Response::make((string) file_get_contents($file))
            ->header('Content-Type', str_ends_with($name, '.js') ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=31536000, immutable')->header('X-Content-Type-Options', 'nosniff');
    }
}
