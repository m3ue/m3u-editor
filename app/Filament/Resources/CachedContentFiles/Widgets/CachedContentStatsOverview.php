<?php

namespace App\Filament\Resources\CachedContentFiles\Widgets;

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\CachedContentFiles\Pages\ListCachedContentFiles;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Overview above the Cached Downloads table. Built from the table's own
 * filtered query (InteractsWithPageTable), so it follows the table's filters
 * and the resource's per-user scoping, and aggregated in one query so no
 * rows are loaded. Polls so in-progress downloads stay current.
 */
class CachedContentStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected ?string $pollingInterval = '10s';

    protected function getTablePage(): string
    {
        return ListCachedContentFiles::class;
    }

    protected function getStats(): array
    {
        $completed = CachedContentFileStatus::Completed->value;

        // reorder(): the table query carries the table's sort, which an
        // aggregate-only select can't ORDER BY on Postgres.
        $totals = $this->getPageTableQuery()
            ->reorder()
            ->toBase()
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS completed', [$completed])
            ->selectRaw("COUNT(CASE WHEN status = ? AND content_type = 'movie' THEN 1 END) AS completed_movies", [$completed])
            ->selectRaw("COUNT(CASE WHEN status = ? AND content_type = 'episode' THEN 1 END) AS completed_episodes", [$completed])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN file_size_bytes END), 0) AS storage_bytes', [$completed])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND managed_by IS NOT NULL THEN file_size_bytes END), 0) AS auto_bytes', [$completed])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS downloading', [CachedContentFileStatus::Downloading->value])
            ->selectRaw("COUNT(CASE WHEN status = ? AND source <> 'arr' THEN 1 END) AS pending", [CachedContentFileStatus::Pending->value])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS failed', [CachedContentFileStatus::Failed->value])
            ->first();

        $storageBytes = (int) $totals->storage_bytes;
        $autoBytes = (int) $totals->auto_bytes;
        $inProgress = (int) $totals->downloading + (int) $totals->pending;
        $failed = (int) $totals->failed;

        return [
            Stat::make(__('Cached'), Number::format((int) $totals->completed))
                ->description(__(':movies movies · :episodes episodes', [
                    'movies' => Number::format((int) $totals->completed_movies),
                    'episodes' => Number::format((int) $totals->completed_episodes),
                ]))
                ->descriptionIcon('heroicon-m-film')
                ->color('primary'),

            Stat::make(__('Storage Used'), Number::fileSize($storageBytes, precision: 1))
                ->description(__('Auto :auto · Manual :manual', [
                    'auto' => Number::fileSize($autoBytes, precision: 1),
                    'manual' => Number::fileSize($storageBytes - $autoBytes, precision: 1),
                ]))
                ->descriptionIcon('heroicon-m-server-stack')
                ->color('info'),

            Stat::make(__('In Progress'), Number::format($inProgress))
                ->description(__(':downloading downloading · :pending pending', [
                    'downloading' => Number::format((int) $totals->downloading),
                    'pending' => Number::format((int) $totals->pending),
                ]))
                ->descriptionIcon('heroicon-m-arrow-down-tray')
                ->color($inProgress > 0 ? 'warning' : 'gray'),

            Stat::make(__('Failed'), Number::format($failed))
                ->description($failed > 0
                    ? __('Filter by Failed status to review them')
                    : __('Nothing has failed'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }
}
