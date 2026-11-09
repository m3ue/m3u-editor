<?php

namespace App\Filament\Navigation;

use App\Filament\Clusters\Devices\DevicesCluster;
use App\Filament\Clusters\MediaServers\MediaServersCluster;
use App\Filament\Clusters\PlaylistAliases\PlaylistAliasesCluster;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Backups;
use App\Filament\Pages\BrowseShows;
use App\Filament\Pages\CreatePlugin;
use App\Filament\Pages\CustomDashboard;
use App\Filament\Pages\LogViewer;
use App\Filament\Pages\M3uProxyStreamMonitor;
use App\Filament\Pages\PluginsDashboard;
use App\Filament\Pages\ReleaseLogs;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\AedProfiles\AedProfileResource;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\CachedContentFiles\CachedContentFileResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Filament\Resources\DvrRecordingRules\DvrRecordingRuleResource;
use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
use App\Filament\Resources\EpgChannels\EpgChannelResource;
use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Filament\Resources\Epgs\EpgResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\MergedEpgs\MergedEpgResource;
use App\Filament\Resources\MergedPlaylists\MergedPlaylistResource;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Resources\PersonalAccessTokens\PersonalAccessTokenResource;
use App\Filament\Resources\PlaylistAuths\PlaylistAuthResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\PlaylistViewers\PlaylistViewerResource;
use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Filament\Resources\Plugins\PluginResource;
use App\Filament\Resources\PostProcesses\PostProcessResource;
use App\Filament\Resources\QueueMonitor\QueueMonitorResource;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\SeriesDynamicGroups\SeriesDynamicGroupResource;
use App\Filament\Resources\StreamFileSettings\StreamFileSettingResource;
use App\Filament\Resources\StreamProfiles\StreamProfileResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VodDynamicGroups\VodDynamicGroupResource;
use App\Filament\Resources\VodGroups\VodGroupResource;
use App\Filament\Resources\Vods\VodResource;
use Filament\Navigation\NavigationItem;

/**
 * Canonical definition of the admin panel's sidebar: which groups and items
 * exist, in what order by default (the "Default" layout), with their fixed
 * label/icon and the conditions under which they are available at all.
 *
 * This is the single source of truth for navigation content. It does not
 * decide final order/visibility for a given request/user - that merging is
 * done by AdminNavigationMenuBuilder, which layers an admin-configured
 * layout (reorder/hide) on top of the order defined here while always
 * respecting the `available` callbacks below.
 *
 * Names and icons are intentionally not configurable (they're referenced in
 * documentation) - only order and visibility can be changed via Settings >
 * Navigation.
 */
final class AdminNavigationSchema
{
    /**
     * Ungrouped top-level items, always shown first.
     *
     * @return list<NavigationItem>
     */
    public static function topItems(): array
    {
        return [
            ...CustomDashboard::getNavigationItems(),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings  Resolved GeneralSettings values, as built in AdminPanelProvider.
     * @return array<string, array{label: \Closure, icon: string, collapsed?: bool, available: \Closure, items: array<string, array{available?: \Closure, resolve: \Closure}>}>
     */
    public static function groups(array $settings): array
    {
        return [
            'administration' => [
                'label' => fn () => __('Administration'),
                'icon' => 'heroicon-s-shield-check',
                'available' => fn () => self::isAdmin(),
                'items' => [
                    'users' => [
                        'available' => fn () => ! config('auth.auto_login'),
                        'resolve' => fn () => UserResource::getNavigationItems(),
                    ],
                    'devices' => [
                        'available' => fn () => $settings['push_relay_enabled'] || $settings['device_pairing_enabled'],
                        'resolve' => fn () => DevicesCluster::getNavigationItems(),
                    ],
                    'settings' => [
                        'resolve' => fn () => SettingsCluster::getNavigationItems(),
                    ],
                ],
            ],
            'playlist' => [
                'label' => fn () => __('Playlist'),
                'icon' => 'heroicon-m-play-pause',
                'available' => fn () => true,
                'items' => [
                    'playlists' => self::entry(PlaylistResource::class),
                    'custom_playlists' => self::entry(CustomPlaylistResource::class),
                    'merged_playlists' => self::entry(MergedPlaylistResource::class),
                    'playlist_aliases' => self::entry(PlaylistAliasesCluster::class),
                    'playlist_viewers' => self::entry(PlaylistViewerResource::class),
                    'playlist_auths' => self::entry(PlaylistAuthResource::class),
                    'stream_file_settings' => self::entry(StreamFileSettingResource::class),
                    'channel_scrubbers' => self::entry(ChannelScrubberResource::class),
                    'cached_downloads' => [
                        // Hidden while caching is off, matching the resource's canAccess().
                        'available' => fn () => CachedContentFileResource::canAccess(),
                        'resolve' => fn () => CachedContentFileResource::getNavigationItems(),
                    ],
                ],
            ],
            'dvr' => [
                'label' => fn () => __('DVR'),
                'icon' => 'heroicon-m-video-camera',
                'available' => fn () => auth()->user()?->canUseDvr() ?? false,
                'items' => [
                    'dvr_recordings' => self::entry(DvrRecordingResource::class),
                    'dvr_recording_rules' => self::entry(DvrRecordingRuleResource::class),
                    'browse_shows' => self::entry(BrowseShows::class),
                ],
            ],
            'integrations' => [
                'label' => fn () => __('Integrations'),
                'icon' => 'heroicon-m-server-stack',
                'available' => fn () => true,
                'items' => [
                    'media_server_integrations' => self::entry(MediaServersCluster::class),
                    'networks' => [
                        'available' => fn () => config('proxy.proxy_integration_enabled', true),
                        'resolve' => fn () => NetworkResource::getNavigationItems(),
                    ],
                ],
            ],
            'live_channels' => [
                'label' => fn () => __('Live Channels'),
                'icon' => 'heroicon-m-tv',
                'available' => fn () => true,
                'items' => [
                    'groups' => self::entry(GroupResource::class),
                    'channels' => self::entry(ChannelResource::class),
                ],
            ],
            'vod_channels' => [
                'label' => fn () => __('VOD Channels'),
                'icon' => 'heroicon-m-film',
                'available' => fn () => true,
                'items' => [
                    'vod_groups' => self::entry(VodGroupResource::class),
                    'vod_dynamic_groups' => self::entry(VodDynamicGroupResource::class),
                    'vods' => self::entry(VodResource::class),
                ],
            ],
            'series' => [
                'label' => fn () => __('Series'),
                'icon' => 'heroicon-m-play',
                'available' => fn () => true,
                'items' => [
                    'categories' => self::entry(CategoryResource::class),
                    'series_dynamic_groups' => self::entry(SeriesDynamicGroupResource::class),
                    'series' => self::entry(SeriesResource::class),
                ],
            ],
            'epg' => [
                'label' => fn () => __('EPG'),
                'icon' => 'heroicon-m-calendar-days',
                'available' => fn () => true,
                'items' => [
                    'epgs' => self::entry(EpgResource::class),
                    'merged_epgs' => self::entry(MergedEpgResource::class),
                    'epg_channels' => self::entry(EpgChannelResource::class),
                    'epg_maps' => self::entry(EpgMapResource::class),
                    'aed_profiles' => self::entry(AedProfileResource::class),
                ],
            ],
            'proxy' => [
                'label' => fn () => __('Proxy'),
                'icon' => 'heroicon-m-arrows-right-left',
                'available' => fn () => config('proxy.proxy_integration_enabled', true) && (auth()->user()?->canUseProxy() ?? false),
                'items' => [
                    'stream_profiles' => self::entry(StreamProfileResource::class),
                    'stream_monitor' => self::entry(M3uProxyStreamMonitor::class),
                ],
            ],
            'plugins' => [
                'label' => fn () => __('Plugins'),
                'icon' => 'heroicon-m-puzzle-piece',
                'available' => fn () => true,
                'items' => [
                    'plugins_dashboard' => self::entry(PluginsDashboard::class),
                    'plugins' => self::entry(PluginResource::class),
                    'plugin_install_reviews' => self::entry(PluginInstallReviewResource::class),
                    'create_plugin' => self::entry(CreatePlugin::class),
                ],
            ],
            'tools' => [
                'label' => fn () => __('Tools'),
                'icon' => 'heroicon-m-wrench-screwdriver',
                'collapsed' => true,
                'available' => fn () => true,
                'items' => [
                    'personal_access_tokens' => self::entry(PersonalAccessTokenResource::class),
                    'assets' => self::entry(AssetResource::class),
                    'post_processes' => self::entry(PostProcessResource::class),
                    'log_viewer' => self::entry(LogViewer::class),
                    'release_logs' => self::entry(ReleaseLogs::class),
                    'system_health' => self::entry(SystemHealth::class),
                    'backups' => self::entry(Backups::class),
                    'queue_monitor' => self::entry(QueueMonitorResource::class),
                    'api_docs' => [
                        'resolve' => fn () => [
                            NavigationItem::make('API Docs')
                                ->key('api_docs')
                                ->label(fn () => __('API Docs').' ↗')
                                ->url('/docs/api', shouldOpenInNewTab: true)
                                ->icon(null)
                                ->visible(self::isAdmin(...)),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * A page or resource entry, gated the way Filament's own
     * registerNavigationItems() gates it. This menu replaces Filament's
     * default navigation and calls getNavigationItems() directly, so without
     * this an admin-only page or a feature-flagged resource would still be
     * linked for users who cannot open it.
     *
     * @param  class-string  $class
     * @return array{available: \Closure, resolve: \Closure}
     */
    private static function entry(string $class): array
    {
        return [
            'available' => fn (): bool => $class::shouldRegisterNavigation() && $class::canAccess(),
            'resolve' => fn () => $class::getNavigationItems(),
        ];
    }

    private static function isAdmin(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
