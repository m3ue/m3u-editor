<?php

namespace App\Filament\Navigation;

use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

/**
 * Builds the admin panel's final sidebar navigation by merging the canonical
 * layout defined in AdminNavigationSchema with the admin-configured order
 * and visibility stored on GeneralSettings (Settings > Navigation), via
 * AdminNavigationLayout.
 *
 * The stored layout can only reorder or hide groups/items that are already
 * available to the requesting user - the `available` callbacks in
 * AdminNavigationSchema (admin-only groups, DVR/Proxy permission checks,
 * feature flags) are evaluated on every request exactly as before and always
 * win over anything stored.
 */
class AdminNavigationMenuBuilder
{
    /**
     * @param  array<string, mixed>  $panelSettings  Resolved GeneralSettings values, as built in AdminPanelProvider.
     */
    public function build(NavigationBuilder $builder, array $panelSettings): NavigationBuilder
    {
        $schema = AdminNavigationSchema::groups($panelSettings);
        $layout = AdminNavigationLayout::resolveActive();

        $available = array_filter($schema, fn (array $group) => $group['available']());

        $groupOrder = AdminNavigationLayout::mergeOrder(array_keys($available), $layout['groups']['order'] ?? []);
        $hiddenGroups = $layout['groups']['hidden'] ?? [];

        $groups = [];
        foreach ($groupOrder as $key) {
            if (in_array($key, $hiddenGroups, true)) {
                continue;
            }

            $groupDef = $available[$key];
            $items = $this->resolveItems($groupDef['items'], $layout['items'][$key] ?? []);

            if ($items === []) {
                // Mirrors NavigationBuilder::getNavigation(), which drops groups with no visible items.
                continue;
            }

            $groups[] = NavigationGroup::make($groupDef['label'])
                ->icon($groupDef['icon'])
                ->collapsed($groupDef['collapsed'] ?? false)
                ->items($items);
        }

        return $builder
            ->items(AdminNavigationSchema::topItems())
            ->groups($groups);
    }

    /**
     * @param  array<string, array{available?: \Closure, resolve: \Closure}>  $itemDefs
     * @param  array{order?: list<string>, hidden?: list<string>}  $itemLayout
     * @return list<NavigationItem>
     */
    private function resolveItems(array $itemDefs, array $itemLayout): array
    {
        $available = array_filter(
            $itemDefs,
            fn (array $item) => ! isset($item['available']) || $item['available']()
        );

        $order = AdminNavigationLayout::mergeOrder(array_keys($available), $itemLayout['order'] ?? []);
        $hidden = $itemLayout['hidden'] ?? [];

        $items = [];
        foreach ($order as $key) {
            if (in_array($key, $hidden, true)) {
                continue;
            }

            foreach ($available[$key]['resolve']() as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
