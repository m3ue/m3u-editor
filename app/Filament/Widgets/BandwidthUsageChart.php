<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use Filament\Widgets\ChartWidget;

class BandwidthUsageChart extends ChartWidget
{
    protected static ?string $heading = 'Bandwidth Usage Trends';
    protected static ?int $sort = 7;
    protected static ?string $pollingInterval = '30s';
    
    protected function getData(): array
    {
        // Get hourly bandwidth stats for the last 24 hours
        $stats = SharedStreamStat::selectRaw('
                DATE_FORMAT(recorded_at, \'%Y-%m-%d %H:00:00\') as hour,
                SUM(bandwidth_kbps) as total_bandwidth,
                AVG(bandwidth_kbps) as avg_bandwidth,
                MAX(bandwidth_kbps) as peak_bandwidth
            ')
            ->where('recorded_at', '>=', now()->subDay())
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $labels = [];
        $totalData = [];
        $avgData = [];
        $peakData = [];

        foreach ($stats as $stat) {
            $labels[] = \Carbon\Carbon::parse($stat->hour)->format('H:i');
            $totalData[] = round($stat->total_bandwidth / 1000, 2); // Convert to Mbps
            $avgData[] = round($stat->avg_bandwidth / 1000, 2);
            $peakData[] = round($stat->peak_bandwidth / 1000, 2);
        }

        // Fill in missing hours with zeros if needed
        $currentHour = now()->startOfHour();
        for ($i = 23; $i >= 0; $i--) {
            $hour = $currentHour->copy()->subHours($i)->format('H:i');
            if (!in_array($hour, $labels)) {
                array_unshift($labels, $hour);
                array_unshift($totalData, 0);
                array_unshift($avgData, 0);
                array_unshift($peakData, 0);
            }
        }

        // Keep only last 24 hours
        $labels = array_slice($labels, -24);
        $totalData = array_slice($totalData, -24);
        $avgData = array_slice($avgData, -24);
        $peakData = array_slice($peakData, -24);

        return [
            'datasets' => [
                [
                    'label' => 'Total Bandwidth (Mbps)',
                    'data' => $totalData,
                    'borderColor' => '#EF4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Average Bandwidth (Mbps)',
                    'data' => $avgData,
                    'borderColor' => '#3B82F6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => false,
                ],
                [
                    'label' => 'Peak Bandwidth (Mbps)',
                    'data' => $peakData,
                    'borderColor' => '#F59E0B',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => false,
                    'borderDash' => [5, 5],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Bandwidth (Mbps)'
                    ]
                ],
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Time (Hours)'
                    ]
                ]
            ],
            'interaction' => [
                'intersect' => false,
                'mode' => 'index',
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'top',
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ]
            ],
        ];
    }
}
