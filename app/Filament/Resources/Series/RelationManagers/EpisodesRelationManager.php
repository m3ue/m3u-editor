<?php

namespace App\Filament\Resources\Series\RelationManagers;

use App\Filament\Tables\ProbeStatusColumn;
use App\Jobs\ProbeVodStreamsChunk;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
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
                    ->label(__('Cover'))
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
                    ->label(__('Enabled')),

                TextColumn::make('info.plot')
                    ->label(__('Plot'))
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
                    ->placeholder(__('No description available')),

                TextColumn::make('series.category.name')
                    ->label(__('Category'))
                    ->searchable()
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('season')
                    ->label(__('Season #'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('episode_num')
                    ->label(__('Ep #'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('info.duration')
                    ->label(__('Duration'))
                    ->badge()
                    ->color('primary')
                    ->icon('heroicon-m-clock')
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['duration'] ?? null;
                    }),

                TextColumn::make('info.rating')
                    ->label(__('Rating'))
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-m-star')
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['rating'] ?? null;
                    }),

                TextColumn::make('info.release_date')
                    ->label(__('Release Date'))
                    ->date()
                    ->color('gray')
                    ->prefix('Released: ')
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['release_date'] ?? null;
                    })
                    ->placeholder(''),

                ToggleColumn::make('probe_enabled')
                    ->label(__('Probe Enabled'))
                    ->toggleable()
                    ->sortable(),

                ProbeStatusColumn::make(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                Action::make('play')
                    ->tooltip(__('Play Episode'))
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
                    ->tooltip(__('Episode Details')),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                // @TODO - add download? Would need to generate streamlink files and compress then download...

                // Enable/disable bulk options
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('enable')
                        ->label(__('Enable selected'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected episodes enabled'))
                                ->body(__('The selected episodes have been enabled.'))
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription(__('Enable the selected channel(s) now?'))
                        ->modalSubmitActionLabel(__('Yes, enable now')),
                    Actions\BulkAction::make('disable')
                        ->label(__('Disable selected'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected episodes disabled'))
                                ->body(__('The selected episodes have been disabled.'))
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription(__('Disable the selected channel(s) now?'))
                        ->modalSubmitActionLabel(__('Yes, disable now')),
                    Actions\BulkAction::make('enable-probing')
                        ->label(__('Enable Probing'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update(['probe_enabled' => true]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Stream probing enabled'))
                                ->body(__('Stream probing has been enabled for the selected episodes.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-signal')
                        ->modalIcon('heroicon-o-signal')
                        ->modalDescription(__('Enable stream probing for the selected episodes. They will be included in stream probing jobs.'))
                        ->modalSubmitActionLabel(__('Enable now')),
                    Actions\BulkAction::make('disable-probing')
                        ->label(__('Disable Probing'))
                        ->color('warning')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update(['probe_enabled' => false]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Stream probing disabled'))
                                ->body(__('Stream probing has been disabled for the selected episodes.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-signal-slash')
                        ->modalIcon('heroicon-o-signal-slash')
                        ->modalDescription(__('Disable stream probing for the selected episodes. They will be excluded from stream probing jobs.'))
                        ->modalSubmitActionLabel(__('Disable now')),
                    Actions\BulkAction::make('probe-streams')
                        ->label(__('Probe Streams'))
                        ->action(function (Collection $records): void {
                            $ids = $records->pluck('id')->all();
                            $total = count($ids);
                            $chunks = array_chunk($ids, 50);
                            $last = count($chunks) - 1;
                            foreach ($chunks as $i => $chunk) {
                                dispatch(new ProbeVodStreamsChunk(
                                    episodeIds: $chunk,
                                    probeTimeout: 15,
                                    notifyUserId: $i === $last ? auth()->id() : null,
                                    notifyLabel: $i === $last ? __('Episode stream probing') : null,
                                    notifyTotal: $i === $last ? $total : null,
                                ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Stream probing started'))
                                ->body(__('Stream probing is running in the background. You will be notified once the process is complete.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-signal')
                        ->modalIcon('heroicon-o-signal')
                        ->modalDescription(__('Probe the selected episodes with ffprobe to collect stream metadata (codec, resolution, bitrate, HDR). This data enables Trash Guide naming with stream-stat-based detection.'))
                        ->modalSubmitActionLabel(__('Start probing')),
                ]),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Episode Details'))
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('series.name')
                            ->label(__('Series')),
                        TextEntry::make('season.name')
                            ->label(__('Season')),
                        TextEntry::make('title')
                            ->label(__('Title'))
                            ->columnSpanFull(),
                        TextEntry::make('episode_num')
                            ->label(__('Episode Number')),
                        TextEntry::make('info.release_date')
                            ->label(__('Release Date'))
                            ->date()
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];

                                return $info['release_date'] ?? null;
                            }),
                    ]),

                Section::make(__('Episode Metadata'))
                    ->collapsible()
                    ->columns(3)
                    ->schema([
                        ImageEntry::make('info.movie_image')
                            ->label(__('Episode Image'))
                            ->size(200, 300)
                            ->columnSpan(1)
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];

                                return $info['movie_image'] ?? $info['cover_big'] ?? null;
                            }),

                        Grid::make()
                            ->columns(2)
                            ->columnSpan(2)
                            ->schema([
                                TextEntry::make('info.duration')
                                    ->label(__('Duration'))
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];

                                        return $info['duration'] ?? null;
                                    }),
                                TextEntry::make('info.rating')
                                    ->label(__('Rating'))
                                    ->badge()
                                    ->color('success')
                                    ->icon('heroicon-m-star')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];

                                        return $info['rating'] ?? null;
                                    }),
                                TextEntry::make('info.bitrate')
                                    ->label(__('Bitrate'))
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        $bitrate = $info['bitrate'] ?? null;

                                        return $bitrate ? "{$bitrate} kbps" : null;
                                    }),
                                TextEntry::make('info.season')
                                    ->label(__('Season (Metadata)'))
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];

                                        return $info['season'] ?? null;
                                    }),
                                TextEntry::make('info.tmdb_id')
                                    ->label(__('TMDB ID'))
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
                            ->label(__('Plot'))
                            ->columnSpanFull()
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];

                                return $info['plot'] ?? 'No plot information available.';
                            }),

                        TextEntry::make('url')
                            ->label(__('Stream URL'))
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
