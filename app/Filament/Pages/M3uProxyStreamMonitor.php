<?php

namespace App\Filament\Pages;

use App\Facades\LogoFacade;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistProfile;
use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use App\Services\PlaylistUrlService;
use Carbon\Carbon;
use Exception;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Illuminate\Support\Facades\Log;

/**
 * Shared Stream Monitor (External API-backed)
 *
 * Uses the external m3u-proxy server API to populate and manage streams.
 */
class M3uProxyStreamMonitor extends Page
{
    public static function getNavigationLabel(): string
    {
        return __('Stream Monitor');
    }

    public function getTitle(): string
    {
        return __('M3U Proxy Stream Monitor');
    }

    /**
     * Check if the user can access this page.
     * Only admin users can access the Preferences page.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseProxy();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Proxy');
    }

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.m3u-proxy-stream-monitor';

    public $streams = [];

    public $globalStats = [];

    public $systemStats = [];

    public $refreshInterval = 5; // seconds (default; the client persists its own choice in localStorage)

    public ?string $lastUpdatedAt = null;

    public $connectionError = null;

    protected M3uProxyService $apiService;

    public function boot(): void
    {
        $this->apiService = app(M3uProxyService::class);
    }

    public function mount(): void
    {
        $this->refreshData();
    }

    public function refreshData(): void
    {
        $this->streams = $this->getActiveStreams();

        $totalClients = array_sum(array_map(fn ($s) => $s['client_count'] ?? 0, $this->streams));
        $totalBandwidth = array_sum(array_map(fn ($s) => $s['bandwidth_kbps'] ?? 0, $this->streams));
        $activeStreams = count(array_filter($this->streams, fn ($s) => $s['status'] === 'active'));

        $this->globalStats = [
            'total_streams' => count($this->streams),
            'active_streams' => $activeStreams,
            'total_clients' => $totalClients,
            'total_bandwidth_kbps' => round($totalBandwidth, 2),
            'avg_clients_per_stream' => count($this->streams) > 0
                ? number_format($totalClients / count($this->streams), 2)
                : '0.00',
        ];

        $this->systemStats = []; // populate if external API provides system metrics

        $this->lastUpdatedAt = now()->toIso8601String();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('Refresh'))
                ->icon('heroicon-o-arrow-path')
                ->size(Size::Small)
                ->action('refreshData'),

            // Action::make('cleanup')
            //     ->label(__('Cleanup Streams'))
            //     ->icon('heroicon-o-trash')
            //     ->size(Size::Small)
            //     ->color('danger')
            //     ->requiresConfirmation()
            //     ->modalDescription(__('This will stop all inactive streams via external API.'))
            //     ->action(function (): void {
            //         // If external API exposes a cleanup endpoint add call here
            //         Notification::make()->title(__('Cleanup requested.'))->success()->send();
            //         $this->refreshData();
            //     }),
        ];
    }

    public function triggerFailover(string $streamId): void
    {
        if (! $this->authorizeStreamAction($streamId)) {
            return;
        }

        try {
            $success = $this->apiService->triggerFailover($streamId);
            if ($success) {
                Notification::make()
                    ->title("Failover triggered for stream {$streamId}.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title("Failed to trigger failover for stream {$streamId}.")
                    ->danger()
                    ->send();
            }
        } catch (Exception $e) {
            Notification::make()
                ->title(__('Error triggering failover.'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->refreshData();
    }

    public function stopStream(string $streamId): void
    {
        if (! $this->authorizeStreamAction($streamId)) {
            return;
        }

        try {
            // Support stopping broadcasts via a special stream ID prefix
            if (str_starts_with($streamId, 'broadcast:')) {
                $networkId = substr($streamId, 10);
                $success = $this->apiService->stopBroadcast($networkId);

                if ($success) {
                    Notification::make()
                        ->title("Broadcast for network {$networkId} stopped successfully.")
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title("Failed to stop broadcast for network {$networkId}.")
                        ->danger()
                        ->send();
                }

                $this->refreshData();

                return;
            }

            $success = $this->apiService->stopStream($streamId);
            if ($success) {
                Notification::make()
                    ->title("Stream {$streamId} stopped successfully.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title("Failed to stop stream {$streamId}.")
                    ->danger()
                    ->send();
            }
        } catch (Exception $e) {
            Notification::make()
                ->title(__('Error stopping stream.'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->refreshData();
    }

    /**
     * Verify the authenticated user owns the stream or broadcast referenced by $streamId.
     * Emits a user-facing notification and logs a warning on failure.
     */
    private function authorizeStreamAction(string $streamId): bool
    {
        if (str_starts_with($streamId, 'broadcast:')) {
            $networkUuid = substr($streamId, strlen('broadcast:'));
            $owned = Network::where('uuid', $networkUuid)
                ->where('user_id', auth()->id())
                ->exists();
        } else {
            $visible = $this->apiService->fetchActiveStreams();
            $owned = collect($visible['streams'] ?? [])
                ->contains(fn ($s) => ($s['stream_id'] ?? null) === $streamId);
        }

        if (! $owned) {
            Log::warning('Unauthorized stream-monitor action blocked', [
                'user_id' => auth()->id(),
                'stream_id' => $streamId,
            ]);

            Notification::make()
                ->title(__('Not authorized to manage this stream.'))
                ->danger()
                ->send();
        }

        return $owned;
    }

    protected function getActiveStreams(): array
    {
        $apiStreams = $this->apiService->fetchActiveStreams();
        $apiClients = $this->apiService->fetchActiveClients();
        $apiBroadcasts = $this->apiService->fetchBroadcasts();

        // Check for connection errors
        if (! $apiStreams['success']) {
            $this->connectionError = $apiStreams['error'] ?? 'Unknown error connecting to m3u-proxy';

            return [];
        }

        if (! $apiClients['success']) {
            $this->connectionError = $apiClients['error'] ?? 'Unknown error connecting to m3u-proxy';

            return [];
        }

        // Clear any previous errors
        $this->connectionError = null;

        $streams = [];
        if (! empty($apiStreams['streams'])) {
            // Group clients by stream_id for easier lookup
            $clientsByStream = collect($apiClients['clients'] ?? [])
                ->groupBy('stream_id')
                ->toArray();

            // Pre-fetch alias and playlist data for all playlist UUIDs to avoid N+1
            $playlistUuids = collect($apiStreams['streams'])
                ->pluck('metadata.playlist_uuid')
                ->filter()
                ->unique()
                ->values();
            $aliasNamesByUuid = PlaylistAlias::whereIn('uuid', $playlistUuids)
                ->with('playlist:id,name,profiles_enabled')
                ->get()
                ->keyBy('uuid');
            $playlistsByUuid = Playlist::whereIn('uuid', $playlistUuids)
                ->get(['id', 'uuid', 'name', 'profiles_enabled'])
                ->keyBy('uuid');

            // Pre-fetch Channel and Episode models referenced by stream metadata
            $channelIds = collect($apiStreams['streams'])
                ->filter(fn ($s) => ($s['metadata']['type'] ?? null) === 'channel')
                ->pluck('metadata.id')
                ->filter()
                ->unique()
                ->values();
            $episodeIds = collect($apiStreams['streams'])
                ->filter(fn ($s) => ($s['metadata']['type'] ?? null) === 'episode')
                ->pluck('metadata.id')
                ->filter()
                ->unique()
                ->values();

            $channelsById = $channelIds->isNotEmpty()
                ? Channel::whereIn('id', $channelIds)
                    ->with('failoverChannels')
                    ->get()
                    ->keyBy('id')
                : collect();
            $episodesById = $episodeIds->isNotEmpty()
                ? Episode::whereIn('id', $episodeIds)->get()->keyBy('id')
                : collect();

            // Pre-fetch transcoding (StreamProfile) and provider (PlaylistProfile) profiles
            $streamProfileIds = collect($apiStreams['streams'])
                ->pluck('metadata.profile_id')
                ->filter()
                ->unique()
                ->values();
            $streamProfilesById = $streamProfileIds->isNotEmpty()
                ? StreamProfile::whereIn('id', $streamProfileIds)->get(['id', 'format', 'backend'])->keyBy('id')
                : collect();

            $providerProfileIds = collect($apiStreams['streams'])
                ->pluck('metadata.provider_profile_id')
                ->filter()
                ->unique()
                ->values();
            $providerProfilesById = $providerProfileIds->isNotEmpty()
                ? PlaylistProfile::whereIn('id', $providerProfileIds)->get(['id', 'name', 'is_primary'])->keyBy('id')
                : collect();

            foreach ($apiStreams['streams'] as $stream) {
                $streamId = $stream['stream_id'];
                $streamClients = $clientsByStream[$streamId] ?? [];

                // Get model information if metadata exists
                $model = [];
                $title = null;
                $logo = null;
                $failoverChannel = null;
                if (isset($stream['metadata']['type']) && isset($stream['metadata']['id'])) {
                    $modelType = $stream['metadata']['type'];
                    $modelId = $stream['metadata']['id'];
                    if ($modelType === 'channel') {
                        $channel = $channelsById[$modelId] ?? null;
                        if ($channel) {
                            $title = $channel->name_custom ?? $channel->name ?? $channel->title;
                            $logo = LogoFacade::getChannelLogoUrl($channel);
                        }
                    } elseif ($modelType === 'episode') {
                        $episode = $episodesById[$modelId] ?? null;
                        if ($episode) {
                            $title = $episode->title;
                            $logo = LogoFacade::getEpisodeLogoUrl($episode);
                        }
                    }
                    if ($title || $logo) {
                        $model = [
                            'title' => $title ?? 'N/A',
                            'logo' => $logo,
                        ];
                    }

                    // Enrich with media info. Live ffmpeg data from the proxy wins where
                    // present; stored ffprobe stats fill in everything else (and act as
                    // the sole source for plain HTTP-proxy streams that don't transcode).
                    if ($modelType === 'channel') {
                        $channel = $channelsById[$modelId] ?? null;
                        if ($channel) {
                            $emby = $channel->getEmbyStreamStats();
                            $probeMediaInfo = ! empty($emby) ? [
                                'resolution' => $emby['resolution'] ?? null,
                                'video_codec' => $emby['video_codec'] ?? null,
                                'video_profile' => $emby['video_profile'] ?? null,
                                'source_fps' => $emby['source_fps'] ?? null,
                                'video_bitrate_kbps' => $emby['ffmpeg_output_bitrate'] ?? null,
                                'audio_codec' => $emby['audio_codec'] ?? null,
                                'audio_channels' => $emby['audio_channels'] ?? null,
                                'audio_bitrate_kbps' => $emby['audio_bitrate'] ?? null,
                                'audio_language' => $emby['audio_language'] ?? null,
                            ] : [];

                            $liveMediaInfo = $stream['media_info'] ?? [];
                            $live = [];
                            if (! empty($liveMediaInfo)) {
                                $live = array_filter([
                                    'resolution' => $liveMediaInfo['resolution'] ?? null,
                                    'video_codec' => $liveMediaInfo['video_codec'] ?? null,
                                    'source_fps' => $liveMediaInfo['fps'] ?? null,
                                    'video_bitrate_kbps' => $liveMediaInfo['bitrate_kbps'] ?? null,
                                    'audio_codec' => $liveMediaInfo['audio_codec'] ?? null,
                                    'audio_channels' => $liveMediaInfo['audio_channels'] ?? null,
                                ], fn ($v) => $v !== null && $v !== '');
                            }
                            $merged = array_merge($probeMediaInfo, $live);

                            if (! empty($merged)) {
                                if (! empty($live)) {
                                    $merged['is_live'] = true;
                                }
                                $model['media_info'] = $merged;
                            }

                            // When the proxy is on a failover URL, identify which configured
                            // failover channel is currently in use. URL match handles dynamic
                            // resolver mode (where current_failover_index doesn't necessarily
                            // line up with the candidate's slot in failoverChannels). We fall
                            // back to index lookup for the static-list mode.
                            $currentUrl = (string) ($stream['current_url'] ?? '');
                            $originalUrl = (string) ($stream['original_url'] ?? '');
                            $currentFailoverIndex = (int) ($stream['current_failover_index'] ?? 0);
                            $failoverActive = $currentFailoverIndex > 0
                                || ($stream['failover_attempts'] ?? 0) > 0;

                            if ($failoverActive && $currentUrl !== '' && $currentUrl !== $originalUrl) {
                                foreach ($channel->failoverChannels as $candidate) {
                                    try {
                                        $candidateUrl = PlaylistUrlService::getChannelUrl($candidate);
                                    } catch (Exception $e) {
                                        continue;
                                    }
                                    if ($candidateUrl !== '' && $candidateUrl === $currentUrl) {
                                        $failoverChannel = [
                                            'title' => $candidate->name_custom ?? $candidate->name ?? $candidate->title,
                                        ];
                                        break;
                                    }
                                }

                                if (! $failoverChannel && $currentFailoverIndex > 0) {
                                    $candidate = $channel->failoverChannels[$currentFailoverIndex - 1] ?? null;
                                    if ($candidate) {
                                        $failoverChannel = [
                                            'title' => $candidate->name_custom ?? $candidate->name ?? $candidate->title,
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }

                // Calculate uptime
                $startedAt = Carbon::parse($stream['created_at'], 'UTC');
                $uptime = $startedAt->diffForHumans(null, true);

                // Format bytes transferred
                $bytesTransferred = $this->formatBytes($stream['total_bytes_served']);

                // Calculate bandwidth (approximate based on bytes and time)
                $durationSeconds = $startedAt->diffInSeconds(now());
                $bandwidthKbps = $durationSeconds > 0
                    ? round(($stream['total_bytes_served'] * 8) / $durationSeconds / 1000, 2)
                    : 0;

                // Normalize clients
                $clients = array_map(function ($client) {
                    $connectedAt = Carbon::parse($client['created_at'], 'UTC');
                    $lastAccess = Carbon::parse($client['last_access'], 'UTC');

                    // Client is considered active if:
                    // 1. is_connected is true (from API), OR
                    // 2. last_access was within the last 30 seconds (more lenient for active streaming)
                    $isActive = ($client['is_connected'] ?? false) || $lastAccess->diffInSeconds(now()) < 30;

                    return [
                        'ip' => $client['ip_address'],
                        'username' => $client['username'] ?? null,
                        'user_agent' => $client['user_agent'] ?? null,
                        'connected_at' => $connectedAt->format('Y-m-d H:i:s'),
                        'duration' => $connectedAt->diffForHumans(null, true),
                        'bytes_received' => $this->formatBytes($client['bytes_served']),
                        'is_active' => $isActive,
                    ];
                }, $streamClients);

                $transcoding = $stream['metadata']['transcoding'] ?? false;
                $transcodingFormat = null;
                $transcodingBackend = null;
                if ($transcoding) {
                    $profile = $streamProfilesById[$stream['metadata']['profile_id'] ?? null] ?? null;
                    if ($profile) {
                        $transcodingFormat = $profile->format === 'm3u8'
                            ? 'HLS'
                            : strtoupper($profile->format);
                        $transcodingBackend = match ($profile->backend) {
                            'streamlink' => 'Streamlink',
                            'ytdlp' => 'yt-dlp',
                            'ffmpeg' => 'FFmpeg',
                            default => null,
                        };
                    }
                }

                // Resolve playlist name, profiles_enabled, and alias name from metadata
                $playlistUuid = $stream['metadata']['playlist_uuid'] ?? '';
                $aliasName = null;
                $playlistName = null;
                $profilesEnabled = false;

                $alias = $aliasNamesByUuid[$playlistUuid] ?? null;
                if ($alias) {
                    $aliasName = $alias->name;
                } else {
                    $playlist = $playlistsByUuid[$playlistUuid] ?? null;
                    if ($playlist) {
                        $playlistName = $playlist->name;
                        $profilesEnabled = (bool) $playlist->profiles_enabled;
                    }
                }

                // Look up provider profile name from metadata
                $providerProfileName = null;
                $providerProfileId = $stream['metadata']['provider_profile_id'] ?? null;
                if ($providerProfileId) {
                    $providerProfile = $providerProfilesById[$providerProfileId] ?? null;
                    if ($providerProfile) {
                        $providerProfileName = $providerProfile->is_primary
                            ? 'Primary profile'
                            : ($providerProfile->name ?? "Profile #{$providerProfile->id}");
                    }
                }

                $streams[] = [
                    'stream_id' => $streamId,
                    'source_url' => $stream['original_url'],
                    'current_url' => $stream['current_url'],
                    'format' => strtoupper($stream['stream_type']),
                    'status' => $stream['is_active'] && $stream['client_count'] > 0 ? 'active' : 'idle',
                    'client_count' => $stream['client_count'],
                    'bandwidth_kbps' => $bandwidthKbps,
                    'bytes_transferred' => $bytesTransferred,
                    'uptime' => $uptime,
                    'started_at' => $startedAt->format('Y-m-d H:i:s'),
                    'process_running' => $stream['is_active'] && $stream['client_count'] > 0,
                    'model' => $model,
                    'clients' => $clients,
                    'has_failover' => $stream['has_failover'],
                    'error_count' => $stream['error_count'],
                    'segments_served' => $stream['total_segments_served'],
                    'transcoding' => $transcoding,
                    'transcoding_format' => $transcodingFormat,
                    'transcoding_backend' => $transcodingBackend,
                    'playlist_name' => $playlistName,
                    'profiles_enabled' => $profilesEnabled,
                    'provider_profile' => $providerProfileName,
                    'alias_name' => $aliasName,
                    // Failover details
                    'failover_urls' => $stream['failover_urls'] ?? [],
                    'failover_resolver_url' => $stream['failover_resolver_url'] ?? null,
                    'current_failover_index' => $stream['current_failover_index'] ?? 0,
                    'failover_attempts' => $stream['failover_attempts'] ?? 0,
                    'last_failover_time' => isset($stream['last_failover_time'])
                        ? Carbon::parse($stream['last_failover_time'], 'UTC')->format('Y-m-d H:i:s')
                        : null,
                    'using_failover' => ($stream['current_failover_index'] ?? 0) > 0 || ($stream['failover_attempts'] ?? 0) > 0,
                    'failover_channel' => $failoverChannel,
                ];
            }
        }

        // Append any active network broadcasts (simplified output)
        if (! empty($apiBroadcasts['success']) && ! empty($apiBroadcasts['broadcasts'])) {
            $broadcastNetworkUuids = collect($apiBroadcasts['broadcasts'])
                ->pluck('network_id')
                ->filter()
                ->unique()
                ->values();
            $networksByUuid = Network::whereIn('uuid', $broadcastNetworkUuids)
                ->get(['uuid', 'name'])
                ->keyBy('uuid');

            foreach ($apiBroadcasts['broadcasts'] as $bcast) {
                $network = $networksByUuid[$bcast['network_id']] ?? null;

                $startedAt = isset($bcast['started_at']) ? Carbon::parse($bcast['started_at'], 'UTC') : null;
                $uptime = $startedAt ? $startedAt->diffForHumans(null, true) : 'N/A';

                $streams[] = [
                    'stream_id' => 'broadcast:'.$bcast['network_id'],
                    'source_url' => $network ? $network->hls_url : ($bcast['stream_url'] ?? ''),
                    'current_url' => $network ? $network->hls_url : ($bcast['stream_url'] ?? ''),
                    'format' => 'HLS',
                    'status' => (($bcast['status'] ?? '') === 'running') ? 'active' : 'idle',
                    'client_count' => 0,
                    'bandwidth_kbps' => 0,
                    'bytes_transferred' => 'N/A',
                    'uptime' => $uptime,
                    'started_at' => $startedAt ? $startedAt->format('Y-m-d H:i:s') : null,
                    'process_running' => (($bcast['status'] ?? '') === 'running'),
                    'model' => [
                        'title' => $network ? $network->name : ('Network '.$bcast['network_id']),
                        'logo' => null,
                    ],
                    'clients' => [],
                    'has_failover' => false,
                    'error_count' => 0,
                    'segments_served' => $bcast['current_segment_number'] ?? 0,
                    'transcoding' => false,
                    'transcoding_format' => null,
                    'using_failover' => false,
                    'failover_channel' => null,
                    'broadcast' => true,
                    'alias_name' => null,
                ];
            }
        }

        return $streams;
    }

    // Reuse helper methods from original monitor
    protected function truncateUrl(string $url, int $maxLength = 50): string
    {
        if (strlen($url) <= $maxLength) {
            return $url;
        }

        return substr($url, 0, $maxLength - 3).'...';
    }

    protected function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }

    public function getViewData(): array
    {
        return [
            'streams' => $this->streams,
            'globalStats' => $this->globalStats,
            'systemStats' => $this->systemStats,
            'refreshInterval' => $this->refreshInterval,
            'lastUpdatedAt' => $this->lastUpdatedAt,
            'connectionError' => $this->connectionError,
        ];
    }
}
