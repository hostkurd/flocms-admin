<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Whole page requests through FloCMS\Core\App::run(), each in its own PHP
 * process like a real request. The package registers itself exactly as on a
 * site (Composer autoload → bootstrap.php); the database is an SQLite file.
 */
abstract class RequestTestCase extends TestCase
{
    protected const EDITOR = ['admin_access' => true, 'isloggedin' => true, 'role' => 1, 'user_id' => 1, 'fullname' => 'Sara', '_csrf_token' => 'tok'];
    protected const PASSWORD = 'secret-pass-1';
    protected const ADMIN = ['admin_access' => true, 'isloggedin' => true, 'role' => 2, 'user_id' => 2, 'fullname' => 'Ali', 'email' => 'ali@example.test', '_csrf_token' => 'tok'];
    protected const SUPER = ['admin_access' => true, 'isloggedin' => true, 'role' => 3, 'user_id' => 3, 'fullname' => 'Root', 'email' => 'root@example.test', '_csrf_token' => 'tok'];
    protected const PERMISSIONS = [0 => [], 1 => ['content.*'], 2 => ['content.*', 'users.manage', 'users.assign_role', 'settings.*'], 3 => ['*']];

    protected static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/flo-admin-site-' . bin2hex(random_bytes(6));
        $files = [
            'public/conf_global.php' => "<?php\ndefine('ACTIVE_LANG', \\FloCMS\\Core\\App::getRouter()->getLanguage());\n",
            'lang/en.php' => "<?php\nreturn ['lng.dir' => 'ltr'];\n",
            'lang/ku.php' => "<?php\nreturn ['lng.dir' => 'rtl', 'lng.en' => 'ئینگلیزی'];\n",
            'templates/default/layouts/admin.html' => '<html><body><?= $data[\'content\'] ?></body></html>',
            'templates/default/layouts/default.html' => '<?= $data[\'content\'] ?>',
            'views/.keep' => '',
            'config/admin.php' => "<?php\nreturn ['resources' => [\\FloCMS\\Admin\\Tests\\Fixtures\\Resources\\PostResource::class, \\FloCMS\\Admin\\Tests\\Fixtures\\Resources\\TagResource::class], 'brand' => 'Test Panel'];\n",
            'request.php' => self::requestScript(),
        ];
        foreach ($files as $path => $content) {
            $file = self::$root . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
        }
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf ' . escapeshellarg(self::$root));
    }

    protected function setUp(): void
    {
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS posts');
        $pdo->exec('DROP TABLE IF EXISTS tags');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NULL, slug VARCHAR(190) NOT NULL DEFAULT "",
            body TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT "draft", views INTEGER NULL, featured INTEGER NOT NULL DEFAULT 0,
            published_on DATE NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT "")');
        $pdo->exec("INSERT INTO posts (title, slug, status, featured) VALUES
            ('{\"en\":\"Sea view villa\",\"ar\":\"فيلا\"}', 'sea-view-villa', 'published', 1),
            ('{\"en\":\"Mountain cabin\"}', 'mountain-cabin', 'draft', 0)");
        $pdo->exec("INSERT INTO tags (name) VALUES ('Garden')");

        // The skeleton's users table: 1 Sara (Editor), 2 Ali (Admin), 3 Root (Super Admin), 4 Dara (User)
        $pdo->exec('DROP TABLE IF EXISTS users');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name VARCHAR(190) NOT NULL DEFAULT "",
            login VARCHAR(190) NOT NULL DEFAULT "", password VARCHAR(255) NOT NULL DEFAULT "", email VARCHAR(190) NOT NULL UNIQUE,
            image VARCHAR(255) NOT NULL DEFAULT "", address VARCHAR(255) NOT NULL DEFAULT "", phone VARCHAR(50) NOT NULL DEFAULT "",
            gender VARCHAR(20) NOT NULL DEFAULT "", role TINYINT NOT NULL DEFAULT 0, status TINYINT NOT NULL DEFAULT 2,
            is_verified TINYINT NOT NULL DEFAULT 0, verify_type TINYINT NOT NULL DEFAULT 0, token VARCHAR(255) NOT NULL DEFAULT "")');
        $insert = $pdo->prepare('INSERT INTO users (full_name, login, email, password, role, status) VALUES (?, ?, ?, ?, ?, 1)');
        foreach ([['Sara', 1], ['Ali', 2], ['Root', 3], ['Dara', 0]] as [$name, $role]) {
            $email = strtolower($name) . '@example.test';
            $insert->execute([$name, $email, $email, password_hash(self::PASSWORD, PASSWORD_DEFAULT), $role]);
        }
    }

    protected static function pdo(): PDO
    {
        return new PDO('sqlite:' . self::$root . '/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array{status: int, body: string, redirected: bool, session: array<string, mixed>, error: ?string} */
    protected static function request(string $method, string $uri, array $options = []): array
    {
        $options += ['session' => self::EDITOR, 'permissions' => self::PERMISSIONS, 'post' => [], 'method' => $method];
        $command = [PHP_BINARY, self::$root . '/request.php', dirname(__DIR__, 2), $uri, json_encode($options)];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $code);
        $result = json_decode(implode("\n", $output), true);
        self::assertIsArray($result, implode("\n", $output));
        self::assertNull($result['error'], (string) $result['error']);

        return $result;
    }

    protected static function post(string $uri, array $data, array $options = []): array
    {
        return self::request('POST', $uri, ['post' => $data + ['_token' => 'tok']] + $options);
    }

    protected static function row(string $table, int $id): ?array
    {
        $row = self::pdo()->query('SELECT * FROM ' . $table . ' WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function requestScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

[, $package, $uri, $options] = $argv;
$options = json_decode($options, true);

define('ROOT', __DIR__);
define('DS', DIRECTORY_SEPARATOR);
define('VIEWS_PATH', ROOT . '/views');
define('TEMPLATES_PATH', ROOT . '/templates');
define('SITE_URI', 'https://example.test');

// Composer's autoloader runs the package's bootstrap.php, as on a site
require $package . '/vendor/autoload.php';

use FloCMS\Core\App;
use FloCMS\Core\Config;
use FloCMS\Core\Database;
use FloCMS\Core\HttpException;

$_SESSION = $options['session'];
$_SERVER['REQUEST_METHOD'] = $options['method'];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_POST = $options['post'];
$query = parse_url($uri, PHP_URL_QUERY);
$_GET = [];
if (is_string($query)) {
    parse_str($query, $_GET);
}
$_ENV['DEFAULT_LANG'] = 'en';

Config::set('routes', ['default' => '', 'admin' => 'admin_']);
Config::set('default_route', 'default');
Config::set('default_controller', 'pages');
Config::set('default_action', 'index');
Config::set('languages', ['en', 'ar', 'ku']);
Config::set('default_language', 'en');
Config::set('permissions', $options['permissions']);
Config::set('roles', [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin']);
if (isset($options['admin_config'])) {
    \FloCMS\Admin\Admin::useConfig($options['admin_config'], true, ROOT);
}

App::$db = new Database(new PDO('sqlite:' . ROOT . '/db.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]));

// Router::redirect() exits, so the result is written on shutdown
$status = 200;
$finished = false;
$error = null;
ob_start();
register_shutdown_function(static function () use (&$status, &$finished, &$error): void {
    $body = (string) ob_get_clean();
    echo json_encode(['status' => $status, 'body' => $body, 'redirected' => !$finished, 'session' => $_SESSION, 'error' => $error]);
});
try {
    App::run($uri);
} catch (HttpException $e) {
    $status = $e->status;
} catch (Throwable $e) {
    $status = 500;
    $error = get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}
$finished = true;
PHP;
    }
}
