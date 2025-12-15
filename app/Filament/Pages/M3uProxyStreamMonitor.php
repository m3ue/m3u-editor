<?php

namespace App\Filament\Pages;

use App\Facades\LogoFacade;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use App\Services\ProxyService;
use Carbon\Carbon;
use Exception;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;

/**
 * Shared Stream Monitor (External API-backed)
 *
 * Uses the external m3u-proxy server API to populate and manage streams.
 */
class M3uProxyStreamMonitor extends Page
{
    protected static ?string $navigationLabel = 'Stream Monitor';

    protected static ?string $title = 'M3U Proxy Stream Monitor';

    /**
     * Check if the user can access this page.
     * Only admin users can access the Preferences page.
     */
    public static function canAccess(): bool
    {
        return true; // Allow all users to access the stream monitor
        // return auth()->check() && auth()->user()->isAdmin();
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Proxy';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.m3u-proxy-stream-monitor';

    public $streams = [];

    public $globalStats = [];

    public $systemStats = [];

    public $refreshInterval = 5; // seconds

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

        $totalClients = array_sum(array_map(fn($s) => $s['client_count'] ?? 0, $this->streams));
        $totalBandwidth = array_sum(array_map(fn($s) => $s['bandwidth_kbps'] ?? 0, $this->streams));
        $activeStreams = count(array_filter($this->streams, fn($s) => $s['status'] === 'active'));

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
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->size(Size::Small)
                ->action('refreshData'),

            // Action::make('cleanup')
            //     ->label('Cleanup Streams')
            //     ->icon('heroicon-o-trash')
            //     ->size(Size::Small)
            //     ->color('danger')
            //     ->requiresConfirmation()
            //     ->modalDescription('This will stop all inactive streams via external API.')
            //     ->action(function (): void {
            //         // If external API exposes a cleanup endpoint add call here
            //         Notification::make()->title('Cleanup requested.')->success()->send();
            //         $this->refreshData();
            //     }),
        ];
    }

    public function triggerFailover(string $streamId): void
    {
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
                ->title('Error triggering failover.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->refreshData();
    }

    public function stopStream(string $streamId): void
    {
        try {
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
                ->title('Error stopping stream.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->refreshData();
    }

    protected function getActiveStreams(): array
    {
        $apiStreams = $this->apiService->fetchActiveStreams();
        $apiClients = $this->apiService->fetchActiveClients();

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

        if (empty($apiStreams['streams'])) {
            return [];
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

        return $streams;
    }

    // Reuse helper methods from original monitor
    protected function truncateUrl(string $url, int $maxLength = 50): string
    {
        if (strlen($url) <= $maxLength) {
            return $url;
        }

        return substr($url, 0, $maxLength - 3) . '...';
    }

    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    public function getViewData(): array
    {
        return [
            'streams' => $this->streams,
            'globalStats' => $this->globalStats,
            'systemStats' => $this->systemStats,
            'refreshInterval' => $this->refreshInterval,
            'connectionError' => $this->connectionError,
        ];
    }
}
