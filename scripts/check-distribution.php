<?php
declare(strict_types=1);

$zip = new ZipArchive();
if ($zip->open($argv[1] ?? '') !== true) { throw new RuntimeException('Cannot open distribution archive.'); }
$version = json_decode(file_get_contents(dirname(__DIR__) . '/package.json'), true, 512, JSON_THROW_ON_ERROR)['version'];
foreach (['dist/admin-v' . $version . '.js', 'dist/admin-v' . $version . '.css', 'bootstrap.php', 'src/Reactive/ResourceComponent.php', 'src/Actions/Action.php', 'src/Actions/ActionExecutor.php', 'src/Actions/BulkExecutor.php', 'src/Table/Filters/DateRangeFilter.php', 'src/Auth/LoginView.php', 'src/Notifications/Notification.php', 'resources/views/action.html', 'resources/views/bulk.html', 'resources/views/login.html', 'resources/views/notifications.html', 'resources/views/reactive-resource.html', 'docs/tables-and-login.md', 'docs/forms.md', 'src/Form/Layout/Tabs.php', 'UPGRADING.md'] as $path) {
    if ($zip->locateName($path) === false) { throw new RuntimeException('Missing ' . $path); }
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $path = $zip->getNameIndex($i);
    if (preg_match('~^(vendor|node_modules|tests|test-results|playwright-report|resources/js|resources/assets)/~', $path)
        || str_starts_with($path, 'composer.local.') || in_array($path, ['package-lock.json', 'package.json', 'composer.lock'], true)) {
        throw new RuntimeException('Development file shipped: ' . $path);
    }
}
echo "Distribution includes versioned runtime assets and excludes development files.\n";
