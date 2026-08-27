<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FormatsDateColumn;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LibraryGrowthChart extends ChartWidget
{
    use FormatsDateColumn;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public function getHeading(): string
    {
        return __('Library growth (90 days)');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $userId = auth()->id();

        $series = Cache::remember("dashboard_growth_chart_{$userId}", 900, function () use ($userId) {
            $start = CarbonImmutable::now()->subDays(89)->startOfDay();

            // One aggregate query per source table. Rows created before the window
            // (or with no created_at at all, e.g. bulk-imported records) are folded
            // into a single "base" bucket so the running totals still end at the true
            // COUNT(*) without a second query. dateExpr() must return text so the CASE
            // result type stays text alongside the 'base' sentinel (Postgres would
            // otherwise try to coerce 'base' to a date and blow up).
            $bucket = "CASE WHEN created_at IS NULL OR created_at < ? THEN 'base' ELSE {$this->dateExpr('created_at')} END";

            $channelRows = DB::table('channels')
                ->selectRaw("{$bucket} as d,
                    SUM(CASE WHEN is_vod = true THEN 0 ELSE 1 END) as live,
                    SUM(CASE WHEN is_vod = true THEN 1 ELSE 0 END) as vod", [$start])
                ->where('user_id', $userId)
                ->groupBy('d')
                ->get()
                ->keyBy('d');

            $seriesRows = DB::table('series')
                ->selectRaw("{$bucket} as d, COUNT(*) as c", [$start])
                ->where('user_id', $userId)
                ->groupBy('d')
                ->pluck('c', 'd');

            $episodeRows = DB::table('episodes')
                ->selectRaw("{$bucket} as d, COUNT(*) as c", [$start])
                ->where('user_id', $userId)
                ->groupBy('d')
                ->pluck('c', 'd');

            $labels = [];
            $live = [];
            $vod = [];
            $seriesCounts = [];
            $episodes = [];

            $runningLive = (int) ($channelRows['base']->live ?? 0);
            $runningVod = (int) ($channelRows['base']->vod ?? 0);
            $runningSeries = (int) ($seriesRows['base'] ?? 0);
            $runningEpisodes = (int) ($episodeRows['base'] ?? 0);

            for ($day = $start; $day->lte(CarbonImmutable::now()); $day = $day->addDay()) {
                $key = $day->format('Y-m-d');
                $runningLive += (int) ($channelRows[$key]->live ?? 0);
                $runningVod += (int) ($channelRows[$key]->vod ?? 0);
                $runningSeries += (int) ($seriesRows[$key] ?? 0);
                $runningEpisodes += (int) ($episodeRows[$key] ?? 0);

                $labels[] = $day->format('M j');
                $live[] = $runningLive;
                $vod[] = $runningVod;
                $seriesCounts[] = $runningSeries;
                $episodes[] = $runningEpisodes;
            }

            return compact('labels', 'live', 'vod', 'seriesCounts', 'episodes');
        });

        $line = fn (string $label, array $data, string $color, ?string $axis = null): array => array_filter([
            'label' => $label,
            'data' => $data,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'fill' => false,
            'tension' => 0.3,
            'pointRadius' => 0,
            'borderWidth' => 2,
            'yAxisID' => $axis,
        ], fn ($value) => $value !== null);

        return [
            'datasets' => [
                $line(__('Live channels'), $series['live'], 'rgb(99, 102, 241)'),
                $line(__('VOD channels'), $series['vod'], 'rgb(14, 165, 233)'),
                $line(__('Series'), $series['seriesCounts'], 'rgb(245, 158, 11)'),
                $line(__('Episodes'), $series['episodes'], 'rgb(16, 185, 129)', 'yEpisodes'),
            ],
            'labels' => $series['labels'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 8]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'yEpisodes' => [
                    'position' => 'right',
                    'beginAtZero' => true,
                    'grid' => ['drawOnChartArea' => false],
                    'ticks' => ['precision' => 0],
                ],
            ],
            'plugins' => ['legend' => ['display' => true]],
        ];
    }
}
