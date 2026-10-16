<?php
declare(strict_types=1);
// Isolated browser fixture. Never include this router in a deployed application.
if (getenv('FLO_ADMIN_TESTING') !== '1') { http_response_code(404); exit; }
$package = dirname(__DIR__, 2);
define('ROOT', sys_get_temp_dir() . '/flo-admin-browser-' . substr(hash('sha256', $package), 0, 12));
define('DS', DIRECTORY_SEPARATOR);
define('VIEWS_PATH', ROOT . '/views');
define('TEMPLATES_PATH', ROOT . '/templates');
define('SITE_URI', '');
foreach (['views', 'public', 'lang', 'templates/default/layouts'] as $dir) { if (!is_dir(ROOT . '/' . $dir)) mkdir(ROOT . '/' . $dir, 0700, true); }
file_put_contents(ROOT . '/public/conf_global.php', '<?php define("ACTIVE_LANG", \\FloCMS\\Core\\App::getRouter()->getLanguage());');
file_put_contents(TEMPLATES_PATH . '/default/layouts/admin.html', '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin</title></head><body><?= $data["content"] ?></body></html>');
$demoLabels = [
    'en' => ['demo.publish' => 'Publish', 'demo.unpublish' => 'Unpublish', 'demo.reason' => 'Reason', 'demo.published' => 'Post published.', 'demo.unpublished' => 'Post unpublished.', 'demo.announce' => 'Announcement', 'demo.ready' => 'Review pending drafts.', 'demo.help' => 'Publishing guide', 'demo.help_title' => 'Publishing guide', 'demo.help_body' => 'Publish a draft after reviewing its content.'],
    'ar' => ['demo.publish' => 'نشر', 'demo.unpublish' => 'إلغاء النشر', 'demo.reason' => 'السبب', 'demo.published' => 'تم نشر المقال.', 'demo.unpublished' => 'تم إلغاء نشر المقال.', 'demo.announce' => 'إعلان', 'demo.ready' => 'راجع المسودات.', 'demo.help' => 'دليل النشر', 'demo.help_title' => 'دليل النشر', 'demo.help_body' => 'انشر المسودة بعد مراجعة محتواها.'],
    'ku' => ['demo.publish' => 'بڵاوکردنەوە', 'demo.unpublish' => 'هەڵوەشاندنەوەی بڵاوکردنەوە', 'demo.reason' => 'هۆکار', 'demo.published' => 'بابەتەکە بڵاوکرایەوە.', 'demo.unpublished' => 'بڵاوکردنەوەی بابەتەکە هەڵوەشایەوە.', 'demo.announce' => 'ئاگاداری', 'demo.ready' => 'ڕەشنووسەکان بپشکنە.', 'demo.help' => 'ڕێنمایی بڵاوکردنەوە', 'demo.help_title' => 'ڕێنمایی بڵاوکردنەوە', 'demo.help_body' => 'دوای پشکنینی ناوەڕۆک ڕەشنووسەکە بڵاو بکەرەوە.'],
];
foreach ($demoLabels as $lang => $labels) { file_put_contents(ROOT . '/lang/' . $lang . '.php', '<?php return ' . var_export($labels, true) . ';'); }
require $package . '/vendor/autoload.php';
use FloCMS\Core\{App, Config, Csrf, Database, HttpException, Session};
use FloCMS\Core\Http\Response;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Tests\Fixtures\Actions\PostActionResource;
use FloCMS\Admin\Tests\Fixtures\Resources\TagResource;
use FloCMS\Admin\Tests\Fixtures\Resources\PropertyResource;
if (!is_dir(ROOT . '/sessions')) { mkdir(ROOT . '/sessions', 0700, true); }
session_save_path(ROOT . '/sessions');
session_start();
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_ENV['DEFAULT_LANG'] = 'en';
Config::set('routes', ['default' => '', 'admin' => 'admin_']);
Config::set('default_route', 'default');
Config::set('default_controller', 'pages');
Config::set('default_action', 'index');
Config::set('languages', ['en', 'ar', 'ku']);
Config::set('default_language', 'en');
Config::set('reactive.key', hash('sha256', __FILE__));
Config::set('permissions', [0 => [], 1 => ['content.posts.view'], 3 => ['*']]);
Config::set('roles', [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin']);
Config::set('auth.user_loader', static fn (int $id) => ['role' => Session::get('read_only') ? 1 : 3, 'status' => Session::get('expired') ? 3 : 1]);
Config::set('admin_access_roles', [1, 3]);
if (!Session::get('fixture')) {
    Session::set('fixture', true); Session::set('isloggedin', true); Session::set('admin_access', true);
    Session::set('user_id', 1); Session::set('role', 3); Session::set('fullname', 'Sara'); Session::set('email', 'sara@example.test');
}
$widgetDemo = (bool) Session::get('widget_demo');
$widgets = $widgetDemo ? [\FloCMS\Admin\Tests\Fixtures\Widgets\ContentOverview::class, \FloCMS\Admin\Tests\Fixtures\Widgets\RecentPosts::class, \FloCMS\Admin\Tests\Fixtures\Widgets\PublicationChart::class, \FloCMS\Admin\Tests\Fixtures\Widgets\ActivityChart::class] : [];
Admin::useConfig(['widgets' => $widgets, 'reactive' => !Session::get('widget_static'), 'resources' => array_merge($widgetDemo ? [\FloCMS\Admin\Tests\Fixtures\Widgets\WidgetPostResource::class] : [], [PostActionResource::class, TagResource::class, PropertyResource::class, \FloCMS\Admin\Tests\Fixtures\Resources\AssetResource::class, \FloCMS\Admin\Tests\Fixtures\Resources\ArticleResource::class, \FloCMS\Admin\Tests\Fixtures\Resources\ProjectResource::class]), 'brand' => 'FloCMS Studio', 'login_theme' => true, 'theme' => getenv('FLO_ADMIN_THEME') ?: 'light', 'primary_color' => getenv('FLO_ADMIN_PRIMARY') ?: null, 'sidebar' => getenv('FLO_ADMIN_SIDEBAR') ?: 'light', 'content_width' => getenv('FLO_ADMIN_WIDTH') ?: 'full', 'media' => ['root' => ROOT . '/public/uploads/editor', 'url' => '/uploads/editor']], true, ROOT);
$pdo = new PDO('sqlite:' . ROOT . '/' . hash('sha256', session_id()) . '.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
App::$db = new Database($pdo);
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='posts'")->fetchColumn()) {
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT UNIQUE, body TEXT, status TEXT, views INTEGER, featured INTEGER, published_on TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT, email TEXT UNIQUE, login TEXT, password TEXT, role INTEGER, status INTEGER, phone TEXT, is_verified INTEGER, verify_type INTEGER, token TEXT)');
    App::$db->table('users')->insert(['full_name'=>'Sara','email'=>'sara@example.test','login'=>'sara@example.test','password'=>password_hash('secret-pass-1', PASSWORD_DEFAULT),'role'=>3,'status'=>1]);
    App::$db->table('tags')->insert(['name'=>'Garden']);
    foreach (['Sea view villa', 'Mountain cabin', 'City apartment', 'Garden house', 'Lake house', 'Modern loft', 'Country cottage', 'Studio'] as $i => $title) {
        App::$db->table('posts')->insert(['title'=>json_encode(['en'=>$title,'ar'=>'منزل '.$i,'ku'=>'خانوو '.$i]),'slug'=>'post-'.($i+1),'body'=>json_encode(['en'=>'A place to call home.']),'status'=>'draft','views'=>10,'featured'=>1]);
    }
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='properties'")->fetchColumn()) {
    $pdo->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, description TEXT, country TEXT, city TEXT, listing_type TEXT, price DECIMAL(12,2), lease_months INTEGER, access_code TEXT, status TEXT, published_on DATE)');
    App::$db->table('properties')->insert(['title' => '{"en":"Villa"}', 'description' => '{"en":"Description"}', 'country' => 'iq', 'city' => 'sulaimani', 'listing_type' => 'sale', 'price' => 100, 'status' => 'draft']);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='articles'")->fetchColumn()) {
    $pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, body TEXT, note TEXT)');
    App::$db->table('articles')->insert(['title' => '{"en":"Welcome","ar":"أهلاً"}', 'body' => '{"en":"<p>Hello <strong>world</strong></p>","ar":"<p>مرحبا</p>"}', 'note' => null]);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='assets'")->fetchColumn()) {
    $pdo->exec('CREATE TABLE assets (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, kind TEXT, cover TEXT, attachment TEXT, internal TEXT, extra TEXT)');
    App::$db->table('assets')->insert(['title'=>'Villa media', 'kind'=>'normal']);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='projects'")->fetchColumn()) {
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT UNIQUE, summary TEXT, detail TEXT, meta_title TEXT, status TEXT, featured INTEGER, starts_on DATE, created_at TEXT, updated_at TEXT)');
    foreach (['Harbour bridge', 'City library', 'River park', 'Old town square', 'Solar farm', 'Airport terminal', 'Metro line'] as $i => $name) {
        App::$db->table('projects')->insert(['name' => json_encode(['en' => $name, 'ar' => 'مشروع ' . $i]), 'slug' => 'project-' . ($i + 1), 'summary' => 'Summary ' . ($i + 1), 'detail' => json_encode(['en' => '<p>Detail</p>']), 'status' => 'planned', 'featured' => 0]);
    }
}
// Editor media, as a web server would serve public/uploads (the public folder is the web root).
if (preg_match('~^/uploads/editor/([A-Za-z0-9/_.-]+)$~', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $media) === 1 && !str_contains($media[1], '..')) {
    $file = ROOT . '/public/uploads/editor/' . $media[1];
    if (!is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    readfile($file); exit;
}
if (str_starts_with($_SERVER['REQUEST_URI'], '/__test/')) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
        case '/__test/widgets': Session::set('widget_demo', true); Session::set('widget_static', ($_POST['static'] ?? '') === '1'); break;
        case '/__test/widget-add': App::$db->table('posts')->insert(['title'=>'{"en":"Fresh widget post"}', 'slug'=>'widget-'.bin2hex(random_bytes(4)), 'status'=>'published', 'featured'=>1, 'views'=>7]); break;
        case '/__test/readonly': Session::set('read_only', true); break;
        case '/__test/expire': Csrf::rotate(); break;
        case '/__test/asset-change': App::$db->table('assets')->where('id', '=', 1)->update(['title'=>(string) ($_POST['title'] ?? 'Changed elsewhere')]); break;
        case '/__test/change': App::$db->table('posts')->where('id', '=', (int) ($_POST['id'] ?? 1))->update(['title'=>'{"en":"Changed elsewhere"}']); break;
        case '/__test/delete': App::$db->table('posts')->where('id', '=', (int) ($_POST['id'] ?? 1))->delete(); break;
        default: http_response_code(404); exit;
    }
    http_response_code(204); exit;
}
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
if (preg_match('~^/(en|ar|ku)/__login-preview$~', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $match)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
    define('ACTIVE_LANG', $match[1]);
    \FloCMS\Admin\Support\AdminLang::setLanguage($match[1]);
    $session = $_SESSION; Session::set('isloggedin', false);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login preview</title></head><body>' . \FloCMS\Admin\Auth\LoginView::render() . '</body></html>';
    $_SESSION = $session; exit;
}
try { App::run($_SERVER['REQUEST_URI']); }
catch (HttpException $error) { Response::text($error->getMessage(), $error->status)->send(); }
