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
// The website's layout, as a site has it: page_meta() and blocks_assets() in the head, pages_menu() in the header (0.30).
file_put_contents(TEMPLATES_PATH . '/default/layouts/default.html', '<!doctype html><html lang="<?= ACTIVE_LANG ?>" dir="<?= ACTIVE_LANG === "en" ? "ltr" : "rtl" ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?= page_meta() ?><?= blocks_assets() ?><style>body{margin:0;font-family:system-ui,sans-serif;color:#101828}.site-head{display:flex;gap:18px;align-items:center;padding:16px 24px;border-bottom:1px solid #e4e7ec}.site-head a{color:#101828;text-decoration:none}.site-head a[aria-current]{font-weight:700}</style></head><body><header class="site-head"><strong>Your Company</strong><?php foreach (pages_menu() as $item): ?><a href="<?= htmlspecialchars($item["url"]) ?>"<?= $item["current"] ? " aria-current=\"page\"" : "" ?>><?= htmlspecialchars($item["title"]) ?></a><?php endforeach; ?></header><?= $data["content"] ?></body></html>');
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
// Forgot password (0.26): emails go to ROOT/storage/logs/admin-mail.log
$_ENV['MAIL_TRANSPORT'] = 'log';
$_ENV['APP_KEY'] = $_ENV['APP_KEY'] ?? 'browser-test-key-0123456789abcdef';
$_ENV['MAIL_FROM'] = 'panel@example.test';
$_ENV['APP_URL'] = 'http://127.0.0.1:8083';
Config::set('routes', ['default' => '', 'admin' => 'admin_']);
Config::set('default_route', 'default');
Config::set('default_controller', 'pages');
Config::set('default_action', 'index');
Config::set('languages', ['en', 'ar', 'ku']);
Config::set('default_language', 'en');
Config::set('reactive.key', hash('sha256', __FILE__));
Config::set('permissions', [0 => [], 1 => ['content.posts.view'], 3 => ['*'], 4 => ['content.listings.*', 'content.media.view']]);
Config::set('roles', [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin', 4 => 'Writer']);
Config::set('auth.user_loader', static fn (int $id) => Session::get('security_demo') && App::$db !== null
    // Login security (0.35): the real row, so the sign-in steps see the account.
    ? App::$db->table('users')->where('id', '=', $id)->first()
    : ['role' => Session::get('read_only') ? 1 : (Session::get('writer') ? 4 : 3), 'status' => Session::get('expired') ? 3 : 1]);
Config::set('admin_access_roles', [1, 3, 4]);
if (!Session::get('fixture')) {
    Session::set('fixture', true); Session::set('isloggedin', true); Session::set('admin_access', true);
    Session::set('user_id', 1); Session::set('role', 3); Session::set('fullname', 'Sara'); Session::set('email', 'sara@example.test');
}
$widgetDemo = (bool) Session::get('widget_demo');
$widgets = $widgetDemo ? [\FloCMS\Admin\Tests\Fixtures\Widgets\ContentOverview::class, \FloCMS\Admin\Tests\Fixtures\Widgets\RecentPosts::class, \FloCMS\Admin\Tests\Fixtures\Widgets\PublicationChart::class, \FloCMS\Admin\Tests\Fixtures\Widgets\ActivityChart::class] : [];
Admin::useConfig(['widgets' => $widgets, 'reactive' => !Session::get('widget_static'), 'resources' => array_merge($widgetDemo ? [\FloCMS\Admin\Tests\Fixtures\Widgets\WidgetPostResource::class] : [], [PostActionResource::class, TagResource::class, PropertyResource::class, \FloCMS\Admin\Tests\Fixtures\Resources\AssetResource::class, \FloCMS\Admin\Tests\Fixtures\Resources\ArticleResource::class, \FloCMS\Admin\Tests\Fixtures\Resources\ProjectResource::class, \FloCMS\Admin\Tests\Fixtures\Relations\HomeResource::class, \FloCMS\Admin\Tests\Fixtures\Relations\HomeDialogResource::class, \FloCMS\Admin\Tests\Fixtures\Relations\CityResource::class, \FloCMS\Admin\Tests\Fixtures\Relations\CountryResource::class, \FloCMS\Admin\Tests\Fixtures\Relations\FeatureResource::class, \FloCMS\Admin\Tests\Fixtures\Reorder\FaqResource::class, \FloCMS\Admin\Tests\Fixtures\Media\ListingResource::class, \FloCMS\Admin\Tests\Fixtures\Managers\EstateResource::class, \FloCMS\Admin\Tests\Fixtures\Managers\UnitResource::class, \FloCMS\Admin\Tests\Fixtures\Managers\EnquiryResource::class, \FloCMS\Admin\Tests\Fixtures\Translations\VillaResource::class, \FloCMS\Admin\Tests\Fixtures\Translations\RoomResource::class, \FloCMS\Admin\Tests\Fixtures\Repeaters\ApartmentResource::class, \FloCMS\Admin\Tests\Fixtures\Repeaters\ApartmentDialogResource::class, \FloCMS\Admin\Tests\Fixtures\Choices\HouseResource::class, \FloCMS\Admin\Tests\Fixtures\Choices\HouseDialogResource::class, \FloCMS\Admin\Tests\Fixtures\Builder\LandingResource::class, \FloCMS\Admin\Tests\Fixtures\ImportExport\ProductResource::class]), 'pages' => (bool) Session::get('pages_demo'), 'admin_pages' => Session::get('custom_pages_demo') ? [\FloCMS\Admin\Tests\Fixtures\PanelPages\SalesReport::class, \FloCMS\Admin\Tests\Fixtures\PanelPages\ExchangeRates::class, \FloCMS\Admin\Tests\Fixtures\PanelPages\GettingStarted::class, \FloCMS\Admin\Tests\Fixtures\PanelPages\MissingTable::class] : [], 'two_factor' => (bool) Session::get('security_demo'), 'email_verification' => (bool) Session::get('security_demo'), 'block_styles' => ['heading' => ['underlined' => 'Underlined', 'eyebrow' => ['en' => 'With eyebrow', 'ar' => 'مع عنوان صغير']], 'cards' => ['shadow' => 'With shadow']], 'brand' => 'FloCMS Studio', 'login_theme' => true, 'password_reset' => true, 'theme' => getenv('FLO_ADMIN_THEME') ?: 'light', 'primary_color' => getenv('FLO_ADMIN_PRIMARY') ?: null, 'sidebar' => getenv('FLO_ADMIN_SIDEBAR') ?: 'light', 'content_width' => getenv('FLO_ADMIN_WIDTH') ?: 'full', 'media' => ['root' => ROOT . '/public/uploads/editor', 'url' => '/uploads/editor'], 'settings_path' => $package . '/tests/Fixtures/Settings/settings.php', 'settings_uploads' => ['root' => ROOT . '/public/uploads/settings', 'url' => '/uploads/settings']], true, ROOT);
// One database per browser session, kept when the session id changes (a held sign-in's release gives a new id, 0.35).
if (!is_string($_SESSION['_test_db'] ?? null)) { $_SESSION['_test_db'] = hash('sha256', session_id()); }
$pdo = new PDO('sqlite:' . ROOT . '/' . $_SESSION['_test_db'] . '.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='faqs'")->fetchColumn()) {
    // Reorder mode: every position starts at 0; 40 rows so dragging has to scroll on a small screen.
    $pdo->exec('CREATE TABLE faqs (id INTEGER PRIMARY KEY AUTOINCREMENT, question TEXT, category TEXT, sort INTEGER NOT NULL DEFAULT 0, archived INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)');
    $faqs = (int) (Session::get('faq_count') ?: 5);
    $names = ['Where is my parcel?' => 'أين طردي؟', 'How do I pay?' => 'كيف أدفع؟', 'Can I get a refund?' => 'هل يمكنني استرداد المال؟', 'Do you ship abroad?' => 'هل تشحنون للخارج؟', 'Is VAT included?' => 'هل الضريبة مشمولة؟'];
    $keys = array_keys($names);
    for ($i = 0; $i < $faqs; $i++) {
        $en = $keys[$i] ?? 'Question ' . ($i + 1);
        App::$db->table('faqs')->insert(['question' => json_encode(['en' => $en, 'ar' => $names[$en] ?? 'سؤال ' . ($i + 1)], JSON_UNESCAPED_UNICODE), 'category' => $i % 2 === 0 ? 'shipping' : 'billing', 'sort' => 0, 'archived' => 0]);
    }
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='listings'")->fetchColumn()) {
    $pdo->exec('CREATE TABLE listings (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, cover_id INTEGER NULL, gallery TEXT NULL, video_id INTEGER NULL, body TEXT NULL)');
    App::$db->table('listings')->insert(['title' => 'Villa', 'body' => '{"en":"<p>Welcome</p>"}']);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='settings'")->fetchColumn()) {
    $pdo->exec('CREATE TABLE settings (`param` VARCHAR(190) NOT NULL, `value` TEXT NULL, `lang` VARCHAR(10) NOT NULL DEFAULT \'\', PRIMARY KEY (`param`, `lang`))');
    $pdo->exec('CREATE TABLE admin_user_preferences (`user_id` INTEGER NOT NULL, `key` VARCHAR(100) NOT NULL, `value` TEXT NULL, `updated_at` DATETIME NULL, PRIMARY KEY (`user_id`, `key`))');
}
// Each browser session has its own database, so the shared settings cache is rebuilt on every request.
@unlink(ROOT . '/storage/cache/flocms-admin-settings.php');
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='homes'")->fetchColumn()) {
    $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    $pdo->exec('CREATE TABLE cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, country_id INTEGER, archived INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE features (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    $pdo->exec('CREATE TABLE agents (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT)');
    $pdo->exec('CREATE TABLE homes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, country_id INTEGER, city_id INTEGER, agent_id INTEGER)');
    $pdo->exec('CREATE TABLE home_feature (home_id INTEGER NOT NULL, feature_id INTEGER NOT NULL, sort INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (home_id, feature_id))');
    $pdo->exec("INSERT INTO countries (name) VALUES ('Iraq'), ('Greece')");
    $pdo->exec("INSERT INTO cities (name, country_id) VALUES ('{\"en\":\"Erbil\",\"ar\":\"أربيل\",\"ku\":\"هەولێر\"}', 1), ('{\"en\":\"Sulaimani\",\"ku\":\"سلێمانی\"}', 1), ('{\"en\":\"Athens\"}', 2), ('{\"en\":\"Thessaloniki\"}', 2)");
    // Many agents: their control searches the server instead of listing everyone
    $insert = $pdo->prepare('INSERT INTO agents (full_name) VALUES (?)');
    foreach (['Dara', 'Lana', 'Sara', 'Rebin', 'Shilan', 'Aram', 'Nyan', 'Hemin'] as $first) { foreach (range(1, 10) as $n) { $insert->execute([$first . ' Agent ' . $n]); } }
    foreach (['Pool', 'Garden', 'Sea view', 'Parking', 'Balcony', 'Fireplace'] as $feature) { $pdo->prepare('INSERT INTO features (name) VALUES (?)')->execute([json_encode(['en' => $feature])]); }
    $pdo->exec("INSERT INTO homes (title, country_id, city_id, agent_id) VALUES ('Villa', 1, 1, 3), ('Flat', 2, 3, NULL)");
    $pdo->exec("INSERT INTO home_feature (home_id, feature_id, sort) VALUES (1, 2, 1), (1, 1, 2), (2, 4, 1)");
}
// Relation managers (0.22): estates with units and enquiries
$seedProducts = static function (PDO $pdo): void {
    // CSV export and import (0.25): products with every importable kind of field, and the import tables.
    $pdo->exec('DROP TABLE IF EXISTS product_feature');
    $pdo->exec('DROP TABLE IF EXISTS products');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, title TEXT, summary TEXT, category TEXT, price DECIMAL(12,2), in_stock INTEGER NOT NULL DEFAULT 0, city_id INTEGER NULL, released_on DATE NULL)');
    $pdo->exec('CREATE TABLE product_feature (product_id INTEGER NOT NULL, feature_id INTEGER NOT NULL, PRIMARY KEY (product_id, feature_id))');
    $rows = [['P-100', ['en' => 'Garden hose', 'ar' => 'خرطوم حديقة'], 'garden', 19.5, 1, 1, '2026-03-01'], ['P-101', ['en' => 'Hammer'], 'tools', 12, 1, 2, null], ['P-102', ['en' => '=Danger, "quoted"'], 'kitchen', null, 0, null, null]];
    foreach ($rows as [$code, $title, $category, $price, $stock, $city, $released]) {
        $pdo->prepare('INSERT INTO products (code, title, category, price, in_stock, city_id, released_on) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$code, json_encode($title, JSON_UNESCAPED_UNICODE), $category, $price, $stock, $city, $released]);
    }
    $pdo->exec('INSERT INTO product_feature (product_id, feature_id) VALUES (1, 1), (1, 2)');
    $pdo->exec('DROP TABLE IF EXISTS admin_import_rows');
    $pdo->exec('DROP TABLE IF EXISTS admin_imports');
    $file = ROOT . '/import-migration.php';
    file_put_contents($file, \FloCMS\Admin\Console\Stub::render('import-migration', ['imports_table' => 'admin_imports', 'rows_table' => 'admin_import_rows']));
    (require $file)->up(App::$db);
};
$seedManagers = static function (PDO $pdo): void {
    foreach (['units', 'enquiries', 'estates'] as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
    $pdo->exec('CREATE TABLE estates (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT "")');
    $pdo->exec('CREATE TABLE units (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT "", bedrooms TEXT NULL, floor TEXT NULL, estate_id INTEGER NULL, archived INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE enquiries (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT "", email TEXT NOT NULL DEFAULT "", estate_id INTEGER NULL)');
    $pdo->exec("INSERT INTO estates (title) VALUES ('Bay view villa'), ('Harbour flats'), ('Old town house')");
    $pdo->exec("INSERT INTO units (name, bedrooms, estate_id) VALUES ('Flat 1', '2', 1), ('Flat 2', '1', 1), ('Flat 3', '3', 1), ('Flat 4', '2', 1), ('Penthouse', '4', 1), ('Harbour A', '2', 2)");
    $pdo->exec("INSERT INTO enquiries (name, email, estate_id) VALUES ('Omar Haddad', 'omar@example.com', 2)");
};
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='estates'")->fetchColumn()) { $seedManagers($pdo); }
// Page-level translation (0.23): villas with rooms, partly translated
$seedVillas = static function (PDO $pdo): void {
    foreach (['rooms', 'villas'] as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
    $pdo->exec('CREATE TABLE villas (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, summary TEXT, description TEXT, price TEXT, city TEXT, seo_title TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE rooms (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, size TEXT, villa_id INTEGER NULL)');
    $insert = $pdo->prepare('INSERT INTO villas (title, summary, description, price, city, seo_title, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([json_encode(['en' => 'Cliff top villa', 'ar' => 'فيلا على قمة الجرف']), json_encode(['en' => 'Four bedrooms, five minutes from the beach.', 'ar' => 'أربع غرف نوم، على بعد خمس دقائق من الشاطئ.']),
        json_encode(['en' => '<p>A bright villa on the hill above Chania, with a garden, a pool and a view of the bay from every room.</p>']), '420000', 'Chania', json_encode(['en' => 'Cliff top villa in Chania', 'ar' => 'فيلا في خانيا']), 'published']);
    $insert->execute([json_encode(['en' => 'Harbour residences', 'ar' => 'مساكن الميناء', 'ku' => 'خانووەکانی بەندەر']), json_encode(['en' => 'Flats by the old port.', 'ar' => 'شقق قرب الميناء القديم.', 'ku' => 'شوقە لە نزیک بەندەری کۆن.']),
        json_encode(['en' => '<p>Flats.</p>', 'ar' => '<p>شقق.</p>', 'ku' => '<p>شوقە.</p>']), '310000', 'Heraklion', json_encode(['en' => 'Harbour', 'ar' => 'الميناء', 'ku' => 'بەندەر']), 'published']);
    $insert->execute([json_encode(['en' => 'Old town houses']), null, null, '250000', 'Rethymno', null, 'draft']);
    $pdo->exec("INSERT INTO rooms (name, size, villa_id) VALUES ('" . json_encode(['en' => 'Penthouse', 'ar' => 'البنتهاوس'], JSON_UNESCAPED_UNICODE) . "', '160', 1), ('" . json_encode(['en' => 'Garden room']) . "', '40', 1)");
};
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='villas'")->fetchColumn()) { $seedVillas($pdo); }
// Repeater and key-value fields (0.24)
$seedApartments = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS apartments');
    $pdo->exec('CREATE TABLE apartments (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, rooms TEXT, specs TEXT, hours TEXT)');
    $rooms = json_encode([
        ['_key' => 'ka1', 'name' => ['en' => 'Master bedroom', 'ar' => 'غرفة النوم الرئيسية'], 'size' => '22', 'floor' => '1'],
        ['_key' => 'ka2', 'name' => ['en' => 'Kitchen'], 'size' => '16', 'floor' => '0'],
        ['_key' => 'ka3', 'name' => ['en' => 'Living room', 'ar' => 'غرفة المعيشة'], 'size' => '34', 'floor' => '0'],
    ], JSON_UNESCAPED_UNICODE);
    $specs = json_encode([['key' => 'Parking', 'value' => ['en' => '2 covered spaces']], ['key' => 'Heating', 'value' => ['en' => 'Underfloor, gas', 'ar' => 'أرضية، بالغاز']]], JSON_UNESCAPED_UNICODE);
    $hours = json_encode([['_key' => 'kh1', 'day' => 'mon', 'opens' => '09:00', 'closes' => '17:00']]);
    $pdo->prepare('INSERT INTO apartments (title, rooms, specs, hours) VALUES (?, ?, ?, ?)')->execute([json_encode(['en' => 'Harbour apartment']), $rooms, $specs, $hours]);
};
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='apartments'")->fetchColumn()) { $seedApartments($pdo); }
// CheckboxList, TagsInput, TimePicker (0.37)
$seedHouses = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS houses');
    $pdo->exec('CREATE TABLE houses (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, features TEXT NULL, keywords TEXT NULL, opens_at TIME NULL, closes_at TIME NULL, slots TEXT NULL)');
    $insert = $pdo->prepare('INSERT INTO houses (title, features, keywords, opens_at, closes_at) VALUES (?, ?, ?, ?, ?)');
    $insert->execute(['Seaside villa with garden', '["pool","parking","sea_view","garden"]', '["villa","sea view","garden"]', '09:30:00', '18:00:00']);
    $insert->execute(['Harbour apartment', '["pool","lift","balcony"]', '["apartment","Sea View"]', '10:00:00', '16:00:00']);
    $insert->execute(['Stone house near the old port', '["pool","garden","fireplace","balcony","furnished"]', '["house","sea view","quiet","stone"]', '11:00:00', '13:00:00']);
    $insert->execute(['Alpine lodge', '["fireplace","sauna"]', '["lodge"]', '15:00:00', null]);
};
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='houses'")->fetchColumn()) { $seedHouses($pdo); }
// Pages (0.30): on after /__test/pages
$seedPages = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS admin_pages');
    $file = ROOT . '/pages-migration.php';
    file_put_contents($file, \FloCMS\Admin\Console\Stub::render('pages-migration', ['table' => 'admin_pages']));
    (require $file)->up(App::$db);
    $home = json_encode(['en' => [
        ['id' => 'bh1', 'type' => 'hero', 'data' => ['heading' => 'Design that works', 'text' => 'Websites and apps for growing teams.', 'button' => 'Our services', 'link' => '/en/services', 'height' => 'medium', 'align' => 'start', 'overlay' => '35']],
        ['id' => 'bc1', 'type' => 'cards', 'data' => ['heading' => 'What we do', 'columns' => '3', 'cards' => [['title' => 'Strategy', 'text' => 'Research and planning.'], ['title' => 'Design', 'text' => 'Interfaces people enjoy.'], ['title' => 'Development', 'text' => 'Fast, accessible sites.']]]],
        ['id' => 'bt1', 'type' => 'call_to_action', 'data' => ['heading' => 'Start a project', 'text' => 'Tell us what you need.', 'button' => 'Contact us', 'link' => '/en/contact', 'style' => 'brand']],
    ]], JSON_UNESCAPED_UNICODE);
    $insert = $pdo->prepare('INSERT INTO admin_pages (title, slug, type, blocks, html, body, status, publish_at, parent_id, menu, sort, meta_title, meta_description, is_home, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $now = date('Y-m-d H:i:s');
    foreach ([
        ['{"en":"Home","ar":"الرئيسية"}', '{"en":"home","ar":"home"}', 'builder', $home, null, null, 'published', null, null, 0, 0, '{"en":"Your Company"}', '{"en":"Websites and apps for growing teams."}', 1],
        ['{"en":"About us","ar":"من نحن"}', '{"en":"about-us","ar":"من-نحن"}', 'rich_text', null, null, '{"en":"<p>We are a small studio that builds websites.</p>","ar":"<p>نحن استوديو صغير يبني المواقع.</p>"}', 'published', null, null, 1, 1, null, '{"en":"Who we are and how we work."}', 0],
        ['{"en":"Services"}', '{"en":"services"}', 'rich_text', null, null, '{"en":"<p>Strategy, design and development.</p>"}', 'published', null, null, 1, 2, null, null, 0],
        ['{"en":"Web design"}', '{"en":"web-design"}', 'html', null, '{"en":"<section class=\"custom\">\n  <h2>Web design</h2>\n  <p>Sites that work on every screen.</p>\n</section>"}', null, 'published', null, 3, 1, 0, null, null, 0],
        ['{"en":"Careers"}', '{"en":"careers"}', 'rich_text', null, null, '{"en":"<p>Join us.</p>"}', 'draft', null, null, 1, 3, null, null, 0],
        ['{"en":"Winter offer"}', '{"en":"winter-offer"}', 'rich_text', null, null, '{"en":"<p>Soon.</p>"}', 'scheduled', '2099-12-01 09:00:00', null, 0, 4, null, null, 0],
    ] as $row) { $insert->execute([...$row, $now, $now]); }
    array_map('unlink', glob(ROOT . '/storage/cache/blocks/*.json') ?: []);
};
if (Session::get('pages_demo') && !(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='admin_pages'")->fetchColumn()) { $seedPages($pdo); }
// The Builder field (0.29)
$seedLandings = static function (PDO $pdo, int $many = 0): void {
    $pdo->exec('DROP TABLE IF EXISTS landings');
    $pdo->exec('CREATE TABLE landings (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, content TEXT)');
    $en = [
        ['id' => 'bslider1', 'type' => 'slider', 'data' => ['name' => 'Home slider', 'height' => 'screen', 'slides' => [
            ['_key' => 'k1', 'media' => '1', 'heading' => 'Design that works', 'text' => 'Websites and apps for growing teams.', 'button' => 'Our work', 'link' => '/work', 'position' => 'bottom-start'],
            ['_key' => 'k2', 'media' => '2', 'heading' => 'Built to last', 'position' => 'middle-center'],
        ]]],
        ['id' => 'bsect1', 'type' => 'section', 'data' => ['name' => 'Services', 'width' => 'boxed', 'background' => 'light'], 'children' => [
            ['id' => 'bhead1', 'type' => 'heading', 'data' => ['text' => 'What we do', 'level' => 'h2']],
            ['id' => 'bcols1', 'type' => 'columns', 'data' => ['layout' => '50-50'], 'children' => [
                ['id' => 'btext1', 'type' => 'rich_text', 'data' => ['body' => '<p>Strategy and design.</p>']],
                ['id' => 'btext2', 'type' => 'rich_text', 'column' => 1, 'data' => ['body' => '<p>Development and support.</p>']],
            ]],
        ]],
        ['id' => 'bcta1', 'type' => 'call_to_action', 'data' => ['heading' => 'Start a project', 'button' => 'Contact us', 'link' => '/contact'], 'settings' => ['status' => 'draft']],
    ];
    // many=N (0.32): N more headings in English (up to 30 blocks) and N in Arabic and Kurdish, for a page with more fields than max_input_vars.
    $lists = ['en' => $en];
    for ($i = 1; $i <= $many; $i++) {
        foreach (['en', 'ar', 'ku'] as $lang) {
            if ($lang === 'en' && $i > 23) { continue; }
            $lists[$lang][] = ['id' => 'bm' . $lang . $i, 'type' => 'heading', 'data' => ['text' => strtoupper($lang) . ' heading ' . $i, 'level' => 'h2']];
        }
    }
    $pdo->prepare('INSERT INTO landings (title, content) VALUES (?, ?)')->execute([json_encode(['en' => 'Home page']), json_encode($lists, JSON_UNESCAPED_UNICODE)]);
};
if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='landings'")->fetchColumn()) { $seedLandings($pdo); }
// Editor media and settings files, as a web server would serve public/uploads (the public folder is the web root).
if (preg_match('~^/uploads/(editor|settings)/([A-Za-z0-9/_.-]+)$~', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $media) === 1 && !str_contains($media[2], '..')) {
    $file = ROOT . '/public/uploads/' . $media[1] . '/' . $media[2];
    if (!is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    readfile($file); exit;
}
if (str_starts_with($_SERVER['REQUEST_URI'], '/__test/')) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/__test/landing-view') { http_response_code(405); exit; }
    switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
        // Custom panel pages (0.36)
        case '/__test/custom-pages':
            Session::set('custom_pages_demo', true);
            if (!is_dir(VIEWS_PATH . '/admin')) { mkdir(VIEWS_PATH . '/admin', 0700, true); }
            file_put_contents(VIEWS_PATH . '/admin/sales-report.html', '<div class="fa-stat-row"><div class="fa-stat-tile"><span class="fa-stat-label">Filter</span><span class="fa-stat-value">{{ $status }}</span></div></div><div class="fa-split"><section class="fa-card"><h2>{{ $heading }}</h2><p>Income by month.</p></section><section class="fa-card fa-empty"><h2>No refunds</h2><p>Refunds show up here.</p></section></div>');
            file_put_contents(VIEWS_PATH . '/admin/getting-started.html', '<section class="fa-card"><h2>Set up your site</h2><ul>@foreach($steps as $step)<li>{{ $step }}</li>@endforeach</ul></section>');
            break;
        case '/__test/widgets': Session::set('widget_demo', true); Session::set('widget_static', ($_POST['static'] ?? '') === '1'); break;
        case '/__test/widget-add': App::$db->table('posts')->insert(['title'=>'{"en":"Fresh widget post"}', 'slug'=>'widget-'.bin2hex(random_bytes(4)), 'status'=>'published', 'featured'=>1, 'views'=>7]); break;
        case '/__test/readonly': Session::set('read_only', true); break;
        // Edits listings and sees the media library, but may not add to it (no content.media.create).
        case '/__test/writer': Session::set('writer', true); break;
        case '/__test/faqs': Session::set('faq_count', max(1, (int) ($_POST['count'] ?? 5))); App::$db->query('DROP TABLE IF EXISTS faqs'); break;
        case '/__test/media':
            // The media library (off until a test asks): its tables, and with seed=1 three items with real files.
            \FloCMS\Admin\Tests\Fixtures\Media\MediaTables::create(App::$db);
            App::$db->query('UPDATE listings SET cover_id = NULL, gallery = NULL, video_id = NULL, body = ? WHERE id = 1', ['{"en":"<p>Welcome</p>"}']);
            if (($_POST['seed'] ?? '') === '1') {
                $root = ROOT . '/public/uploads/editor';
                foreach (\FloCMS\Admin\Tests\Fixtures\Media\MediaTables::seed(App::$db) as $files) {
                    foreach ($files as $file) {
                        if (!is_dir(dirname($root . '/' . $file))) { mkdir(dirname($root . '/' . $file), 0777, true); }
                        copy($package . '/tests/Browser/fixtures/' . (str_ends_with($file, '.mp4') ? 'clip.mp4' : 'photo.png'), $root . '/' . $file);
                    }
                }
            }
            break;
        case '/__test/listing': header('Content-Type: application/json'); echo json_encode([
            'row' => App::$db->table('listings')->where('id', '=', 1)->first(),
            'usage' => App::$db->query('SELECT media_id, field FROM media_usage WHERE resource = \'listings\' ORDER BY media_id, field')->fetchAll(PDO::FETCH_ASSOC),
            'media' => App::$db->query('SELECT id, title, alt, path, folder_id, focal_x, focal_y FROM media ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'folders' => App::$db->query('SELECT id, parent_id, name FROM media_folders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]); exit;
        case '/__test/media-off': App::$db->query('DROP TABLE IF EXISTS media_folders'); App::$db->query('DROP TABLE IF EXISTS media_usage'); App::$db->query('DROP TABLE IF EXISTS media'); break;
        case '/__test/people':
            // The Users page (0.28): a few people, the mailing tables and the activity log.
            foreach ([['mailings-migration', ['mailings_table' => 'admin_mailings', 'recipients_table' => 'admin_mailing_recipients', 'optouts_table' => 'admin_mail_optouts'], 'mailings'], ['trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity'], 'history'], ['password-resets-migration', ['table' => 'admin_password_resets'], 'resets']] as [$stub, $values, $name]) {
                $file = ROOT . '/' . $name . '-migration.php';
                file_put_contents($file, \FloCMS\Admin\Console\Stub::render($stub, $values));
                (require $file)->up(App::$db);
            }
            try { $pdo->exec("ALTER TABLE users ADD COLUMN image TEXT NOT NULL DEFAULT ''"); } catch (\PDOException) { /* already there */ }
            try { $pdo->exec('ALTER TABLE users ADD COLUMN bio TEXT NULL'); } catch (\PDOException) { /* already there */ }
            if ((int) App::$db->table('users')->count() < 3) {
                foreach ([['Daniel Okafor', 2, 1, 1], ['Lana Karim', 1, 1, 1], ['Omar Haddad', 0, 2, 0], ['Elena Papadopoulou', 1, 1, 1], ['Rebin Aziz', 0, 3, 1], ['Mia Fischer', 0, 1, 1], ['Yusuf Demir', 0, 2, 0]] as [$name, $role, $status, $verified]) {
                    $login = strtolower(strtok($name, ' '));
                    App::$db->table('users')->insert(['full_name' => $name, 'email' => $login . '@seaside.example', 'login' => $login, 'password' => '', 'role' => $role, 'status' => $status, 'is_verified' => $verified]);
                }
            }
            break;
        case '/__test/history':
            // The trash and the activity log (0.19): off until a test asks, so other tests delete for good.
            $file = ROOT . '/history-migration.php';
            file_put_contents($file, \FloCMS\Admin\Console\Stub::render('trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']));
            (require $file)->up(App::$db);
            break;
        case '/__test/roles':
            // The Roles page (0.21): its table, three editors, and a role added in the panel with two users.
            $file = ROOT . '/roles-migration.php';
            file_put_contents($file, \FloCMS\Admin\Console\Stub::render('roles-migration', ['table' => 'admin_roles']));
            (require $file)->up(App::$db);
            App::$db->query('DELETE FROM admin_roles');
            App::$db->query("DELETE FROM users WHERE email LIKE '%@seaside.example'");
            foreach ([['Nikos Papadakis', 1], ['Maria Kosta', 1], ['Omar Saleh', 1], ['Lana Aziz', 5], ['Rebin Kamal', 5], ['Hana Ali', 0]] as [$name, $role]) {
                App::$db->table('users')->insert(['full_name' => $name, 'email' => strtolower(strtok($name, ' ')) . '@seaside.example', 'login' => strtolower(strtok($name, ' ')), 'password' => '', 'role' => $role, 'status' => 1]);
            }
            App::$db->table('admin_roles')->insert(['role' => 5, 'name' => json_encode(['en' => 'Author', 'ar' => 'كاتب', 'ku' => 'نووسەر'], JSON_UNESCAPED_UNICODE), 'level' => 1, 'admin_access' => 1, 'permissions' => '["content.articles.view","content.articles.create","content.articles.update","content.media.view"]', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            break;
        case '/__test/roles-off': App::$db->query('DROP TABLE IF EXISTS admin_roles'); App::$db->query("DELETE FROM users WHERE email LIKE '%@seaside.example'"); break;
        case '/__test/roles-state': header('Content-Type: application/json'); echo json_encode([
            'roles' => App::$db->query('SELECT role, name, level, admin_access, permissions FROM admin_roles ORDER BY role')->fetchAll(PDO::FETCH_ASSOC),
            'users' => App::$db->query('SELECT full_name, role FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]); exit;
        case '/__test/history-off': App::$db->query('DROP TABLE IF EXISTS admin_trash'); App::$db->query('DROP TABLE IF EXISTS admin_activity'); break;
        case '/__test/trash': header('Content-Type: application/json'); echo json_encode([
            'trash' => App::$db->query('SELECT resource, record_id, batch FROM admin_trash ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'activity' => App::$db->query('SELECT action, resource, record_id FROM admin_activity ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]); exit;
        case '/__test/faq-add': App::$db->table('faqs')->insert(['question' => '{"en":"Added elsewhere"}', 'category' => 'billing', 'sort' => 99, 'archived' => 0]); break;
        case '/__test/faq-order': header('Content-Type: application/json'); echo json_encode(array_map('intval', App::$db->query('SELECT id FROM faqs ORDER BY sort, id')->fetchAll(PDO::FETCH_COLUMN))); exit;
        case '/__test/expire': Csrf::rotate(); break;
        case '/__test/password':
            // A visitor who is not signed in, and an empty password resets table
            foreach (['isloggedin', 'admin_access', 'user_id', 'role', 'fullname', 'email'] as $key) { Session::delete($key); }
            App::$db->query('DROP TABLE IF EXISTS admin_password_resets');
            $file = ROOT . '/password-resets-migration.php';
            file_put_contents($file, \FloCMS\Admin\Console\Stub::render('password-resets-migration', ['table' => 'admin_password_resets']));
            (require $file)->up(App::$db);
            @unlink(ROOT . '/storage/logs/admin-mail.log');
            break;
        case '/__test/reset-link':
            header('Content-Type: application/json');
            preg_match_all('#https?://[^/\s]+(/[a-z]{2}/admin/password/reset/[A-Za-z0-9_-]{43})#', (string) @file_get_contents(ROOT . '/storage/logs/admin-mail.log'), $links);
            echo json_encode(['path' => $links[1] === [] ? null : end($links[1]), 'mails' => substr_count((string) @file_get_contents(ROOT . '/storage/logs/admin-mail.log'), '==== ')]); exit;
        case '/__test/security':
            // Login security (0.35): its tables, the activity log, and Sara (Super admin) not yet confirmed.
            Session::set('security_demo', true);
            foreach ([['login-security-migration', ['two_factor_table' => 'admin_two_factor', 'roles_table' => 'admin_two_factor_roles', 'devices_table' => 'admin_trusted_devices', 'tokens_table' => 'admin_email_tokens'], 'security'], ['trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity'], 'history']] as [$stub, $values, $name]) {
                $file = ROOT . '/' . $name . '-migration.php';
                file_put_contents($file, \FloCMS\Admin\Console\Stub::render($stub, $values));
                (require $file)->up(App::$db);
            }
            foreach (['admin_two_factor', 'admin_two_factor_roles', 'admin_trusted_devices', 'admin_email_tokens'] as $table) { App::$db->query('DELETE FROM ' . $table); }
            App::$db->table('users')->where('id', '=', 1)->update(['is_verified' => (int) ($_POST['verified'] ?? 0), 'role' => 3, 'status' => 1, 'password' => password_hash('secret-pass-1', PASSWORD_DEFAULT)]);
            Session::delete('auth_holds_asked'); Session::delete('admin_password_stamp');
            @unlink(ROOT . '/storage/logs/admin-mail.log');
            break;
        case '/__test/security-require': \FloCMS\Admin\Auth\TwoFactor::setRequired(3, ($_POST['required'] ?? '1') === '1'); break;
        case '/__test/signin':
            // A fresh sign-in of Sara: what the skeleton's login action leaves in the session.
            foreach (['auth_held', 'auth_holds_asked', 'admin_2fa_wrong', 'admin_password_stamp'] as $key) { Session::delete($key); }
            Session::set('isloggedin', true); Session::set('admin_access', true); Session::set('user_id', 1); Session::set('role', 3);
            Session::set('fullname', 'Sara'); Session::set('email', 'sara@example.test');
            break;
        case '/__test/totp':
            header('Content-Type: application/json');
            $secret = \FloCMS\Admin\Auth\Totp::base32Decode((string) ($_POST['key'] ?? '')) ?? '';
            echo json_encode(['code' => \FloCMS\Admin\Auth\Totp::code($secret, \FloCMS\Admin\Auth\Totp::step() + (int) ($_POST['offset'] ?? 0))]); exit;
        case '/__test/security-state': header('Content-Type: application/json'); echo json_encode([
            'two_factor' => App::$db->query('SELECT user_id, failures FROM admin_two_factor')->fetchAll(PDO::FETCH_ASSOC),
            'devices' => (int) App::$db->query('SELECT COUNT(*) FROM admin_trusted_devices')->fetchColumn(),
            'session' => array_intersect_key($_SESSION, array_flip(['isloggedin', 'auth_held', 'user_id'])),
            'link' => preg_match_all('#https?://[^/\s]+(/[a-z]{2}/admin/verify/link/[A-Za-z0-9_-]{43})#', (string) @file_get_contents(ROOT . '/storage/logs/admin-mail.log'), $links) ? end($links[1]) : null,
            'verified' => (int) App::$db->table('users')->where('id', '=', 1)->first()->is_verified]); exit;
        case '/__test/managers': $seedManagers($pdo); break;
        case '/__test/apartments': $seedApartments($pdo); break;
        case '/__test/houses': $seedHouses($pdo); break;
        case '/__test/houses-state': header('Content-Type: application/json'); echo json_encode(App::$db->table('houses')->orderBy('id')->get()); exit;
        case '/__test/pages': Session::set('pages_demo', true); $seedPages($pdo); break;
        case '/__test/pages-off': Session::set('pages_demo', false); break;
        case '/__test/pages-state': header('Content-Type: application/json'); echo json_encode($pdo->query('SELECT * FROM admin_pages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)); exit;
        case '/__test/landings': $seedLandings($pdo, max(0, min(30, (int) ($_POST['many'] ?? 0)))); array_map('unlink', glob(ROOT . '/storage/cache/blocks/*.json') ?: []); break;
        case '/__test/landing-view':
            // The website side (0.29): a page that prints a landing's blocks, as a site's layout would.
            $row = $pdo->query('SELECT * FROM landings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            $lang = preg_match('/^[a-z]{2}$/', (string) ($_GET['lang'] ?? '')) === 1 ? (string) $_GET['lang'] : 'en';
            $body = blocks($row['content'] ?? '', $lang);
            echo '<!doctype html><html lang="' . $lang . '" dir="' . ($lang === 'en' ? 'ltr' : 'rtl') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Landing</title>'
                . '<style>body{margin:0;font-family:system-ui,sans-serif;color:#101828}</style>' . blocks_assets() . '</head><body>' . $body . '</body></html>';
            exit;
        // 0.33: a landing's blocks as given (a style a developer has since removed…)
        case '/__test/landings-set': $pdo->prepare('UPDATE landings SET content = ? WHERE id = 1')->execute([(string) ($_POST['content'] ?? '')]); array_map('unlink', glob(ROOT . '/storage/cache/blocks/*.json') ?: []); break;
        case '/__test/landings-state': header('Content-Type: application/json'); echo json_encode($pdo->query('SELECT * FROM landings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)); exit;
        case '/__test/products': $seedProducts($pdo); break;
        case '/__test/products-state': header('Content-Type: application/json'); echo json_encode(['products' => $pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'features' => $pdo->query('SELECT * FROM product_feature ORDER BY product_id, feature_id')->fetchAll(PDO::FETCH_ASSOC)]); exit;
        case '/__test/villas': $seedVillas($pdo); if (class_exists(\FloCMS\Admin\Preferences\Preferences::class)) { Session::set('_flo_admin_preferences', null); } break;
        case '/__test/asset-change': App::$db->table('assets')->where('id', '=', 1)->update(['title'=>(string) ($_POST['title'] ?? 'Changed elsewhere')]); break;
        case '/__test/change': App::$db->table('posts')->where('id', '=', (int) ($_POST['id'] ?? 1))->update(['title'=>'{"en":"Changed elsewhere"}']); break;
        case '/__test/delete': App::$db->table('posts')->where('id', '=', (int) ($_POST['id'] ?? 1))->delete(); break;
        default: http_response_code(404); exit;
    }
    http_response_code(204); exit;
}
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
// The skeleton's login page (its view prints LoginView), here for the password flow's redirect
if (preg_match('~^/(en|ar|ku)/(?:__login-preview|admin/users/login/?)$~', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $match)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
    define('ACTIVE_LANG', $match[1]);
    \FloCMS\Admin\Support\AdminLang::setLanguage($match[1]);
    $session = $_SESSION; Session::set('isloggedin', false);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login preview</title></head><body>' . \FloCMS\Admin\Auth\LoginView::render() . '</body></html>';
    $_SESSION = $session; exit;
}
try { App::run($_SERVER['REQUEST_URI']); }
catch (HttpException $error) { Response::text($error->getMessage(), $error->status)->send(); }
