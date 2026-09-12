<?php

namespace App\Filament\Navigation;

use App\Filament\Clusters\Devices\DevicesCluster;
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
use App\Filament\Pages\RequestContent;
use App\Filament\Resources\AedProfiles\AedProfileResource;
use App\Filament\Resources\Assets\AssetResource;
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
use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
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
                    'playlists' => ['resolve' => fn () => PlaylistResource::getNavigationItems()],
                    'custom_playlists' => ['resolve' => fn () => CustomPlaylistResource::getNavigationItems()],
                    'merged_playlists' => ['resolve' => fn () => MergedPlaylistResource::getNavigationItems()],
                    'playlist_aliases' => ['resolve' => fn () => PlaylistAliasesCluster::getNavigationItems()],
                    'playlist_viewers' => ['resolve' => fn () => PlaylistViewerResource::getNavigationItems()],
                    'playlist_auths' => ['resolve' => fn () => PlaylistAuthResource::getNavigationItems()],
                    'stream_file_settings' => ['resolve' => fn () => StreamFileSettingResource::getNavigationItems()],
                    'channel_scrubbers' => ['resolve' => fn () => ChannelScrubberResource::getNavigationItems()],
                ],
            ],
            'dvr' => [
                'label' => fn () => __('DVR'),
                'icon' => 'heroicon-m-video-camera',
                'available' => fn () => auth()->user()?->canUseDvr() ?? false,
                'items' => [
                    'dvr_recordings' => ['resolve' => fn () => DvrRecordingResource::getNavigationItems()],
                    'dvr_recording_rules' => ['resolve' => fn () => DvrRecordingRuleResource::getNavigationItems()],
                    'browse_shows' => ['resolve' => fn () => BrowseShows::getNavigationItems()],
                ],
            ],
            'integrations' => [
                'label' => fn () => __('Integrations'),
                'icon' => 'heroicon-m-server-stack',
                'available' => fn () => true,
                'items' => [
                    'media_server_integrations' => ['resolve' => fn () => MediaServerIntegrationResource::getNavigationItems()],
                    'request_content' => ['resolve' => fn () => RequestContent::getNavigationItems()],
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
                    'groups' => ['resolve' => fn () => GroupResource::getNavigationItems()],
                    'channels' => ['resolve' => fn () => ChannelResource::getNavigationItems()],
                ],
            ],
            'vod_channels' => [
                'label' => fn () => __('VOD Channels'),
                'icon' => 'heroicon-m-film',
                'available' => fn () => true,
                'items' => [
                    'vod_groups' => ['resolve' => fn () => VodGroupResource::getNavigationItems()],
                    'vod_dynamic_groups' => ['resolve' => fn () => VodDynamicGroupResource::getNavigationItems()],
                    'vods' => ['resolve' => fn () => VodResource::getNavigationItems()],
                ],
            ],
            'series' => [
                'label' => fn () => __('Series'),
                'icon' => 'heroicon-m-play',
                'available' => fn () => true,
                'items' => [
                    'categories' => ['resolve' => fn () => CategoryResource::getNavigationItems()],
                    'series' => ['resolve' => fn () => SeriesResource::getNavigationItems()],
                    'series_dynamic_groups' => ['resolve' => fn () => SeriesDynamicGroupResource::getNavigationItems()],
                ],
            ],
            'epg' => [
                'label' => fn () => __('EPG'),
                'icon' => 'heroicon-m-calendar-days',
                'available' => fn () => true,
                'items' => [
                    'epgs' => ['resolve' => fn () => EpgResource::getNavigationItems()],
                    'merged_epgs' => ['resolve' => fn () => MergedEpgResource::getNavigationItems()],
                    'epg_channels' => ['resolve' => fn () => EpgChannelResource::getNavigationItems()],
                    'epg_maps' => ['resolve' => fn () => EpgMapResource::getNavigationItems()],
                    'aed_profiles' => ['resolve' => fn () => AedProfileResource::getNavigationItems()],
                ],
            ],
            'proxy' => [
                'label' => fn () => __('Proxy'),
                'icon' => 'heroicon-m-arrows-right-left',
                'available' => fn () => config('proxy.proxy_integration_enabled', true) && (auth()->user()?->canUseProxy() ?? false),
                'items' => [
                    'stream_profiles' => ['resolve' => fn () => StreamProfileResource::getNavigationItems()],
                    'stream_monitor' => ['resolve' => fn () => M3uProxyStreamMonitor::getNavigationItems()],
                ],
            ],
            'plugins' => [
                'label' => fn () => __('Plugins'),
                'icon' => 'heroicon-m-puzzle-piece',
                'available' => fn () => true,
                'items' => [
                    'plugins_dashboard' => ['resolve' => fn () => PluginsDashboard::getNavigationItems()],
                    'plugins' => ['resolve' => fn () => PluginResource::getNavigationItems()],
                    'plugin_install_reviews' => ['resolve' => fn () => PluginInstallReviewResource::getNavigationItems()],
                    'create_plugin' => ['resolve' => fn () => CreatePlugin::getNavigationItems()],
                ],
            ],
            'tools' => [
                'label' => fn () => __('Tools'),
                'icon' => 'heroicon-m-wrench-screwdriver',
                'collapsed' => true,
                'available' => fn () => true,
                'items' => [
                    'personal_access_tokens' => ['resolve' => fn () => PersonalAccessTokenResource::getNavigationItems()],
                    'assets' => ['resolve' => fn () => AssetResource::getNavigationItems()],
                    'post_processes' => ['resolve' => fn () => PostProcessResource::getNavigationItems()],
                    'log_viewer' => ['resolve' => fn () => LogViewer::getNavigationItems()],
                    'release_logs' => ['resolve' => fn () => ReleaseLogs::getNavigationItems()],
                    'backups' => ['resolve' => fn () => Backups::getNavigationItems()],
                    'queue_monitor' => ['resolve' => fn () => QueueMonitorResource::getNavigationItems()],
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

    private static function isAdmin(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
