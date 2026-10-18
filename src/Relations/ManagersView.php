<?php
declare(strict_types=1);

namespace FloCMS\Admin\Relations;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\App;
use FloCMS\Core\Auth;

/**
 * The relation managers below a record's form (0.22): one card, with tabs
 * when there are two or more. Each manager is the child resource's Reactive
 * list limited to this parent; without JavaScript (or with Reactive off) it
 * links to the child's own list page limited the same way.
 *
 * @internal
 */
final class ManagersView
{
    /**
     * The managers the current user may see: the child is registered and they may view it.
     * (Editing the parent is checked by the page, and again on every request of a manager.)
     *
     * @return list<RelationManager>
     */
    public static function visible(Resource $parent): array
    {
        return array_values(array_filter(RelationManager::of($parent), static fn (RelationManager $manager): bool =>
            Admin::registry()->find($manager->getResource()::getSlug()) === $manager->getResource()
            && Auth::can($manager->getResource()::permission('view'))));
    }

    /** The card on the edit page of $parentId, or '' when there is nothing to show. */
    public static function render(Resource $parent, string $parentId): string
    {
        $managers = self::visible($parent);
        if ($managers === [] || $parent->parentScope() !== null) { return ''; }
        $reactive = Admin::config()->reactiveEnabled();
        if ($reactive) { \FloCMS\Reactive\Reactive::register('flo-admin-resource', \FloCMS\Admin\Reactive\ResourceComponent::class); }
        $slug = $parent::getSlug();
        $items = [];
        foreach ($managers as $manager) {
            $child = $manager->getResource();
            $scope = ParentScope::resolve($slug, $parentId, $manager->getName(), $child::getSlug());
            $key = 'rel-' . $manager->getName();
            $count = (new RecordRepository((new $child())->withinParent($scope), App::db() ?? throw new \RuntimeException('Database required.'), Translations::store()))->newQuery()->count();
            $body = $reactive
                ? \FloCMS\Reactive\Reactive::mount('flo-admin-resource', $key, [
                    'resourceSlug' => $child::getSlug(), 'instanceKey' => $key,
                    'parentSlug' => $slug, 'parentId' => $parentId, 'relation' => $manager->getName(),
                ])
                : self::link($manager, $scope, $count);
            $items[] = ['id' => 'fa-manager-' . $manager->getName(), 'label' => $manager->getLabel(), 'icon' => Icons::svg($manager->getIcon() ?? 'layers'), 'count' => $count, 'body' => $body];
        }

        $title = Str::e(AdminLang::get('relations.managers'));
        $html = '<section class="fa-managers" aria-labelledby="fa-managers-title"><h2 class="fa-sr" id="fa-managers-title">' . $title . '</h2>';
        if (count($items) === 1) {
            $item = $items[0];
            return $html . '<div class="fa-card fa-manager" id="' . Str::e($item['id']) . '" data-admin-manager>'
                . '<div class="fa-manager-head"><h3 class="fa-manager-title">' . $item['icon'] . '<span>' . Str::e($item['label']) . '</span>'
                . self::count($item) . '</h3></div>' . $item['body'] . '</div></section>';
        }
        $buttons = ''; $panels = '';
        foreach ($items as $item) {
            $buttons .= '<button type="button" class="fa-manager-tab" id="' . Str::e($item['id'] . '-tab') . '" data-admin-tab="' . Str::e($item['id']) . '">'
                . $item['icon'] . '<span>' . Str::e($item['label']) . '</span>' . self::count($item) . '</button>';
            $panels .= '<section class="fa-manager-panel" id="' . Str::e($item['id']) . '" data-admin-tab-panel data-admin-manager aria-labelledby="' . Str::e($item['id'] . '-title') . '">'
                . '<h3 class="fa-tab-heading fa-manager-heading" id="' . Str::e($item['id'] . '-title') . '">' . Str::e($item['label']) . self::count($item) . '</h3>' . $item['body'] . '</section>';
        }
        return $html . '<div class="fa-card fa-manager fa-tabs" id="fa-managers-tabs" data-admin-tabs>'
            . '<div class="fa-tab-list fa-manager-tabs" data-admin-tab-list aria-label="' . $title . '" hidden>' . $buttons . '</div>'
            . $panels . '</div></section>';
    }

    /** The create page: a note that the managers come once the record is saved, or ''. */
    public static function pending(Resource $parent): string
    {
        $managers = self::visible($parent);
        if ($managers === []) { return ''; }
        $labels = array_map(static fn (RelationManager $manager): string => mb_strtolower($manager->getLabel()), $managers);
        $last = array_pop($labels);
        $list = $labels === [] ? $last : AdminLang::get('relations.and', ['first' => implode(AdminLang::get('form.list_separator'), $labels), 'last' => $last]);
        return '<section class="fa-managers"><div class="fa-manager-pending" role="note">'
            . '<span class="fa-manager-pending-icon" aria-hidden="true">' . Icons::svg('layers') . '</span>'
            . '<span><strong>' . Str::e(AdminLang::get('relations.pending_title', ['list' => $list])) . '</strong>'
            . '<span>' . Str::e(AdminLang::get('relations.pending_text', ['label' => mb_strtolower($parent::getLabel()), 'list' => $list])) . '</span></span>'
            . '</div></section>';
    }

    /**
     * What the delete confirmation of a parent adds (0.22): "This property has
     * 12 units and 4 enquiries." Null when it has none (or no managers).
     */
    public static function deleteCount(Resource $parent, string $parentId): ?string
    {
        if ($parent->parentScope() !== null) { return null; }
        $parts = [];
        foreach (RelationManager::of($parent) as $manager) {
            $child = $manager->getResource();
            try {
                $scope = ParentScope::resolve($parent::getSlug(), $parentId, $manager->getName(), $child::getSlug());
                $count = (new RecordRepository((new $child())->withinParent($scope), App::db() ?? throw new \RuntimeException('Database required.'), Translations::store()))->newQuery()->count();
            } catch (\FloCMS\Core\HttpException) { continue; }
            if ($count > 0) { $parts[] = AdminLang::get('relations.count_item', ['count' => $count, 'label' => mb_strtolower($count === 1 ? $child::getLabel() : $manager->getLabel())]); }
        }
        if ($parts === []) { return null; }
        $last = array_pop($parts);
        $list = $parts === [] ? $last : AdminLang::get('relations.and', ['first' => implode(AdminLang::get('form.list_separator'), $parts), 'last' => $last]);
        return AdminLang::get('relations.delete_count', ['label' => mb_strtolower($parent::getLabel()), 'list' => $list]);
    }

    /**
     * The parent a related record belongs to, for a back link on its own edit
     * page (a resource kept out of the sidebar, opened from Ctrl+K): the first
     * parent the user may edit, or null.
     *
     * @return array{url: string, title: string}|null
     */
    public static function parentOf(Resource $child, object $record): ?array
    {
        $db = App::db();
        if ($db === null) { return null; }
        foreach (RelationManager::parentsOf($child, $record) as $note) {
            [$slug, $id] = explode(':', $note, 2);
            $class = Admin::registry()->find($slug);
            if ($class === null || !Auth::can($class::permission('update'))) { continue; }
            $parent = new $class();
            $row = (new RecordRepository($parent, $db, Translations::store()))->find($id);
            if ($row === null || !$parent->canEdit($row)) { continue; }
            return ['url' => Admin::url($slug . '/edit/' . $id), 'title' => \FloCMS\Admin\Actions\ActionExecutor::label($parent, $row)];
        }
        return null;
    }

    /** Reactive off: the manager is a link to the child's list limited to this parent. */
    private static function link(RelationManager $manager, ParentScope $scope, int $count): string
    {
        $url = Admin::url($manager->getResource()::getSlug(), ['static' => Admin::config()->reactiveEnabled() ? 1 : null, 'parent' => $scope->token()]);
        return '<div class="fa-manager-link"><p class="fa-muted">' . Str::e($count === 0 ? $manager->getEmptyText() : AdminLang::get('relations.manager_count', ['count' => $count, 'label' => mb_strtolower($manager->getLabel())])) . '</p>'
            . '<a class="fa-btn" href="' . Str::e($url) . '">' . Str::e(AdminLang::get('relations.manager_page', ['label' => $manager->getLabel()])) . '</a></div>';
    }

    /** @param array{id: string, count: int} $item */
    private static function count(array $item): string
    {
        return '<span class="fa-count fa-ltr" data-admin-manager-count-for="' . Str::e($item['id']) . '">' . $item['count'] . '</span>';
    }
}
