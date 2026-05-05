<?php

namespace App\Filament\Resources\Assets\Widgets;

use App\Models\Asset;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AssetStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $total = Asset::query()
            ->selectRaw('COUNT(*) as count, SUM(size_bytes) as total_bytes, COUNT(CASE WHEN is_image THEN 1 END) as image_count')
            ->first();

        $bySource = Asset::query()
            ->selectRaw('source, COUNT(*) as count, SUM(size_bytes) as total_bytes')
            ->groupBy('source')
            ->get()
            ->keyBy('source');

        $uploadCount = $bySource->get('upload')?->count ?? 0;
        $uploadBytes = $bySource->get('upload')?->total_bytes ?? 0;
        $cacheCount = $bySource->get('logo_cache')?->count ?? 0;
        $cacheBytes = $bySource->get('logo_cache')?->total_bytes ?? 0;
        $scanCount = $bySource->get('scan')?->count ?? 0;

        return [
            Stat::make(__('Total Assets'), number_format((int) ($total->count ?? 0)))
                ->description(__(':images images', ['images' => number_format((int) ($total->image_count ?? 0))]))
                ->descriptionIcon('heroicon-m-photo')
                ->color('primary'),

            Stat::make(__('Total Storage'), $this->formatBytes((int) ($total->total_bytes ?? 0)))
                ->description(__(':uploaded uploaded · :cached cached', [
                    'uploaded' => $this->formatBytes((int) $uploadBytes),
                    'cached' => $this->formatBytes((int) $cacheBytes),
                ]))
                ->descriptionIcon('heroicon-m-server-stack')
                ->color('info'),

            Stat::make(__('Uploaded'), number_format((int) $uploadCount))
                ->description(__(':size total size', ['size' => $this->formatBytes((int) $uploadBytes)]))
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color('success'),

            Stat::make(__('Cached Logos'), number_format((int) $cacheCount))
                ->description(__(':size total size', ['size' => $this->formatBytes((int) $cacheBytes)]))
                ->descriptionIcon('heroicon-m-bookmark')
                ->color('warning'),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = (int) floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2).' '.$units[$pow];
    }
}
