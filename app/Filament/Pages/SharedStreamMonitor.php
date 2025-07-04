<?php

namespace App\Filament\Pages;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use App\Services\SharedStreamService;
use App\Services\StreamMonitorService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\IconPosition;
use Illuminate\Support\Facades\Redis;

/**
 * Shared Stream Monitor Page
 * 
 * xTeVe-like monitoring interface for shared streaming system
 */
class SharedStreamMonitor extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';
    protected static ?string $navigationLabel = 'Shared Streams';
    protected static ?string $title = 'Shared Stream Monitor';
    protected static ?string $navigationGroup = 'Tools';
    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.pages.shared-stream-monitor';

    public $streams = [];
    public $globalStats = [];
    public $systemStats = [];
    public $refreshInterval = 5; // seconds

    protected $sharedStreamService;
    protected $monitorService;

    public function boot(): void
    {
        $this->sharedStreamService = app(SharedStreamService::class);
        $this->monitorService = app(StreamMonitorService::class);
    }

    public function mount(): void
    {
        $this->refreshData();
    }

    public function refreshData(): void
    {
        $this->streams = $this->getActiveStreams();
        $this->globalStats = $this->getGlobalStats();
        $this->systemStats = $this->getSystemStats();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->size(ActionSize::Small)
                ->action('refreshData'),

            Actions\Action::make('cleanup')
                ->label('Cleanup Streams')
                ->icon('heroicon-o-trash')
                ->size(ActionSize::Small)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('This will stop all inactive streams and clean up orphaned processes.')
                ->action('cleanupStreams'),

            Actions\Action::make('settings')
                ->label('Settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->size(ActionSize::Small)
                ->modalSubmitActionLabel('Save Settings')
                ->form($this->getSettingsForm())
                ->action(function (array $data): void {
                    $this->saveSettings($data);
                }),
        ];
    }

    public function cleanupStreams(): void
    {
        $this->sharedStreamService->cleanupInactiveStreams();
        $this->refreshData();

        Notification::make()
            ->title('Cleanup completed successfully.')
            ->success()
            ->send();
    }

    public function stopStream(string $streamId): void
    {
        $success = $this->sharedStreamService->stopStream($streamId);

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

        $this->refreshData();
    }

    public function restartStream(string $streamId): void
    {
        $stream = SharedStream::where('stream_id', $streamId)->first();

        if (!$stream) {
            Notification::make()
                ->title("Stream not found.")
                ->danger()
                ->send();
            return;
        }

        // Stop the current stream
        $this->sharedStreamService->stopStream($streamId);

        // Wait a moment for cleanup
        sleep(1);

        // Start a new stream with the same source
        $newStreamId = $this->sharedStreamService->createSharedStream(
            $stream->source_url,
            $stream->format
        );

        if ($newStreamId) {
            Notification::make()
                ->title("Stream restarted with new ID: {$newStreamId}")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title("Failed to restart stream.")
                ->danger()
                ->send();
        }

        $this->refreshData();
    }

    protected function getActiveStreams(): array
    {
        // Get streams from database instead of just Redis
        $streams = SharedStream::with(['clients', 'stats'])
            ->whereIn('status', ['starting', 'active'])
            ->orderBy('started_at', 'desc')
            ->get();

        return $streams->map(function ($stream) {
            $recentStats = $stream->recentStats(5)->first();
            $clientInfo = $this->sharedStreamService->getClients($stream->stream_id);
            $streamInfo = json_decode($stream->stream_info, true);
            $model = $streamInfo['type'] === 'episode'
                ? Episode::find($streamInfo['model_id'])
                : Channel::find($streamInfo['model_id']);

            $clientsData = array_map(function ($client) {
                $connectedAt = date('H:i:s', $client['connected_at']);
                $duration = isset($client['connected_at']) ? now()->diffInSeconds(now()->setTimestamp($client['connected_at'])) : 0;
                return [
                    'ip' => $client['options']['ip'],
                    'client_id' => $client['client_id'],
                    'connected_at' => $connectedAt,
                    'user_agent' => $client['options']['user_agent'] ?? 'Unknown',
                    'duration' => $duration,
                ];
            }, $clientInfo);

            return [
                'stream_id' => $stream->stream_id,
                'source_url' => $this->truncateUrl($stream->source_url),
                'format' => strtoupper($stream->format),
                'status' => $stream->status,
                'client_count' => $stream->client_count,
                'bandwidth_kbps' => $stream->bandwidth_kbps,
                'bytes_transferred' => $this->formatBytes($stream->bytes_transferred),
                'buffer_size' => $this->formatBytes($stream->buffer_size),
                'started_at' => $stream->started_at?->format('Y-m-d H:i:s'),
                'uptime' => $stream->started_at ? $stream->started_at->diffForHumans(null, true) : 'N/A',
                'process_running' => $stream->isProcessRunning(),
                'clients' => $clientsData,
                'peak_clients' => $recentStats?->client_count ?? 0,
                'avg_bandwidth' => $recentStats?->bandwidth_kbps ?? 0,
                'model' => $model ? [
                    'id' => $model->id,
                    'title' => $model->title,
                    'type' => $streamInfo['type'],
                    'logo' => $model->logo ?? null,
                ] : null,
            ];
        })->toArray();
    }

    protected function getGlobalStats(): array
    {
        return SharedStreamStat::getCurrentSummary();
    }

    protected function getSystemStats(): array
    {
        $totalMemory = $this->getSystemMemory();
        $diskSpace = $this->getDiskSpace();
        $bufferPath = config('proxy.shared_streaming.buffer_path', '/tmp/m3u-proxy-buffers');

        return [
            'total_streams' => SharedStream::count(),
            'active_streams' => SharedStream::active()->count(),
            'total_clients' => SharedStream::active()->sum('client_count'),
            'total_bandwidth' => SharedStream::active()->sum('bandwidth_kbps'),
            'memory_usage' => $totalMemory,
            'disk_space' => $diskSpace,
            'buffer_directory' => $bufferPath,
            'redis_connected' => $this->checkRedisConnection(),
            'uptime' => $this->getSystemUptime(),
        ];
    }

    protected function getSettingsForm(): array
    {
        return [
            \Filament\Forms\Components\TextInput::make('refresh_interval')
                ->label('Refresh Interval (seconds)')
                ->numeric()
                ->default($this->refreshInterval)
                ->minValue(1)
                ->maxValue(60),

            \Filament\Forms\Components\Toggle::make('auto_cleanup')
                ->label('Auto Cleanup Inactive Streams')
                ->default(true),

            \Filament\Forms\Components\TextInput::make('cleanup_interval')
                ->label('Cleanup Interval (minutes)')
                ->numeric()
                ->default(10)
                ->minValue(1),

            \Filament\Forms\Components\TextInput::make('max_buffer_size')
                ->label('Max Buffer Size (MB)')
                ->numeric()
                ->default(100)
                ->minValue(10),
        ];
    }

    public function saveSettings(array $data): void
    {
        // Save settings to configuration or cache
        Redis::hmset('shared_streaming:settings', [
            'refresh_interval' => $data['refresh_interval'],
            'auto_cleanup' => $data['auto_cleanup'] ? 1 : 0,
            'cleanup_interval' => $data['cleanup_interval'],
            'max_buffer_size' => $data['max_buffer_size'],
        ]);

        $this->refreshInterval = $data['refresh_interval'];

        Notification::make()
            ->title('Settings saved successfully.')
            ->success()
            ->send();
    }

    public function getSubheading(): ?string
    {
        $activeCount = count(array_filter($this->streams, fn($s) => $s['status'] === 'active'));
        $totalClients = array_sum(array_column($this->streams, 'client_count'));
        $totalBandwidth = array_sum(array_column($this->streams, 'bandwidth_kbps'));

        return "Active: {$activeCount} streams | Clients: {$totalClients} | Bandwidth: " .
            ($totalBandwidth > 1000 ? round($totalBandwidth / 1000, 1) . ' Mbps' : $totalBandwidth . ' kbps');
    }

    // Helper methods
    protected function truncateUrl(string $url, int $maxLength = 50): string
    {
        if (strlen($url) <= $maxLength) {
            return $url;
        }

        return substr($url, 0, $maxLength - 3) . '...';
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    protected function getSystemMemory(): array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (!$meminfo) {
            return ['total' => 'N/A', 'free' => 'N/A', 'used' => 'N/A', 'percentage' => 0];
        }

        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);

        $totalMem = isset($total[1]) ? (int)$total[1] * 1024 : 0;
        $availableMem = isset($available[1]) ? (int)$available[1] * 1024 : 0;
        $usedMem = $totalMem - $availableMem;

        return [
            'total' => $this->formatBytes($totalMem),
            'free' => $this->formatBytes($availableMem),
            'used' => $this->formatBytes($usedMem),
            'percentage' => $totalMem > 0 ? round(($usedMem / $totalMem) * 100, 1) : 0,
        ];
    }

    protected function getDiskSpace(): array
    {
        $path = config('proxy.shared_streaming.buffer_path', '/tmp');
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;

        return [
            'total' => $this->formatBytes($total),
            'free' => $this->formatBytes($free),
            'used' => $this->formatBytes($used),
            'percentage' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    protected function checkRedisConnection(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function getSystemUptime(): string
    {
        $uptime = @file_get_contents('/proc/uptime');
        if (!$uptime) {
            return 'N/A';
        }

        $seconds = (int)explode(' ', $uptime)[0];
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$days}d {$hours}h {$minutes}m";
    }

    public function getViewData(): array
    {
        return [
            'streams' => $this->streams,
            'globalStats' => $this->globalStats,
            'systemStats' => $this->systemStats,
            'refreshInterval' => $this->refreshInterval,
        ];
    }
}
