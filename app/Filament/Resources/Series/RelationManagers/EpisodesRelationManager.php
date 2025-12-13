<?php

namespace App\Filament\Resources\Series\RelationManagers;

use App\Facades\LogoFacade;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Actions\ViewAction;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
            ->recordAction(null)
            ->defaultGroup('season')
            ->defaultSort('episode_num', 'asc')
            ->contentGrid([
                'md' => 2,
                'lg' => 3,
                'xl' => 4,
            ])
            ->recordUrl(null) // Disable default record URL behavior
            ->paginated([12, 24, 48, 100])
            ->defaultPaginationPageOption(12)
            ->columns([
                ToggleColumn::make('enabled')
                    ->label('Enabled'),
                Stack::make([
                    ImageColumn::make('info.movie_image')
                        ->label('')
                        ->height(200)
                        ->width('full')
                        ->extraImgAttributes(['class' => 'episode-placeholder rounded-t-lg object-cover w-full h-48'])
                        ->checkFileExistence(false)
                        ->getStateUsing(fn($record) => LogoFacade::getEpisodeLogoUrl($record)),

                    Stack::make([
                        TextColumn::make('title')
                            ->weight('semibold')
                            ->size('sm')
                            ->limit(50)
                            ->tooltip(fn($record) => $record->title),

                        TextColumn::make('episode_info')
                            ->label('')
                            ->size('xs')
                            ->color('gray')
                            ->getStateUsing(function ($record) {
                                $seasonName = $record->season ? "Season {$record->season}" : 'Unknown Season';
                                $episodeNum = $record->episode_num ? "Episode {$record->episode_num}" : '';
                                return trim("{$seasonName} {$episodeNum}");
                            }),

                        TextColumn::make('info.plot')
                            ->label('')
                            ->limit(100)
                            ->size('xs')
                            ->color('gray')
                            ->tooltip(fn ($record) => $record->plot ?? $record->info['plot'] ?? null)
                            ->getStateUsing(function ($record) {
                                // Check the dedicated plot column first, then fall back to info.plot
                                if (!empty($record->plot)) {
                                    return $record->plot;
                                }
                                $info = $record->info ?? [];
                                return $info['plot'] ?? null;
                            })
                            ->placeholder('No description available'),

                        Grid::make(2)
                            ->schema([
                                TextColumn::make('info.duration')
                                    ->label('Duration')
                                    ->badge()
                                    ->size('xs')
                                    ->color('primary')
                                    ->icon('heroicon-m-clock')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        return $info['duration'] ?? null;
                                    }),

                                TextColumn::make('info.rating')
                                    ->label('Rating')
                                    ->badge()
                                    ->size('xs')
                                    ->color('success')
                                    ->icon('heroicon-m-star')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        return $info['rating'] ?? null;
                                    }),
                            ]),

                        TextColumn::make('info.release_date')
                            ->label('')
                            ->date()
                            ->size('xs')
                            ->color('gray')
                            ->prefix('Released: ')
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];
                                return $info['release_date'] ?? null;
                            })
                            ->placeholder(''),
                    ])->space(2)->extraAttributes(['class' => 'p-4']),
                ])->space(0)->extraAttributes(['class' => 'bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden']),
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
                    ->hiddenLabel()
                    ->size('sm'),
                ViewAction::make()
                    ->slideOver()
                    ->hiddenLabel()
                    ->icon('heroicon-m-information-circle')
                    ->button()
                    ->size('xs')
                    ->tooltip('Episode Details'),
            ])
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
                            ->url(fn($record) => $record->url)
                            ->openUrlInNewTab(),
                    ]),
            ]);
    }

    public function getTabs(): array
    {
        return [];
    }
}
