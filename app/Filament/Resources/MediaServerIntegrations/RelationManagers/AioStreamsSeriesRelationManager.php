<?php

namespace App\Filament\Resources\MediaServerIntegrations\RelationManagers;

use App\Filament\Resources\Series\SeriesResource;
use App\Jobs\NotifyAioStreamsResolutionComplete;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\Series;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * AIOStreams-added series for this integration — mirrors AioStreamsMoviesRelationManager,
 * reusing SeriesResource's table columns/filters/actions for parity with the default
 * Series table. Resolution status lives per-episode (a series itself has no stream of
 * its own to resolve — see AioStreamsBrowse::findOrCreateAioSeries()), so this shows
 * aggregate episode counts by status rather than a single series-level status.
 */
class AioStreamsSeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'series';

    protected static ?string $title = 'Series';

    public static function getNavigationLabel(): string
    {
        return __('Series');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'aiostreams';
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(__('Series'))
            ->badge($ownerRecord->series()->where('is_custom', true)->count())
            ->icon('heroicon-m-tv');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return SeriesResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('name')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('is_custom', true)
                ->withCount([
                    'episodes as resolved_episodes_count' => fn (Builder $q) => $q->where('aio_resolution_status', 'resolved'),
                    'episodes as failed_episodes_count' => fn (Builder $q) => $q->where('aio_resolution_status', 'failed'),
                    'episodes as scheduled_episodes_count' => fn (Builder $q) => $q->where('aio_resolution_status', 'scheduled'),
                ]))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('created_at', 'desc')
            ->columns([
                ...SeriesResource::getTableColumns(showCategory: false, showPlaylist: false),
                TextColumn::make('resolved_episodes_count')
                    ->label(__('Resolved'))
                    ->badge()
                    ->color('success')
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('scheduled_episodes_count')
                    ->label(__('Awaiting Air Date'))
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->toggleable()
                    ->visible(fn ($record) => ($record?->scheduled_episodes_count ?? 0) > 0),
                TextColumn::make('failed_episodes_count')
                    ->label(__('Failed'))
                    ->badge()
                    ->color('danger')
                    ->alignCenter()
                    ->toggleable()
                    ->visible(fn ($record) => ($record?->failed_episodes_count ?? 0) > 0),
            ])
            ->filters(SeriesResource::getTableFilters(showPlaylist: false))
            ->recordActions([
                // SeriesResource's own EditAction opens a slideOver — AIOStreams-added series have
                // enough AIO-specific fields (resolution status, integration linkage) that editing
                // them inline is confusing, so send users to the full Series edit page instead.
                ...array_map(
                    fn ($action) => $action instanceof EditAction
                        ? $action->slideOver(false)->url(fn (Series $record) => SeriesResource::getUrl('edit', ['record' => $record]))
                        : $action,
                    SeriesResource::getTableActions(),
                ),
                Action::make('rescan')
                    ->tooltip(__('Rescan episodes for stream candidates'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (Series $record) {
                        $episodeIds = self::rescanSeriesEpisodes($record);

                        NotifyAioStreamsResolutionComplete::dispatch([], $episodeIds, $record->user_id, $record->name)
                            ->delay(now()->addSeconds(15));

                        Notification::make()->success()->title(__(':count episode(s) rescanning, check back shortly', ['count' => count($episodeIds)]))->send();
                    })
                    ->button()
                    ->hiddenLabel()
                    ->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkAction::make('rescan')
                    ->label(__('Rescan'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $episodeIds = $records->flatMap(fn (Series $record) => self::rescanSeriesEpisodes($record))->all();

                        NotifyAioStreamsResolutionComplete::dispatch([], $episodeIds, $records->first()?->user_id, __('Series rescan'))
                            ->delay(now()->addSeconds(15));

                        Notification::make()->success()->title(__(':count episode(s) rescanning, check back shortly', ['count' => count($episodeIds)]))->send();
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Re-resolve every already-aired episode of the series (skips episodes still
     * awaiting their air date, which have never resolved and aren't playable yet).
     *
     * @return array<int> The IDs of the episodes that were queued for resolution.
     */
    private static function rescanSeriesEpisodes(Series $record): array
    {
        $episodes = $record->episodes()->where('aio_resolution_status', '!=', 'scheduled')->get();

        foreach ($episodes as $episode) {
            $episode->update(['aio_resolution_status' => 'pending']);
            ResolveAioStreamsEpisode::dispatch($episode->id);
        }

        return $episodes->pluck('id')->all();
    }
}
