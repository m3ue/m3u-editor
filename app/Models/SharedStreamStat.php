<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared Stream Statistics Model
 * 
 * Time-series statistics for shared streams,
 * used for monitoring and analytics.
 */
class SharedStreamStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'stream_id',
        'recorded_at',
        'client_count',
        'bandwidth_kbps',
        'buffer_size',
        'performance_metrics'
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'performance_metrics' => 'array',
        'client_count' => 'integer',
        'bandwidth_kbps' => 'integer',
        'buffer_size' => 'integer'
    ];

    /**
     * Get the shared stream this statistic belongs to
     */
    public function sharedStream(): BelongsTo
    {
        return $this->belongsTo(SharedStream::class, 'stream_id', 'stream_id');
    }

    /**
     * Record new statistics for a stream
     */
    public static function recordStats(
        string $streamId,
        int $clientCount,
        int $bandwidthKbps,
        int $bufferSize,
        ?array $performanceMetrics = null
    ): self {
        return self::create([
            'stream_id' => $streamId,
            'recorded_at' => now(),
            'client_count' => $clientCount,
            'bandwidth_kbps' => $bandwidthKbps,
            'buffer_size' => $bufferSize,
            'performance_metrics' => $performanceMetrics ?? []
        ]);
    }

    /**
     * Get statistics for a time range
     */
    public static function getStatsForRange(
        string $streamId,
        \DateTime $startTime,
        \DateTime $endTime
    ) {
        return self::where('stream_id', $streamId)
                  ->whereBetween('recorded_at', [$startTime, $endTime])
                  ->orderBy('recorded_at')
                  ->get();
    }

    /**
     * Get hourly aggregated statistics
     */
    public static function getHourlyStats(string $streamId, int $hours = 24)
    {
        $startTime = now()->subHours($hours);
        
        return self::selectRaw('
                DATE_FORMAT(recorded_at, \'%Y-%m-%d %H:00:00\') as hour,
                AVG(client_count) as avg_clients,
                MAX(client_count) as max_clients,
                AVG(bandwidth_kbps) as avg_bandwidth,
                MAX(bandwidth_kbps) as max_bandwidth,
                AVG(buffer_size) as avg_buffer_size
            ')
            ->where('stream_id', $streamId)
            ->where('recorded_at', '>=', $startTime)
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();
    }

    /**
     * Clean up old statistics
     */
    public static function cleanupOldStats(int $daysOld = 7): int
    {
        return self::where('recorded_at', '<', now()->subDays($daysOld))
                  ->delete();
    }

    /**
     * Get peak performance metrics
     */
    public static function getPeakMetrics(string $streamId, int $hours = 24): array
    {
        $stats = self::where('stream_id', $streamId)
                    ->where('recorded_at', '>=', now()->subHours($hours))
                    ->get();

        if ($stats->isEmpty()) {
            return [
                'peak_clients' => 0,
                'peak_bandwidth' => 0,
                'avg_clients' => 0,
                'avg_bandwidth' => 0,
                'total_data_points' => 0
            ];
        }

        return [
            'peak_clients' => $stats->max('client_count'),
            'peak_bandwidth' => $stats->max('bandwidth_kbps'),
            'avg_clients' => round($stats->avg('client_count'), 2),
            'avg_bandwidth' => round($stats->avg('bandwidth_kbps'), 2),
            'total_data_points' => $stats->count(),
            'time_range_hours' => $hours
        ];
    }

    /**
     * Get current statistics summary
     */
    public static function getCurrentSummary(): array
    {
        $activeStreams = SharedStream::active()->count();
        $totalClients = SharedStream::active()->sum('client_count');
        $totalBandwidth = SharedStream::active()->sum('bandwidth_kbps');

        return [
            'active_streams' => $activeStreams,
            'total_clients' => $totalClients,
            'total_bandwidth_kbps' => $totalBandwidth,
            'avg_clients_per_stream' => $activeStreams > 0 ? round($totalClients / $activeStreams, 2) : 0,
            'avg_bandwidth_per_stream' => $activeStreams > 0 ? round($totalBandwidth / $activeStreams, 2) : 0,
            'timestamp' => now()->toISOString()
        ];
    }
}
