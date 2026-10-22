<?php
declare(strict_types=1);

namespace FloCMS\Admin\PanelPages;

use FloCMS\Admin\Admin;
use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Support\Icons;
use FloCMS\Admin\Support\Log;

/**
 * The site's panel pages (0.36) as the sidebar, search and Roles page see them.
 *
 * @internal
 */
final class CustomPages
{
    /** The activity log's "resource" of a page's entries. */
    public const ACTIVITY = 'panel-page';

    /** The longest badge text shown on a sidebar item. */
    private const BADGE_LENGTH = 8;

    /**
     * Pages the signed-in person may open, in sidebar order.
     *
     * @return array<string, AdminPage>
     */
    public static function allowed(): array
    {
        $pages = [];
        try {
            foreach (Admin::panelPages()->all() as $slug => $class) {
                $page = new $class();
                if ($page->allowed()) { $pages[$slug] = $page; }
            }
        } catch (\Throwable $error) {
            Log::error('Panel pages could not be listed: ' . $error->getMessage());
        }
        return $pages;
    }

    /**
     * Sidebar items of the pages in the sidebar: those without a group (shown
     * under Content) and the groups, ordered by their lowest sort.
     *
     * @return array{content: list<array<string, mixed>>, groups: list<array{label: string, items: list<array<string, mixed>>}>}
     */
    public static function navigation(?string $activeSlug): array
    {
        $content = [];
        $groups = [];
        foreach (self::allowed() as $slug => $page) {
            if (!$page::showsInNavigation()) { continue; }
            $item = [
                'label' => $page::getLabel(),
                'url' => Admin::url($slug),
                'active' => $slug === $activeSlug,
                'icon' => Icons::svg($page::getIcon()),
                'badge' => self::badge($page),
            ];
            $group = $page::getGroup();
            if ($group === null) { $content[] = $item; continue; }
            $groups[$group] ??= ['label' => $group, 'sort' => $page::getSort(), 'items' => []];
            $groups[$group]['sort'] = min($groups[$group]['sort'], $page::getSort());
            $groups[$group]['items'][] = $item;
        }
        uasort($groups, static fn (array $a, array $b): int => [$a['sort'], $a['label']] <=> [$b['sort'], $b['label']]);
        return ['content' => $content, 'groups' => array_values(array_map(static fn (array $g): array => ['label' => $g['label'], 'items' => $g['items']], $groups))];
    }

    /** The badge text of a page's sidebar item, or null; a failing badge() is logged and left out. */
    public static function badge(AdminPage $page): ?string
    {
        try {
            $badge = $page->badge();
        } catch (\Throwable $error) {
            Log::error('Admin page ' . $page::class . ': badge() failed: ' . $error->getMessage());
            return null;
        }
        if ($badge === null) { return null; }
        $text = trim(is_int($badge) ? number_format($badge) : $badge);
        if ($text === '' || $text === '0') { return null; }
        return mb_strlen($text) > self::BADGE_LENGTH ? mb_substr($text, 0, self::BADGE_LENGTH - 1) . '…' : $text;
    }
}
