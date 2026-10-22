<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\PanelPages;

use FloCMS\Admin\AdminPage;

/** Takes the slug of a resource fixture. */
final class PostsPage extends AdminPage
{
    protected static string $slug = 'posts';
}

/** Takes a package page's slug. */
final class SettingsClash extends AdminPage
{
    protected static string $slug = 'settings';
}

/** Takes the slug of the application controller NewsController. */
final class NewsPage extends AdminPage
{
}

/** An invalid width and route. */
final class BrokenDefinition extends AdminPage
{
    protected static string $width = 'huge';
    protected static array $routes = ['Bad-Route' => 'missing'];
}
