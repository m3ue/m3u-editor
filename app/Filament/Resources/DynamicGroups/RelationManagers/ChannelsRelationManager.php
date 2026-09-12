<?php

namespace App\Filament\Resources\DynamicGroups\RelationManagers;

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\DynamicGroups\Widgets\DynamicGroupCacheActivityWidget;
use App\Filament\Resources\Vods\VodResource;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Services\DynamicGroupCacheDispatchService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * VOD (Channel) members of the parent DynamicGroup. Visible only when the
 * parent's `type` is `'vod'` - DynamicGroups are single-type by construction
 * (matching their `dynamic_groups_config` rule row), so a series-type parent
 * has zero channels to show and the tab is hidden.
 *
 * The relation itself is strictly read-only (membership is computed by
 * `SyncDynamicGroups`), but the table exposes a per-row "Cache Now" bulk
 * action so operators can pick which channels to download — useful when
 * the dynamic group has hundreds of items and only a subset is worth
 * pre-caching.
 */
class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static ?string $title = 'Movies';

    public static function getNavigationLabel(): string
    {
        return __('Movies');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'vod';
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(__('Movies'))
            ->badge($ownerRecord->channels()->count())
            ->icon('heroicon-m-film');
    }

    public function table(Table $table): Table
    {
        // Reuse VodResource's full table setup (columns, filters, eager-loading,
        // pagination, sort) so this view can never drift from the canonical VOD
        // table - the same convention `VodGroups\RelationManagers\VodRelationManager`
        // uses. `$this->ownerRecord->id` is only consulted by setupTable() to decide
        // column visibility (truthy => hide Group/Playlist, same as `showGroup: false,
        // showPlaylist: false` before), not to scope the query - Filament's relation
        // manager machinery already scopes via the `channels` relationship.
        //
        // setupTable() wires up VodResource's record/bulk actions (edit, delete,
        // fetch metadata, sync, ...), which would break this manager's read-only
        // contract - recordActions are replaced with the Cache Now / Delete cache
        // menu below, and the Cache Now bulk action stays for multi-select kicks.
        // Per-movie 'is THIS movie cached?' indicator. Same shape as the
        // Dynamic Group Cache Activity widget's `status` column (TextColumn
        // badge with state-driven label/color/icon) and the PlaylistResource
        // `status` column (TextColumn badge with enum getColor()) - keeps the
        // VOD and Series tabs and the activity widget rendering identically,
        // and the polling added on ViewDynamicGroup keeps the badge fresh
        // while Downloading jobs run. Inserted before the inherited
        // 'has_metadata' column rather than pushed to the end - Cached /
        // Metadata are both binary indicators that read naturally together.
        //
        // ponytail: two cachedFileForChannel() queries per row render (state
        // closure + per-row closure that re-reads it). Cache once via the
        // state closure and read back from the column, or batch via WHERE IN
        // over the visible page's tmdb_ids, when list-page scale grows.
        $cachedColumn = TextColumn::make('is_cached')
            ->label(__('Cache'))
            ->badge()
            ->getStateUsing(fn (Channel $record): ?CachedContentFileStatus => $this->cachedFileForChannel($record)?->status)
            ->formatStateUsing(fn (?CachedContentFileStatus $state): ?string => $state?->getLabel())
            ->color(fn (?CachedContentFileStatus $state): ?string => $state?->getColor())
            ->icon(fn (?CachedContentFileStatus $state): ?string => $state?->getIcon())
            ->placeholder(__('Not cached'));

        $table = VodResource::setupTable($table, $this->ownerRecord->id)
            ->recordTitleAttribute('title')
            ->recordActions([
                ActionGroup::make([
                    Action::make('cache_now_record')
                        ->label(__('Cache Now'))
                        ->icon('heroicon-o-cloud-arrow-down')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading(__('Cache this movie?'))
                        ->modalDescription(__('Queue a download job for this movie? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget.'))
                        ->modalSubmitActionLabel(__('Yes, cache now'))
                        ->action(function (Channel $record): void {
                            $group = $this->ownerRecord;
                            $playlist = $group->playlist;
                            if (! $playlist) {
                                return;
                            }
                            $service = app(DynamicGroupCacheDispatchService::class);
                            $rule = $service->resolveRuleForGroup($group);

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
                            if (! $rule || ! ($rule['cache_enabled'] ?? false)) {
                                Notification::make()
                                    ->warning()
                                    ->title(__('Caching is not enabled for this group'))
                                    ->body(__('Check the rule in Preferences → Dynamic Groups.'))
                                    ->duration(10000)
                                    ->send();

                                return;
                            }

                            $dispatched = $service->dispatchForChannel($playlist, $group, $record, $rule);

                            Notification::make()
                                ->success()
                                ->title($dispatched
                                    ? __('Dispatched 1 cache job.')
                                    : __('Nothing to cache — already complete or in cooldown.')
                                )
                                ->body(__('Track progress in the Dynamic Group Cache Activity widget.'))
                                ->duration(10000)
                                ->send();
                        }),
                    Action::make('cancel_download_record')
                        ->label(__('Cancel download'))
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->visible(fn (Channel $record): bool => in_array(
                            $this->cachedFileForChannel($record)?->status,
                            [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading],
                            true
                        ))
                        ->requiresConfirmation()
                        ->modalHeading(__('Cancel this in-flight download?'))
                        ->modalDescription(__('Removes the tracking row and any partial storage file, and stops the transfer. An active download stops within a few seconds; a not-yet-started one may rarely still begin if a worker was already about to pick it up.'))
                        ->modalSubmitActionLabel(__('Cancel download'))
                        ->action(function (Channel $record): void {
                            $cf = $this->cachedFileForChannel($record);
                            if ($cf && in_array($cf->status, [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true)) {
                                DynamicGroupCacheActivityWidget::deleteCachedFile($cf);
                                Notification::make()
                                    ->success()
                                    ->title(__('Download cancelled'))
                                    ->send();
                            }
                        }),
                    Action::make('delete_cache_record')
                        ->label(__('Delete cache'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (Channel $record): bool => $this->cachedFileForChannel($record) !== null)
                        ->requiresConfirmation()
                        ->modalHeading(__('Delete cached file for this movie?'))
                        ->modalDescription(__('Removes the cached file from Storage and deletes the cached_content_files row. Playback will fall back to the live source.'))
                        ->modalSubmitActionLabel(__('Yes, delete cache'))
                        ->action(function (Channel $record): void {
                            $cf = $this->cachedFileForChannel($record);
                            if ($cf) {
                                DynamicGroupCacheActivityWidget::deleteCachedFile($cf);
                                Notification::make()
                                    ->success()
                                    ->title(__('Deleted 1 cached file'))
                                    ->send();
                            }
                        }),
                ])->button()->hiddenLabel()->size('sm'),
            ], RecordActionsPosition::BeforeCells);

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
                ->modalHeading(__('Cache selected movies'))
                ->modalDescription(__('Queue download jobs for every selected movie? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget.'))
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

                    $dispatched = 0;
                    $skipped = 0;

                    /** @var Channel $channel */
                    foreach ($records as $channel) {
                        if (! $rule || ! ($rule['cache_enabled'] ?? false)) {
                            $skipped++;

                            continue;
                        }
                        if ($service->dispatchForChannel($playlist, $group, $channel, $rule)) {
                            $dispatched++;
                        } else {
                            $skipped++;
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
     * First CachedContentFile row for a Channel — used by the per-row
     * delete action's `visible()` (returns null when nothing to delete)
     * and the same action's handler. tmdb_id-only on purpose: any quality
     * variant of the cache row counts as "the cache for this movie".
     *
     * ponytail: one query per row render; if page scale grows, switch to
     * a single WHERE IN over the visible page's tmdb_ids.
     */
    private function cachedFileForChannel(Channel $channel): ?CachedContentFile
    {
        $tmdbId = $channel->getTmdbId();
        if ($tmdbId === null) {
            return null;
        }

        return CachedContentFile::query()
            ->where('content_type', 'movie')
            ->where('tmdb_id', (string) $tmdbId)
            ->first();
    }
}
