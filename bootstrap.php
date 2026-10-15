<?php
declare(strict_types=1);

/*
 * Loaded by Composer's autoloader on every request and command.
 *
 * It only registers a controller resolver (a closure): no file is read and no
 * database is touched until FloCMS asks the resolver about an admin URL that
 * no application controller answers. Removing the package removes this file,
 * so the site is back to exactly what it was.
 */

if (class_exists(\FloCMS\Core\App::class) && method_exists(\FloCMS\Core\App::class, 'resolveControllersUsing')) {
    \FloCMS\Admin\Admin::boot();
}

\FloCMS\Reactive\Reactive::register('flo-admin-resource', \FloCMS\Admin\Reactive\ResourceComponent::class);

\FloCMS\Reactive\Reactive::register('flo-admin-dashboard', \FloCMS\Admin\Reactive\DashboardComponent::class);
