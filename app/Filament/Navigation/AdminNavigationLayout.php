<?php

namespace App\Filament\Navigation;

use App\Settings\GeneralSettings;
use Throwable;

/**
 * Shared order/visibility merge logic for the admin navigation menu, used by
 * both the live sidebar builder (AdminNavigationMenuBuilder) and the
 * Settings > Navigation editor (ManageNavigationSettings), so both always
 * agree on how a stored layout is merged with the canonical schema order.
 */
final class AdminNavigationLayout
{
    /**
     * The layout currently applied to the live sidebar for all users.
     *
     * @return array{groups?: array{order?: list<string>, hidden?: list<string>}, items?: array<string, array{order?: list<string>, hidden?: list<string>}>}
     */
    public static function resolveActive(): array
    {
        try {
            $settings = app(GeneralSettings::class);
        } catch (Throwable) {
            // Settings table not migrated yet (e.g. during initial test bootstrap).
            return [];
        }

        if (! empty($settings->admin_nav_layout)) {
            return $settings->admin_nav_layout;
        }

        if ($settings->admin_nav_active_preset === 'simplified' && ! empty($settings->admin_nav_simplified_layout)) {
            return $settings->admin_nav_simplified_layout;
        }

        return [];
    }

    /**
     * A layout snapshot of the canonical schema order exactly as-is (nothing
     * hidden) - the starting point for the "Simplified Default" preset and
     * for building the Settings > Navigation editor when no layout is
     * stored yet.
     *
     * @param  array<string, mixed>  $panelSettings
     * @return array{groups: array{order: list<string>, hidden: list<string>}, items: array<string, array{order: list<string>, hidden: list<string>}>}
     */
    public static function canonicalSnapshot(array $panelSettings): array
    {
        $schema = AdminNavigationSchema::groups($panelSettings);

        $items = [];
        foreach ($schema as $groupKey => $groupDef) {
            $items[$groupKey] = [
                'order' => array_keys($groupDef['items']),
                'hidden' => [],
            ];
        }

        return [
            'groups' => [
                'order' => array_keys($schema),
                'hidden' => [],
            ],
            'items' => $items,
        ];
    }

    /**
     * Stored order (filtered to what's currently available) first, then any
     * available keys not yet in the stored order, in their canonical
     * relative order - so newly added resources/groups keep appearing
     * automatically without needing to touch a saved layout.
     *
     * @param  list<string>  $availableKeys
     * @param  list<string>  $storedOrder
     * @return list<string>
     */
    public static function mergeOrder(array $availableKeys, array $storedOrder): array
    {
        $ordered = array_values(array_intersect($storedOrder, $availableKeys));
        $remaining = array_values(array_diff($availableKeys, $ordered));

        return [...$ordered, ...$remaining];
    }
}
