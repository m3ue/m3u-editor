<?php

namespace App\Filament\Resources\DynamicGroups\RelationManagers;

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Series\SeriesResource;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Series;
use App\Services\DynamicGroupCacheDispatchService;
use App\Settings\GeneralSettings;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Series members of the parent DynamicGroup. Visible only when the parent's
 * `type` is `'series'` - DynamicGroups are single-type by construction, so a
 * vod-type parent has zero series to show and the tab is hidden.
 *
 * The relation itself is strictly read-only (membership is computed by
 * `SyncDynamicGroups`), but the table exposes a per-row "Cache Now" bulk
 * action so operators can pick which series (and which episodes inside each)
 * to download.
 */
class SeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'series';

    protected static ?string $title = 'Series';

    public static function getNavigationLabel(): string
    {
        return __('Series');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'series';
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(__('Series'))
            ->badge($ownerRecord->series()->count())
            ->icon('heroicon-m-tv');
    }

    public function table(Table $table): Table
    {
        // Reuse SeriesResource's full table setup - see the parallel comment on
        // `ChannelsRelationManager::table()` for why (drift prevention) and why
        // recordActions are stripped back out (this manager stays strictly read-
        // only for record-level actions, but DOES expose a Cache Now bulk action).
        //
        // Per-series 'is THIS series cached?' indicator, aggregated across all
        // episode cache rows with worst-active-wins ordering (any-Downloading
        // > any-Pending > any-Failed > any-Completed > none). Same TextColumn
        // badge shape (state-driven label/color/icon via
        // CachedContentFileStatus) as the Dynamic Group Cache Activity widget
        // and ChannelsRelationManager's is_cached column so the VOD and
        // Series tabs render identically. Inserted before the inherited
        // 'has_metadata' column so Cached / Metadata stay paired as binary
        // indicators. ViewDynamicGroup polls every 2s so the badge refreshes
        // while Downloading jobs run.
        //
        // ponytail: aggregateCachedFileForSeries() runs one cachedFilesForSeries()
        // query per row render; the icon/color closures reuse the same state
        // resolution via Filament's named-param magic. Batch via WHERE IN
        // grouped by series tmdb_id when page scale grows.
        $cachedColumn = TextColumn::make('is_cached')
            ->label(__('Cache'))
            ->badge()
            ->getStateUsing(fn (Series $record): ?CachedContentFileStatus => $this->aggregateCachedFileForSeries($record)?->status)
            ->formatStateUsing(fn (?CachedContentFileStatus $state): ?string => $state?->getLabel())
            ->color(fn (?CachedContentFileStatus $state): ?string => $state?->getColor())
            ->icon(fn (?CachedContentFileStatus $state): ?string => $state?->getIcon())
            ->placeholder(__('Not cached'));

        $table = SeriesResource::setupTable($table, $this->ownerRecord->id)
            ->recordTitleAttribute('name')
            ->recordActions([]);

        // Insert before the inherited 'has_metadata' column, mirroring
        // ChannelsRelationManager's placement so Cached / Metadata stay
        // paired as binary indicators.
        $columns = $table->getColumns();
        $metaKey = array_search('has_metadata', array_map(fn ($c) => $c->getName(), $columns), true);
        if ($metaKey !== false) {
            $inserted = false;
            $reordered = [];
            foreach ($columns as $key => $col) {
                if ($key === $metaKey && ! $inserted) {
                    $reordered['is_cached'] = $cachedColumn;
                    $inserted = true;
                }
                $reordered[$key] = $col;
            }
            $table->columns($reordered);
        } else {
            $table->pushColumns([$cachedColumn]);
        }

        return $table->bulkActions([
            BulkAction::make('cache_now')
                ->label(__('Cache Now'))
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading(__('Cache selected series'))
                ->modalDescription(__('Queue download jobs for every episode of every selected series? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget.'))
                ->modalSubmitActionLabel(__('Yes, cache now'))
                ->action(function (Collection $records): void {
                    $settings = app(GeneralSettings::class);
                    if (! $settings->enable_dynamic_group_cache) {
                        Notification::make()
                            ->warning()
                            ->title(__('Dynamic Group Caching is disabled'))
                            ->body(__('Enable it in Preferences → Dynamic Groups before queueing cache downloads.'))
                            ->duration(10000)
                            ->send();

                        return;
                    }

                    /** @var DynamicGroup $group */
                    $group = $this->ownerRecord;
                    $playlist = $group->playlist;
                    if (! $playlist) {
                        return;
                    }

                    /** @var DynamicGroupCacheDispatchService $service */
                    $service = app(DynamicGroupCacheDispatchService::class);
                    $rule = $service->resolveRuleForGroup($group);
                    $cacheEnabled = $rule && ($rule['cache_enabled'] ?? false);

                    $dispatched = 0;
                    $skipped = 0;

                    /** @var Series $series */
                    foreach ($records as $series) {
                        if (! $cacheEnabled) {
                            $skipped++;

                            continue;
                        }
                        foreach ($series->episodes as $episode) {
                            /** @var Episode $episode */
                            if ($service->dispatchForEpisode($playlist, $group, $episode, $rule)) {
                                $dispatched++;
                            } else {
                                $skipped++;
                            }
                        }
                    }

                    if ($dispatched === 0) {
                        Notification::make()
                            ->info()
                            ->title(__('No cache jobs queued'))
                            ->body($skipped > 0
                                ? __('Skipped :skipped — caching not enabled for this group.', ['skipped' => $skipped])
                                : __('Nothing eligible to cache.')
                            )
                            ->send();

                        return;
                    }

                    $title = $dispatched === 1
                        ? __('Dispatched 1 cache job.')
                        : __('Dispatched :count cache jobs.', ['count' => $dispatched]);
                    Notification::make()
                        ->success()
                        ->title($title)
                        ->body($skipped > 0
                            ? __('Skipped :skipped.', ['skipped' => $skipped])
                            : __('Track progress in the Dynamic Group Cache Activity widget.')
                        )
                        ->duration(10000)
                        ->send();
                }),
        ]);
    }

    /**
     * All CachedContentFile rows for a Series — one per cached episode.
     * tmdb_id-only on purpose: any quality variant of each episode's
     * cache row counts as "the cache for this series".
     *
     * ponytail: one query per row render; for page-scale growth, switch
     * to a single WHERE IN grouped by series tmdb_id.
     */
    private function cachedFilesForSeries(Series $series): Collection
    {
        $tmdbId = $series->tmdb_id !== null ? (string) $series->tmdb_id : null;
        if ($tmdbId === null) {
            return collect();
        }

        return CachedContentFile::query()
            ->where('content_type', 'episode')
            ->where('tmdb_id', $tmdbId)
            ->get();
    }

    /**
     * Worst-active-wins aggregation across all CachedContentFile rows
     * for a Series. Returns the first CachedContentFile matching the
     * highest-priority status (any-Downloading > any-Pending >
     * any-Failed > any-Completed > none) so callers - the is_cached
     * IconColumn's state and tooltip closures - read the row's progress
     * / ETA without a second query.
     *
     * ponytail: piggybacks on cachedFilesForSeries()'s per-row query;
     * the column closures then reuse the returned model. Same
     * batch-via-WHERE-IN lever applies when page scale grows.
     */
    private function aggregateCachedFileForSeries(Series $series): ?CachedContentFile
    {
        $files = $this->cachedFilesForSeries($series);
        if ($files->isEmpty()) {
            return null;
        }

        return $files->firstWhere('status', CachedContentFileStatus::Downloading)
            ?? $files->firstWhere('status', CachedContentFileStatus::Pending)
            ?? $files->firstWhere('status', CachedContentFileStatus::Failed)
            ?? $files->first();
    }
}
