<?php

namespace App\Filament\Resources\EpgMaps\RelationManagers;

use App\Models\Channel;
use App\Services\EpgCacheService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MappedChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'mappedChannels';

    protected static ?string $title = 'Mapped';

    protected static bool $isBadgeDeferred = true;

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->mappedChannels()->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Mapped channels'))
            ->description(__('Channels in this map\'s scope that already have an EPG mapping, whether applied automatically, via candidate review, or set before this map existed.'))
            ->deferLoading()
            ->defaultSort('title', 'asc')
            ->defaultPaginationPageOption(25)
            ->paginated([10, 25, 50, 100, 200])
            ->columns([
                TextColumn::make('title')
                    ->label(__('Channel'))
                    ->state(fn (Channel $record): string => $record->title_custom ?: $record->title)
                    ->searchable(['title', 'title_custom', 'name', 'name_custom'])
                    ->sortable(),
                TextColumn::make('epgChannel.display_name')
                    ->label(__('Mapped EPG channel'))
                    ->state(fn (Channel $record): ?string => $record->epgChannel?->display_name ?: $record->epgChannel?->name)
                    ->description(fn (Channel $record): ?string => $record->epgChannel?->channel_id ?: null)
                    ->searchable(query: function ($query, string $search) {
                        return $query->orWhereHas('epgChannel', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('display_name', 'like', "%{$search}%")
                                ->orWhere('channel_id', 'like', "%{$search}%");
                        });
                    })
                    ->placeholder(__('EPG channel no longer available')),
                TextColumn::make('group.name')
                    ->label(__('Group'))
                    ->toggleable()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('unmap')
                    ->label(__('Unmap'))
                    ->icon('heroicon-s-x-mark')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Channel $record): string => __('Clear the EPG mapping for :channel?', [
                        'channel' => $record->title_custom ?: $record->title,
                    ]))
                    ->visible(fn (): bool => static::ownerMatchesAuth($this->ownerRecord))
                    ->action(fn (Channel $record) => static::unmapMany([$record->id]))
                    ->hiddenLabel()
                    ->button()
                    ->size('sm'),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('unmap')
                        ->label(__('Unmap selected'))
                        ->icon('heroicon-s-x-mark')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription(__('Clear the EPG mapping for the selected channels?'))
                        ->visible(fn (): bool => static::ownerMatchesAuth($this->ownerRecord))
                        ->action(fn ($records) => static::unmapMany($records->pluck('id')->all())),
                ]),
            ]);
    }

    protected static function ownerMatchesAuth($ownerRecord): bool
    {
        return $ownerRecord !== null
            && $ownerRecord->user_id === auth()->id()
            && $ownerRecord->epg?->user_id === auth()->id()
            && $ownerRecord->playlist_id !== null;
    }

    /** @param  array<int, int>  $ids */
    protected static function unmapMany(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Channel::query()->whereIn('id', $ids)->update(['epg_channel_id' => null]);

        $records = Channel::query()
            ->whereIn('id', $ids)
            ->with(['playlist.mergedPlaylists', 'customPlaylists'])
            ->get();

        $affectedPlaylists = collect();
        foreach ($records as $channel) {
            if ($channel->playlist) {
                $affectedPlaylists->push($channel->playlist);
                foreach ($channel->playlist->mergedPlaylists as $merged) {
                    $affectedPlaylists->push($merged);
                }
            }
            foreach ($channel->customPlaylists as $custom) {
                $affectedPlaylists->push($custom);
            }
        }
        // clearPlaylistEpgCacheFile() returns false when there was nothing to clear;
        // Collection::each() stops on the first false return, so the callback must not
        // forward it or later playlists get silently skipped.
        $affectedPlaylists->unique(fn ($playlist) => $playlist->getTable().'-'.$playlist->id)
            ->each(function ($playlist) {
                EpgCacheService::clearPlaylistEpgCacheFile($playlist);
            });

        Notification::make()
            ->success()
            ->title(trans_choice(':count mapping cleared.|:count mappings cleared.', count($ids), ['count' => count($ids)]))
            ->send();
    }
}
