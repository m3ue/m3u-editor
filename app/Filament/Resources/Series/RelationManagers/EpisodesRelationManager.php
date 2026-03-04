<?php

namespace App\Filament\Resources\Series\RelationManagers;

use Filament\Actions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EpisodesRelationManager extends RelationManager
{
    protected static string $relationship = 'episodes';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['season', 'series', 'playlist']);
            })
            ->defaultGroup('season')
            ->defaultSort('episode_num', 'asc')
            ->recordUrl(null) // Disable default record URL behavior
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                ImageColumn::make('info.movie_image')
                    ->label('Cover')
                    ->height(60)
                    ->width(40)
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['movie_image'] ?? $info['cover_big'] ?? null;
                    })
                    ->defaultImageUrl('/images/placeholder-episode.png'),

                TextColumn::make('title')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->title),

                ToggleColumn::make('enabled')
                    ->label('Enabled'),

                TextColumn::make('info.plot')
                    ->label('Plot')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->plot ?? $record->info['plot'] ?? null)
                    ->getStateUsing(function ($record) {
                        // Check the dedicated plot column first, then fall back to info.plot
                        if (! empty($record->plot)) {
                            return $record->plot;
                        }
                        $info = $record->info ?? [];

                        return $info['plot'] ?? null;
                    })
                    ->placeholder('No description available'),

                TextColumn::make('series.category.name')
                    ->label('Category')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('season')
                    ->label('Season #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('episode_num')
                    ->label('Ep #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('info.duration')
                    ->label('Duration')
                    ->badge()
                    ->color('primary')
                    ->icon('heroicon-m-clock')
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['duration'] ?? null;
                    }),

                TextColumn::make('info.rating')
                    ->label('Rating')
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-m-star')
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['rating'] ?? null;
                    }),

                TextColumn::make('info.release_date')
                    ->label('Release Date')
                    ->date()
                    ->color('gray')
                    ->prefix('Released: ')
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['release_date'] ?? null;
                    })
                    ->placeholder(''),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                Action::make('play')
                    ->tooltip('Play Episode')
                    ->action(function ($record, $livewire) {
                        $livewire->dispatch('openFloatingStream', $record->getFloatingPlayerAttributes());
                    })
                    ->icon('heroicon-s-play-circle')
                    ->button()
                    ->hiddenLabel(),
                ViewAction::make()
                    ->slideOver()
                    ->hiddenLabel()
                    ->icon('heroicon-m-information-circle')
                    ->button()
                    ->tooltip('Episode Details'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                // @TODO - add download? Would need to generate streamlink files and compress then download...

                // Enable/disable bulk options
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('enable')
                        ->label('Enable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected episodes enabled')
                                ->body('The selected episodes have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected channel(s) now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Actions\BulkAction::make('disable')
                        ->label('Disable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected episodes disabled')
                                ->body('The selected episodes have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected channel(s) now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                ]),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Episode Details')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('series.name')
                            ->label('Series'),
                        TextEntry::make('season.name')
                            ->label('Season'),
                        TextEntry::make('title')
                            ->label('Title')
                            ->columnSpanFull(),
                        TextEntry::make('episode_num')
                            ->label('Episode Number'),
                        TextEntry::make('info.release_date')
                            ->label('Release Date')
                            ->date()
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];

                                return $info['release_date'] ?? null;
                            }),
                    ]),

                Section::make('Episode Metadata')
                    ->collapsible()
                    ->columns(3)
                    ->schema([
                        ImageEntry::make('info.movie_image')
                            ->label('Episode Image')
                            ->size(200, 300)
                            ->columnSpan(1)
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];

                                return $info['movie_image'] ?? $info['cover_big'] ?? null;
                            }),

                        \Filament\Schemas\Components\Grid::make()
                            ->columns(2)
                            ->columnSpan(2)
                            ->schema([
                                TextEntry::make('info.duration')
                                    ->label('Duration')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];

                                        return $info['duration'] ?? null;
                                    }),
                                TextEntry::make('info.rating')
                                    ->label('Rating')
                                    ->badge()
                                    ->color('success')
                                    ->icon('heroicon-m-star')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];

                                        return $info['rating'] ?? null;
                                    }),
                                TextEntry::make('info.bitrate')
                                    ->label('Bitrate')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        $bitrate = $info['bitrate'] ?? null;

                                        return $bitrate ? "{$bitrate} kbps" : null;
                                    }),
                                TextEntry::make('info.season')
                                    ->label('Season (Metadata)')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];

                                        return $info['season'] ?? null;
                                    }),
                                TextEntry::make('info.tmdb_id')
                                    ->label('TMDB ID')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];

                                        return $info['tmdb_id'] ?? null;
                                    })
                                    ->url(function ($record) {
                                        $info = $record->info ?? [];
                                        $tmdbId = $info['tmdb_id'] ?? null;

                                        return $tmdbId ? "https://www.themoviedb.org/tv/episode/{$tmdbId}" : null;
                                    }, true),
                            ]),

                        TextEntry::make('info.plot')
                            ->label('Plot')
                            ->columnSpanFull()
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];

                                return $info['plot'] ?? 'No plot information available.';
                            }),

                        TextEntry::make('url')
                            ->label('Stream URL')
                            ->columnSpanFull()
                            ->copyable()
                            ->url(fn ($record) => $record->url)
                            ->openUrlInNewTab(),
                    ]),
            ]);
    }

    public function getTabs(): array
    {
        return [];
    }
}
