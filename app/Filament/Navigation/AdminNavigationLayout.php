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
     * Groups hidden in the Simplified Default preset shipped with the app.
     * Values are AdminNavigationSchema group keys.
     *
     * @var list<string>
     */
    private const SIMPLIFIED_HIDDEN_GROUPS = [
        'dvr',
        'plugins',
    ];

    /**
     * Group order for the shipped Simplified Default preset. Groups not
     * listed here keep their canonical order, appended after the ones that
     * are. Only difference from canonical: Integrations moved below EPG.
     *
     * @var list<string>
     */
    private const SIMPLIFIED_GROUP_ORDER = [
        'administration',
        'playlist',
        'dvr',
        'live_channels',
        'vod_channels',
        'series',
        'epg',
        'integrations',
        'proxy',
        'plugins',
        'tools',
    ];

    /**
     * Items hidden per group in the shipped Simplified Default preset, keyed
     * by group key; values are AdminNavigationSchema item keys within that
     * group.
     *
     * @var array<string, list<string>>
     */
    private const SIMPLIFIED_HIDDEN_ITEMS = [
        'playlist' => [
            'custom_playlists',
            'merged_playlists',
            'playlist_aliases',
            'playlist_viewers',
            'stream_file_settings',
            'channel_scrubbers',
        ],
        'integrations' => [
            'request_content',
            'networks',
        ],
        'epg' => [
            'merged_epgs',
            'epg_channels',
            'aed_profiles',
        ],
        'tools' => [
            'personal_access_tokens',
            'post_processes',
            'release_logs',
            'queue_monitor',
            'api_docs',
        ],
    ];

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

        if ($settings->admin_nav_active_preset === 'simplified') {
            // Defensive: "Use Simplified Default" writes the resolved layout to
            // admin_nav_layout, so this branch only matters if that value was later
            // cleared without resetting the preset. Falls back to the shipped layout,
            // exactly as the "default" preset falls back to the canonical schema order.
            return self::simplifiedDefault(self::panelSettings($settings));
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
     * The "Simplified Default" navigation layout shipped with the app,
     * applied on every install whenever the Simplified Default preset is
     * selected (Settings > Navigation > "Use Simplified Default"). Mirrors
     * how the "default" preset is the canonical schema order rather than a
     * stored value.
     *
     * Shape it by editing the SIMPLIFIED_* constants at the top of this
     * class; anything not named there stays in canonical order and visible.
     *
     * @param  array<string, mixed>  $panelSettings
     * @return array{groups: array{order: list<string>, hidden: list<string>}, items: array<string, array{order: list<string>, hidden: list<string>}>}
     */
    public static function simplifiedDefault(array $panelSettings): array
    {
        $layout = self::canonicalSnapshot($panelSettings);

        $layout['groups']['order'] = self::mergeOrder($layout['groups']['order'], self::SIMPLIFIED_GROUP_ORDER);
        $layout['groups']['hidden'] = array_values(
            array_intersect(self::SIMPLIFIED_HIDDEN_GROUPS, $layout['groups']['order'])
        );

        foreach (self::SIMPLIFIED_HIDDEN_ITEMS as $groupKey => $itemKeys) {
            if (! isset($layout['items'][$groupKey])) {
                continue;
            }

            $layout['items'][$groupKey]['hidden'] = array_values(
                array_intersect($itemKeys, $layout['items'][$groupKey]['order'])
            );
        }

        return $layout;
    }

    /**
     * Panel-availability settings (feature toggles that gate whole nav
     * groups) pulled straight from GeneralSettings, matching how
     * AdminPanelProvider builds them for the live sidebar.
     *
     * @return array{push_relay_enabled: bool, device_pairing_enabled: bool}
     */
    private static function panelSettings(GeneralSettings $settings): array
    {
        return [
            'push_relay_enabled' => $settings->push_relay_enabled ?? true,
            'device_pairing_enabled' => $settings->device_pairing_enabled ?? true,
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
