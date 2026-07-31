<?php

namespace App\Filament\Resources\MediaServerIntegrations\RelationManagers;

use App\Filament\Resources\Vods\VodResource;
use App\Jobs\NotifyAioStreamsResolutionComplete;
use App\Jobs\ResolveAioStreamsChannel;
use App\Models\Channel;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
 * AIOStreams-added movies (VOD Channels) for this integration — distinct from
 * MoviesRelationManager, which lists content synced from a "real" media server
 * library and is hidden for aiostreams (nothing is synced there; everything
 * here was added on-demand via the browse UI instead). Reuses VodResource's
 * table columns/filters/actions for visual and functional parity with the
 * default VOD Channels table, with AIO-specific status/retry bolted on.
 */
class AioStreamsMoviesRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static ?string $title = 'Movies';

    public static function getNavigationLabel(): string
    {
        return __('Movies');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'aiostreams';
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(__('Movies'))
            ->badge($ownerRecord->channels()->where('is_vod', true)->where('is_custom', true)->count())
            ->icon('heroicon-m-film');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return VodResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('title')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['tags', 'epgChannel', 'playlist'])
                    ->where('is_vod', true)
                    ->where('is_custom', true);
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('created_at', 'desc')
            ->columns([
                ...VodResource::getTableColumns(showGroup: false, showPlaylist: false),
                TextColumn::make('aio_resolution_status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'resolved' => __('Resolved'),
                        'partial' => __('Partial'),
                        'failed' => __('Failed'),
                        'pending' => __('Resolving...'),
                        default => __('Unknown'),
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'resolved' => 'success',
                        'partial' => 'warning',
                        'failed' => 'danger',
                        'pending' => 'gray',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('aio_last_resolved_at')
                    ->label(__('Last Resolved'))
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters(VodResource::getTableFilters(showPlaylist: false))
            ->recordActions([
                ...VodResource::getTableActions(),
                Action::make('rescan')
                    ->tooltip(__('Rescan for stream candidates'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (Channel $record) {
                        $record->update(['aio_resolution_status' => 'pending']);
                        ResolveAioStreamsChannel::dispatch($record->id);
                        NotifyAioStreamsResolutionComplete::dispatch([$record->id], [], $record->user_id, $record->title_custom ?? $record->title ?? $record->name)
                            ->delay(now()->addSeconds(15));

                        Notification::make()->success()->title(__('Rescanning stream, check back shortly'))->send();
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
                        $records->each(function (Channel $record) {
                            $record->update(['aio_resolution_status' => 'pending']);
                            ResolveAioStreamsChannel::dispatch($record->id);
                        });

                        NotifyAioStreamsResolutionComplete::dispatch(
                            $records->pluck('id')->all(),
                            [],
                            $records->first()?->user_id,
                            __('Movie rescan'),
                        )->delay(now()->addSeconds(15));

                        Notification::make()->success()->title(__(':count movie(s) rescanning, check back shortly', ['count' => $records->count()]))->send();
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
