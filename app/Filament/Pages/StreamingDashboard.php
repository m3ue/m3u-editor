<?php

namespace App\Filament\Pages;

use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use App\Services\StreamMonitorService;
use Filament\Actions;
use Filament\Pages\Page;
use Filament\Support\Enums\ActionSize;
use Illuminate\Support\Facades\Cache;

/**
 * Comprehensive Stats Dashboard
 * 
 * Advanced analytics and monitoring for the shared streaming system
 */
class StreamingDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationLabel = 'Analytics Dashboard';
    protected static ?string $title = 'Streaming Analytics & Performance';
    protected static ?string $navigationGroup = 'Streaming';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.streaming-dashboard';

    public $performanceMetrics = [];
    public $bandwidthAnalytics = [];
    public $streamStatistics = [];
    public $systemHealth = [];
    public $historicalData = [];
    public $refreshInterval = 30; // seconds

    public function mount(): void
    {
        $this->loadAnalyticsData();
    }

    public function refreshData(): void
    {
        $this->loadAnalyticsData();
        
        $this->getNotification('Dashboard data refreshed successfully.')
             ->success()
             ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh Data')
                ->icon('heroicon-o-arrow-path')
                ->size(ActionSize::Small)
                ->action('refreshData'),
                
            Actions\Action::make('export')
                ->label('Export Report')
                ->icon('heroicon-o-document-arrow-down')
                ->size(ActionSize::Small)
                ->action('exportReport'),
                
            Actions\Action::make('settings')
                ->label('Dashboard Settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->size(ActionSize::Small)
                ->modalHeading('Dashboard Configuration')
                ->modalWidth('lg')
                ->form([
                    \Filament\Forms\Components\Select::make('refresh_interval')
                        ->label('Auto-refresh Interval')
                        ->options([
                            15 => '15 seconds',
                            30 => '30 seconds',
                            60 => '1 minute',
                            300 => '5 minutes',
                            0 => 'Disabled'
                        ])
                        ->default($this->refreshInterval),
                        
                    \Filament\Forms\Components\Select::make('time_range')
                        ->label('Default Time Range')
                        ->options([
                            '1h' => 'Last Hour',
                            '6h' => 'Last 6 Hours', 
                            '24h' => 'Last 24 Hours',
                            '7d' => 'Last 7 Days',
                            '30d' => 'Last 30 Days'
                        ])
                        ->default('24h'),
                ])
                ->action(function (array $data) {
                    $this->refreshInterval = $data['refresh_interval'];
                    // Save to user preferences or config
                }),
        ];
    }

    protected function loadAnalyticsData(): void
    {
        $monitorService = app(StreamMonitorService::class);
        
        // Load performance metrics
        $this->performanceMetrics = $this->getPerformanceMetrics();
        
        // Load bandwidth analytics
        $this->bandwidthAnalytics = $this->getBandwidthAnalytics();
        
        // Load stream statistics
        $this->streamStatistics = $this->getStreamStatistics();
        
        // Load system health data
        $this->systemHealth = $monitorService->getSystemStats();
        
        // Load historical data
        $this->historicalData = $this->getHistoricalData();
    }

    protected function getPerformanceMetrics(): array
    {
        $activeStreams = SharedStream::active()->count();
        $totalStreams = SharedStream::count();
        $totalClients = SharedStream::active()->sum('client_count');
        $totalBandwidth = SharedStream::active()->sum('bandwidth_kbps');
        
        // Get peak metrics for today
        $todayPeaks = SharedStreamStat::whereDate('recorded_at', today())
            ->selectRaw('MAX(client_count) as peak_clients, MAX(bandwidth_kbps) as peak_bandwidth')
            ->first();
            
        // Calculate efficiency metrics
        $avgClientsPerStream = $activeStreams > 0 ? round($totalClients / $activeStreams, 2) : 0;
        $avgBandwidthPerStream = $activeStreams > 0 ? round($totalBandwidth / $activeStreams, 2) : 0;
        
        // Stream utilization rate
        $utilizationRate = $totalStreams > 0 ? round(($activeStreams / $totalStreams) * 100, 1) : 0;
        
        return [
            'active_streams' => $activeStreams,
            'total_streams' => $totalStreams,
            'total_clients' => $totalClients,
            'total_bandwidth_kbps' => $totalBandwidth,
            'peak_clients_today' => $todayPeaks?->peak_clients ?? 0,
            'peak_bandwidth_today' => $todayPeaks?->peak_bandwidth ?? 0,
            'avg_clients_per_stream' => $avgClientsPerStream,
            'avg_bandwidth_per_stream' => $avgBandwidthPerStream,
            'utilization_rate' => $utilizationRate,
            'efficiency_score' => $this->calculateEfficiencyScore($avgClientsPerStream, $utilizationRate),
        ];
    }

    protected function getBandwidthAnalytics(): array
    {
        // Current bandwidth distribution by format
        $formatBandwidth = SharedStream::active()
            ->selectRaw('format, SUM(bandwidth_kbps) as total_bandwidth, COUNT(*) as stream_count')
            ->groupBy('format')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->format => [
                    'bandwidth' => $item->total_bandwidth,
                    'streams' => $item->stream_count,
                    'avg_per_stream' => $item->stream_count > 0 ? round($item->total_bandwidth / $item->stream_count, 2) : 0
                ]];
            });

        // Bandwidth trends for the last 7 days
        $bandwidthTrends = SharedStreamStat::selectRaw('
                DATE(recorded_at) as date,
                AVG(bandwidth_kbps) as avg_bandwidth,
                MAX(bandwidth_kbps) as max_bandwidth,
                SUM(bandwidth_kbps) as total_bandwidth
            ')
            ->where('recorded_at', '>=', now()->subDays(7))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'by_format' => $formatBandwidth,
            'trends' => $bandwidthTrends,
            'total_today' => $this->getTotalBandwidthToday(),
            'peak_hour_today' => $this->getPeakBandwidthHourToday(),
        ];
    }

    protected function getStreamStatistics(): array
    {
        // Stream status distribution
        $statusDistribution = SharedStream::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Top streams by various metrics
        $topStreamsByClients = SharedStream::active()
            ->orderByDesc('client_count')
            ->limit(5)
            ->get(['stream_id', 'client_count', 'bandwidth_kbps']);

        $topStreamsByBandwidth = SharedStream::active()
            ->orderByDesc('bandwidth_kbps')
            ->limit(5)
            ->get(['stream_id', 'client_count', 'bandwidth_kbps']);

        // Stream health analysis
        $healthStats = SharedStream::active()
            ->selectRaw('health_status, COUNT(*) as count')
            ->groupBy('health_status')
            ->pluck('count', 'health_status');

        return [
            'status_distribution' => $statusDistribution,
            'top_by_clients' => $topStreamsByClients,
            'top_by_bandwidth' => $topStreamsByBandwidth,
            'health_distribution' => $healthStats,
            'average_uptime' => $this->getAverageUptime(),
            'longest_running' => $this->getLongestRunningStream(),
        ];
    }

    protected function getHistoricalData(): array
    {
        // Historical performance data for charts
        $hourlyStats = SharedStreamStat::selectRaw('
                DATE_FORMAT(recorded_at, \'%Y-%m-%d %H:00:00\') as hour,
                AVG(client_count) as avg_clients,
                AVG(bandwidth_kbps) as avg_bandwidth,
                COUNT(DISTINCT stream_id) as active_streams
            ')
            ->where('recorded_at', '>=', now()->subDay())
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $dailyStats = SharedStreamStat::selectRaw('
                DATE(recorded_at) as date,
                AVG(client_count) as avg_clients,
                AVG(bandwidth_kbps) as avg_bandwidth,
                MAX(client_count) as peak_clients,
                MAX(bandwidth_kbps) as peak_bandwidth
            ')
            ->where('recorded_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'hourly' => $hourlyStats,
            'daily' => $dailyStats,
        ];
    }

    // Helper methods
    protected function calculateEfficiencyScore(float $avgClients, float $utilization): int
    {
        // Simple efficiency score based on client density and utilization
        $clientScore = min($avgClients * 10, 50); // Max 50 points for client density
        $utilizationScore = $utilization * 0.5; // Max 50 points for utilization
        
        return (int) min($clientScore + $utilizationScore, 100);
    }

    protected function getTotalBandwidthToday(): int
    {
        return SharedStreamStat::whereDate('recorded_at', today())
            ->sum('bandwidth_kbps');
    }

    protected function getPeakBandwidthHourToday(): ?string
    {
        $peak = SharedStreamStat::selectRaw('DATE_FORMAT(recorded_at, \'%Y-%m-%d %H:00:00\') as hour, SUM(bandwidth_kbps) as total')
            ->whereDate('recorded_at', today())
            ->groupBy('hour')
            ->orderByDesc('total')
            ->first();

        return $peak ? \Carbon\Carbon::parse($peak->hour)->format('H:i') : null;
    }

    protected function getAverageUptime(): string
    {
        $avgSeconds = SharedStream::active()
            ->whereNotNull('started_at')
            ->get()
            ->avg(function ($stream) {
                return $stream->started_at->diffInSeconds(now());
            });

        if (!$avgSeconds) {
            return 'N/A';
        }

        $hours = floor($avgSeconds / 3600);
        $minutes = floor(($avgSeconds % 3600) / 60);

        return "{$hours}h {$minutes}m";
    }

    protected function getLongestRunningStream(): ?array
    {
        $stream = SharedStream::active()
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->first();

        if (!$stream) {
            return null;
        }

        return [
            'stream_id' => $stream->stream_id,
            'title' => "Stream {$stream->stream_id}",
            'uptime' => $stream->started_at->diffForHumans(null, true),
            'uptime_seconds' => $stream->started_at->diffInSeconds(now()),
        ];
    }

    public function exportReport(): void
    {
        // TODO: Implement report export functionality
        $this->getNotification('Report export feature coming soon!')
             ->info()
             ->send();
    }

    public function getViewData(): array
    {
        return [
            'performanceMetrics' => $this->performanceMetrics,
            'bandwidthAnalytics' => $this->bandwidthAnalytics,
            'streamStatistics' => $this->streamStatistics,
            'systemHealth' => $this->systemHealth,
            'historicalData' => $this->historicalData,
            'refreshInterval' => $this->refreshInterval,
        ];
    }
}
