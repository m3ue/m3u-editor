<?php

namespace App\Filament\Widgets;

use App\Models\SharedStream;
use App\Models\SharedStreamStat;
use Filament\Widgets\ChartWidget;

class SharedStreamPerformanceChart extends ChartWidget
{
    protected static ?string $heading = 'Stream Performance (Last 24 Hours)';
    protected static ?int $sort = 3;
    protected static ?string $pollingInterval = '30s';
    
    protected function getData(): array
    {
        $stats = SharedStreamStat::selectRaw('
                DATE_FORMAT(recorded_at, \'%Y-%m-%d %H:00:00\') as hour,
                AVG(client_count) as avg_clients,
                AVG(bandwidth_kbps) as avg_bandwidth
            ')
            ->where('recorded_at', '>=', now()->subDay())
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $labels = [];
        $clientData = [];
        $bandwidthData = [];

        foreach ($stats as $stat) {
            $labels[] = \Carbon\Carbon::parse($stat->hour)->format('H:i');
            $clientData[] = round($stat->avg_clients, 1);
            $bandwidthData[] = round($stat->avg_bandwidth / 1000, 2); // Convert to Mbps
        }

        // Fill in missing hours with zeros
        $hours = 24;
        while (count($labels) < $hours) {
            $labels[] = now()->subHours($hours - count($labels))->format('H:i');
            $clientData[] = 0;
            $bandwidthData[] = 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Average Clients',
                    'data' => $clientData,
                    'borderColor' => '#10B981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Bandwidth (Mbps)',
                    'data' => $bandwidthData,
                    'borderColor' => '#8B5CF6',
                    'backgroundColor' => 'rgba(139, 92, 246, 0.1)',
                    'yAxisID' => 'y1',
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
            'scales' => [
                'y' => [
                    'type' => 'linear',
                    'display' => true,
                    'position' => 'left',
                    'title' => [
                        'display' => true,
                        'text' => 'Clients'
                    ]
                ],
                'y1' => [
                    'type' => 'linear',
                    'display' => true,
                    'position' => 'right',
                    'title' => [
                        'display' => true,
                        'text' => 'Bandwidth (Mbps)'
                    ],
                    'grid' => [
                        'drawOnChartArea' => false,
                    ],
                ],
            ],
            'interaction' => [
                'intersect' => false,
                'mode' => 'index',
            ],
            'responsive' => true,
            'maintainAspectRatio' => false,
        ];
    }
}
