<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ContentBreakdownChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public function getHeading(): string
    {
        return __('Content breakdown');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $userId = auth()->id();

        $data = Cache::remember("dashboard_content_breakdown_{$userId}", 300, function () use ($userId) {
            $channels = DB::table('channels')
                ->selectRaw('
                    COUNT(CASE WHEN is_vod = false OR is_vod IS NULL THEN 1 END) as live,
                    COUNT(CASE WHEN is_vod = true THEN 1 END) as vod
                ')
                ->where('user_id', $userId)
                ->first();

            return [
                'live' => (int) ($channels->live ?? 0),
                'vod' => (int) ($channels->vod ?? 0),
                'series' => DB::table('series')->where('user_id', $userId)->count(),
                'episodes' => DB::table('episodes')->where('user_id', $userId)->count(),
            ];
        });

        return [
            'datasets' => [
                [
                    'label' => __('Items'),
                    'data' => [$data['live'], $data['vod'], $data['series'], $data['episodes']],
                    'backgroundColor' => [
                        'rgb(99, 102, 241)',
                        'rgb(14, 165, 233)',
                        'rgb(245, 158, 11)',
                        'rgb(16, 185, 129)',
                    ],
                ],
            ],
            'labels' => [__('Live channels'), __('VOD channels'), __('Series'), __('Episodes')],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
