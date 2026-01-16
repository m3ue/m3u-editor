<?php

namespace App\Http\Controllers;

use App\Facades\LogoFacade;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class ProxyController extends Controller
{
    /**
     * Get proxy status information.
     *
     * Returns the current state of the m3u-proxy including:
     * - Whether proxy is enabled
     * - Proxy mode (embedded/external)
     * - Proxy URL
     * - Active streams count and details
     * - Streams grouped by playlist
     * - Proxy server info and health
     *
     *
     * @response array{
     *     proxy_enabled: bool,
     *     mode: string,
     *     proxy_url: string|null,
     *     using_failover_resolver: bool,
     *     active_streams: int,
     *     streams_by_playlist: array<array{playlist_uuid: string, playlist_name: string, active_count: int}>,
     *     proxy_info: array|null,
     *     health: string
     * }
     */
    public function status(): JsonResponse
    {
        $proxyEnabled = config('proxy.external_proxy_enabled', false);
        $proxyService = new M3uProxyService;

        $mode = $proxyService->mode();
        $proxyUrl = config('proxy.m3u_proxy_public_url') ?: config('proxy.m3u_proxy_host');
        $usingResolver = $proxyService->usingResolver();

        // Fetch active streams
        $activeStreamsData = $proxyService->fetchActiveStreams();
        $streams = $activeStreamsData['streams'] ?? [];
        $totalActiveStreams = count($streams);

        // Group streams by playlist
        $streamsByPlaylist = [];
        $playlistUuids = [];
        foreach ($streams as $stream) {
            $playlistUuid = $stream['metadata']['playlist_uuid'] ?? null;
            if ($playlistUuid) {
                if (! isset($streamsByPlaylist[$playlistUuid])) {
                    $streamsByPlaylist[$playlistUuid] = [
                        'playlist_uuid' => $playlistUuid,
                        'playlist_name' => null,
                        'active_count' => 0,
                    ];
                    $playlistUuids[] = $playlistUuid;
                }
                $streamsByPlaylist[$playlistUuid]['active_count']++;
            }
        }

        // Fetch playlist names
        if (! empty($playlistUuids)) {
            $playlists = Playlist::whereIn('uuid', $playlistUuids)->pluck('name', 'uuid');
            foreach ($streamsByPlaylist as $uuid => &$data) {
                $data['playlist_name'] = $playlists[$uuid] ?? 'Unknown';
            }
        }

        // Fetch proxy server info
        $proxyInfo = null;
        $health = 'unknown';
        if ($proxyEnabled) {
            $infoResult = $proxyService->getProxyInfo();
            if ($infoResult['success']) {
                $proxyInfo = $infoResult['info'];
                $health = 'healthy';
            } else {
                $health = 'unhealthy';
            }
        } else {
            $health = 'disabled';
        }

        return response()->json([
            'proxy_enabled' => $proxyEnabled,
            'mode' => $mode,
            'proxy_url' => $proxyUrl,
            'using_failover_resolver' => $usingResolver,
            'active_streams' => $totalActiveStreams,
            'streams_by_playlist' => array_values($streamsByPlaylist),
            'proxy_info' => $proxyInfo,
            'health' => $health,
        ]);
    }

    /**
     * Get proxy streams information.
     *
     * Returns detailed information about all active streams from the m3u-proxy,
     * including client connections, bandwidth usage, and stream metadata.
     *
     * @response array{
     *     success: bool,
     *     streams: array<array{
     *         stream_id: string,
     *         source_url: string,
     *         current_url: string,
     *         format: string,
     *         status: string,
     *         client_count: int,
     *         bandwidth_kbps: float,
     *         bytes_transferred: string,
     *         uptime: string,
     *         started_at: string,
     *         process_running: bool,
     *         model: array{title: string, logo: string|null},
     *         clients: array<array{ip: string, username: string|null, connected_at: string, duration: string, bytes_received: string, bandwidth: string, is_active: bool}>,
     *         has_failover: bool,
     *         error_count: int,
     *         segments_served: int,
     *         transcoding: bool,
     *         transcoding_format: string|null,
     *         failover_urls: array<string>,
     *         failover_resolver_url: string|null,
     *         current_failover_index: int,
     *         failover_attempts: int,
     *         last_failover_time: string|null,
     *         using_failover: bool
     *     }>,
     *     globalStats: array{
     *         total_streams: int,
     *         active_streams: int,
     *         total_clients: int,
     *         total_bandwidth_kbps: float,
     *         avg_clients_per_stream: string
     *     },
     *     systemStats: array
     * }
     */
    public function streams(): JsonResponse
    {
        $apiStreams = app(M3uProxyService::class)->fetchActiveStreams();
        $apiClients = app(M3uProxyService::class)->fetchActiveClients();

        // Check for connection errors
        if (! $apiStreams['success']) {
            return response()->json([
                'success' => false,
                'error' => $apiStreams['error'] ?? 'Unknown error connecting to m3u-proxy',
            ], 500);
        }

        if (! $apiClients['success']) {
            return response()->json([
                'success' => false,
                'error' => $apiClients['error'] ?? 'Unknown error connecting to m3u-proxy',
            ], 500);
        }

        if (empty($apiStreams['streams'])) {
            return response()->json([
                'success' => true,
                'streams' => [],
                'globalStats' => [
                    'total_streams' => 0,
                    'active_streams' => 0,
                    'total_clients' => 0,
                    'total_bandwidth_kbps' => 0,
                    'avg_clients_per_stream' => '0.00',
                ],
                'systemStats' => [],
            ]);
        }

        // Group clients by stream_id for easier lookup
        $clientsByStream = collect($apiClients['clients'] ?? [])
            ->groupBy('stream_id')
            ->toArray();

        $streams = [];
        foreach ($apiStreams['streams'] as $stream) {
            $streamId = $stream['stream_id'];
            $streamClients = $clientsByStream[$streamId] ?? [];

            // Get model information if metadata exists
            $model = [];
            if (isset($stream['metadata']['type']) && isset($stream['metadata']['id'])) {
                $modelType = $stream['metadata']['type'];
                $modelId = $stream['metadata']['id'];
                $title = null;
                $logo = null;

                if ($modelType === 'channel') {
                    $channel = Channel::find($modelId);
                    if ($channel) {
                        $title = $channel->name_custom ?? $channel->name ?? $channel->title;
                        $logo = LogoFacade::getChannelLogoUrl($channel);
                    }
                } elseif ($modelType === 'episode') {
                    $episode = Episode::find($modelId);
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
                    'connected_at' => $connectedAt->format('Y-m-d H:i:s'),
                    'duration' => $connectedAt->diffForHumans(null, true),
                    'bytes_received' => $this->formatBytes($client['bytes_served']),
                    'bandwidth' => 'N/A', // Can calculate if needed
                    'is_active' => $isActive,
                ];
            }, $streamClients);

            $transcoding = $stream['metadata']['transcoding'] ?? false;
            $transcodingFormat = null;
            if ($transcoding) {
                $profile = StreamProfile::find($stream['metadata']['profile_id'] ?? null);
                if ($profile) {
                    $transcodingFormat = $profile->format === 'm3u8'
                        ? 'HLS'
                        : strtoupper($profile->format);
                }
            }

            $streams[] = [
                'stream_id' => $streamId,
                'source_url' => $this->truncateUrl($stream['original_url']),
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
                // Failover details
                'failover_urls' => $stream['failover_urls'] ?? [],
                'failover_resolver_url' => $stream['failover_resolver_url'] ?? null,
                'current_failover_index' => $stream['current_failover_index'] ?? 0,
                'failover_attempts' => $stream['failover_attempts'] ?? 0,
                'last_failover_time' => isset($stream['last_failover_time'])
                    ? Carbon::parse($stream['last_failover_time'], 'UTC')->format('Y-m-d H:i:s')
                    : null,
                'using_failover' => ($stream['current_failover_index'] ?? 0) > 0 || ($stream['failover_attempts'] ?? 0) > 0,
            ];
        }

        // Calculate global stats
        $totalClients = array_sum(array_map(fn ($s) => $s['client_count'] ?? 0, $streams));
        $totalBandwidth = array_sum(array_map(fn ($s) => $s['bandwidth_kbps'] ?? 0, $streams));
        $activeStreams = count(array_filter($streams, fn ($s) => $s['status'] === 'active'));

        $globalStats = [
            'total_streams' => count($streams),
            'active_streams' => $activeStreams,
            'total_clients' => $totalClients,
            'total_bandwidth_kbps' => round($totalBandwidth, 2),
            'avg_clients_per_stream' => count($streams) > 0
                ? number_format($totalClients / count($streams), 2)
                : '0.00',
        ];

        return response()->json([
            'success' => true,
            'streams' => $streams,
            'globalStats' => $globalStats,
            'systemStats' => [], // populate if external API provides system metrics
        ]);
    }

    /**
     * Truncate a URL for display
     */
    protected function truncateUrl(string $url, int $maxLength = 50): string
    {
        if (strlen($url) <= $maxLength) {
            return $url;
        }

        return substr($url, 0, $maxLength - 3).'...';
    }

    /**
     * Format bytes into human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
