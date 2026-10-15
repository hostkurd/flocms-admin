<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Widgets;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Core\Database;
/** Featured rows stand in for an application's tenant/site query scope. */
final class WidgetPostResource extends PostResource
{
    protected static string $slug = 'widget-posts';
    protected static string $pluralLabel = 'Featured posts';
    public function query(Database $query): Database { return $query->where('featured', '=', 1); }
}
