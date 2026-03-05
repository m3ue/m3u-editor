<?php

namespace App\Filament\Resources\PlaylistViewers\RelationManagers;

use App\Models\ViewerWatchProgress;
use App\Services\LogoService;
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
                ->icon('heroicon-o-signal')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'live')->count())
                ->query(fn ($query) => $query->where('content_type', 'live')),

            'vod' => Tab::make('VOD')
                ->icon('heroicon-o-film')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'vod')->count())
                ->query(fn ($query) => $query->where('content_type', 'vod')),

            'episode' => Tab::make('Series')
                ->icon('heroicon-o-tv')
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
        $activeTab = $this->activeTab ?? 'live';

        return $table
            ->persistSortInSession()
            ->filtersTriggerAction(fn ($action) => $action->button()->label('Filters'))
            ->deferLoading()
            ->modifyQueryUsing(fn ($query) => match ($activeTab) {
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
                    ->height(50)
                    ->width(35)
                    ->checkFileExistence(false),

                // ── Live TV ───────────────────────────────────────────────
                TextColumn::make('live_channel_name')
                    ->label('Channel')
                    ->getStateUsing(fn (ViewerWatchProgress $record): string => $record->channel?->title ?? $record->channel?->name ?? "Stream #{$record->stream_id}")
                    ->wrap()
                    ->searchable(false)
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
                    ->searchable(false)
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
                    ->searchable(false)
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
                    ->searchable(false)
                    ->visible(fn () => ($this->activeTab ?? 'live') === 'episode'),

                // ── VOD + Series ──────────────────────────────────────────
                TextColumn::make('progress')
                    ->label('Progress')
                    ->getStateUsing(function (ViewerWatchProgress $record): string {
                        if (! $record->duration_seconds || $record->duration_seconds <= 0) {
                            return $this->formatSeconds($record->position_seconds);
                        }
                        $pct = min(100, (int) round($record->position_seconds / $record->duration_seconds * 100));

                        return "{$pct}% ({$this->formatSeconds($record->position_seconds)} / {$this->formatSeconds($record->duration_seconds)})";
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
