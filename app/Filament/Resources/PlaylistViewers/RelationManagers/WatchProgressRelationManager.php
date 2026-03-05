<?php

namespace App\Filament\Resources\PlaylistViewers\RelationManagers;

use App\Models\ViewerWatchProgress;
use App\Services\LogoService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class WatchProgressRelationManager extends RelationManager
{
    protected static string $relationship = 'watchProgress';

    protected static ?string $title = 'Watch History';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getTabs(): array
    {
        return [
            'live' => Tab::make('Live TV')
                ->icon('heroicon-s-tv')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'live')->count())
                ->query(fn ($query) => $query->where('content_type', 'live')),

            'vod' => Tab::make('VOD')
                ->icon('heroicon-s-film')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'vod')->count())
                ->query(fn ($query) => $query->where('content_type', 'vod')),

            'episode' => Tab::make('Series')
                ->icon('heroicon-s-play')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'episode')->count())
                ->query(fn ($query) => $query->where('content_type', 'episode')),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->persistSortInSession()
            ->filtersTriggerAction(fn ($action) => $action->button()->label('Filters'))
            ->deferLoading()
            ->modifyQueryUsing(fn ($query) => match ($this->activeTab ?? 'live') {
                'episode' => $query->with(['episode', 'episode.series', 'episode.playlist']),
                default => $query->with(['channel', 'channel.epgChannel', 'channel.playlist']),
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('last_watched_at', 'desc')
            ->columns([

                // ── Shared ────────────────────────────────────────────────
                ImageColumn::make('content_logo')
                    ->label('')
                    ->getStateUsing(function (ViewerWatchProgress $record): string {
                        if ($record->content_type === 'episode') {
                            return LogoService::getEpisodeLogoUrl($record->episode);
                        }

                        return LogoService::getChannelLogoUrl($record->channel);
                    })
                    ->extraImgAttributes(fn ($record): array => match ($this->activeTab ?? 'live') {
                        'live' => [
                            'style' => 'height:2.5rem; width:auto; border-radius:4px;', // Live channel style
                        ],
                        'vod' => [
                            'style' => 'width:80px; height:120px; border-radius:4px;', // VOD channel style
                        ],
                        'episode' => [
                            'style' => 'width:120px; height:80px; border-radius:4px;', // Episode style
                        ],
                        default => [],
                    })
                    ->checkFileExistence(false),

                // ── Live TV ───────────────────────────────────────────────
                TextColumn::make('live_channel_name')
                    ->label('Channel')
                    ->getStateUsing(fn (ViewerWatchProgress $record): string => $record->channel?->title ?? $record->channel?->name ?? "Stream #{$record->stream_id}")
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        if (($this->activeTab ?? 'live') !== 'live') {
                            return $query;
                        }
                        $term = mb_strtolower($search);

                        return $query->whereHas('channel', fn (Builder $q) => $q->whereRaw('LOWER(title) LIKE ?', ["%{$term}%"])->orWhereRaw('LOWER(name) LIKE ?', ["%{$term}%"]));
                    })
                    ->visible(fn () => ($this->activeTab ?? 'live') === 'live'),

                TextColumn::make('live_watch_count')
                    ->label('Tunes In')
                    ->getStateUsing(fn (ViewerWatchProgress $record): int => $record->watch_count)
                    ->sortable(false)
                    ->visible(fn () => ($this->activeTab ?? 'live') === 'live'),

                // ── VOD ───────────────────────────────────────────────────
                TextColumn::make('vod_title')
                    ->label('Movie')
                    ->getStateUsing(fn (ViewerWatchProgress $record): string => $record->channel?->title ?? $record->channel?->name ?? "Stream #{$record->stream_id}")
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        if (($this->activeTab ?? 'live') !== 'vod') {
                            return $query;
                        }
                        $term = mb_strtolower($search);

                        return $query->whereHas('channel', fn (Builder $q) => $q->whereRaw('LOWER(title) LIKE ?', ["%{$term}%"])->orWhereRaw('LOWER(name) LIKE ?', ["%{$term}%"]));
                    })
                    ->visible(fn () => ($this->activeTab ?? 'live') === 'vod'),

                TextColumn::make('vod_rating')
                    ->label('Rating')
                    ->getStateUsing(function (ViewerWatchProgress $record): ?string {
                        $info = $record->channel?->info ?? [];

                        return $info['rating'] ?? null;
                    })
                    ->badge()
                    ->visible(fn () => ($this->activeTab ?? 'live') === 'vod'),

                // ── Series / Episodes ──────────────────────────────────────
                TextColumn::make('series_name')
                    ->label('Series')
                    ->getStateUsing(fn (ViewerWatchProgress $record): ?string => $record->episode?->series?->name)
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        if (($this->activeTab ?? 'live') !== 'episode') {
                            return $query;
                        }
                        $term = mb_strtolower($search);

                        return $query->whereHas('episode.series', fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"]));
                    })
                    ->visible(fn () => ($this->activeTab ?? 'live') === 'episode'),

                TextColumn::make('season_number')
                    ->label('Season')
                    ->getStateUsing(fn (ViewerWatchProgress $record): ?int => $record->episode?->season)
                    ->sortable(false)
                    ->visible(fn () => ($this->activeTab ?? 'live') === 'episode'),

                TextColumn::make('episode_number')
                    ->label('Ep #')
                    ->getStateUsing(fn (ViewerWatchProgress $record): ?int => $record->episode?->episode_num)
                    ->sortable(false)
                    ->visible(fn () => ($this->activeTab ?? 'live') === 'episode'),

                TextColumn::make('episode_title')
                    ->label('Episode Title')
                    ->getStateUsing(fn (ViewerWatchProgress $record): ?string => $record->episode?->title)
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        if (($this->activeTab ?? 'live') !== 'episode') {
                            return $query;
                        }
                        $term = mb_strtolower($search);

                        return $query->whereHas('episode', fn (Builder $q) => $q->whereRaw('LOWER(title) LIKE ?', ["%{$term}%"]));
                    })
                    ->visible(fn () => ($this->activeTab ?? 'live') === 'episode'),

                // ── VOD + Series ──────────────────────────────────────────
                ProgressColumn::make('progress')
                    ->width('120px')
                    ->label('Progress')
                    ->progress(function (ViewerWatchProgress $record): string {
                        if (! $record->duration_seconds || $record->duration_seconds <= 0) {
                            return 0;
                        }

                        return min(100, (int) round($record->position_seconds / $record->duration_seconds * 100));
                    })
                    ->visible(fn () => in_array($this->activeTab ?? 'live', ['vod', 'episode']))
                    ->toggleable(),

                TextColumn::make('duration')
                    ->label('Position / Duration')
                    ->getStateUsing(function (ViewerWatchProgress $record): string {
                        if (! $record->duration_seconds || $record->duration_seconds <= 0) {
                            return $this->formatSeconds($record->position_seconds);
                        }
                        $pct = min(100, (int) round($record->position_seconds / $record->duration_seconds * 100));

                        return "{$this->formatSeconds($record->position_seconds)} / {$this->formatSeconds($record->duration_seconds)}";
                    })
                    ->visible(fn () => in_array($this->activeTab ?? 'live', ['vod', 'episode'])),

                IconColumn::make('completed')
                    ->label('Done')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->visible(fn () => in_array($this->activeTab ?? 'live', ['vod', 'episode'])),

                TextColumn::make('watch_count')
                    ->label('Plays')
                    ->sortable()
                    ->visible(fn () => in_array($this->activeTab ?? 'live', ['vod', 'episode'])),

                // ── Shared ────────────────────────────────────────────────
                TextColumn::make('last_watched_at')
                    ->label('Last Watched')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                Action::make('play')
                    ->tooltip('Play')
                    ->action(function ($record, $livewire) {
                        $livewire->dispatch(
                            'openFloatingStream',
                            ($this->activeTab ?? 'live') === 'episode'
                                ? $record->episode?->getFloatingPlayerAttributes()
                                : $record->channel?->getFloatingPlayerAttributes()
                        );
                    })
                    ->icon('heroicon-s-play-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->filters([
                TernaryFilter::make('completed')
                    ->label('Completed')
                    ->hidden(fn () => ($this->activeTab ?? 'live') === 'live'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function formatSeconds(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0:00';
        }
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }
}
