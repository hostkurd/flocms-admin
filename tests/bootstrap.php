<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The fixture resources (namespace FloCMS\Admin\Tests\Fixtures) load through Composer's autoload-dev.

// Mailings sign their unsubscribe links with APP_KEY (0.28).
$_ENV['APP_KEY'] = $_ENV['APP_KEY'] ?? 'phpunit-test-key-0123456789abcdef';
