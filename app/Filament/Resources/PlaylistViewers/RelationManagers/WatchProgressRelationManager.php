<?php

namespace App\Filament\Resources\PlaylistViewers\RelationManagers;

use App\Models\ViewerWatchProgress;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WatchProgressRelationManager extends RelationManager
{
    protected static string $relationship = 'watchProgress';

    protected static ?string $title = 'Watch History';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_watched_at', 'desc')
            ->columns([
                ImageColumn::make('content_logo')
                    ->label('')
                    ->getStateUsing(fn (ViewerWatchProgress $record): ?string => $record->content_logo)
                    ->height(50)
                    ->width(35)
                    ->defaultImageUrl('/images/placeholder-movie.png'),

                TextColumn::make('content_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'live' => 'Live',
                        'vod' => 'VOD',
                        'episode' => 'Episode',
                        default => ucfirst($state),
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'live' => 'danger',
                        'vod' => 'primary',
                        'episode' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('content_title')
                    ->label('Title')
                    ->getStateUsing(fn (ViewerWatchProgress $record): string => $record->content_title)
                    ->wrap()
                    ->searchable(false),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->getStateUsing(function (ViewerWatchProgress $record): string {
                        if (! $record->duration_seconds || $record->duration_seconds <= 0) {
                            return $this->formatSeconds($record->position_seconds);
                        }
                        $pct = min(100, (int) round($record->position_seconds / $record->duration_seconds * 100));

                        return "{$pct}% ({$this->formatSeconds($record->position_seconds)} / {$this->formatSeconds($record->duration_seconds)})";
                    })
                    ->sortable(false),

                IconColumn::make('completed')
                    ->label('Done')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),

                TextColumn::make('watch_count')
                    ->label('Plays')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_watched_at')
                    ->label('Last Watched')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('content_type')
                    ->label('Type')
                    ->options([
                        'live' => 'Live',
                        'vod' => 'VOD',
                        'episode' => 'Episode',
                    ]),

                TernaryFilter::make('completed')
                    ->label('Completed'),
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
