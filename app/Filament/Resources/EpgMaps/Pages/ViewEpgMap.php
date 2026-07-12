<?php

namespace App\Filament\Resources\EpgMaps\Pages;

use App\Enums\Status;
use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Jobs\BuildEpgMapCandidatesJob;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewEpgMap extends ViewRecord
{
    protected static string $resource = EpgMapResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('buildCandidates')
                ->label(__('Build Candidates'))
                ->icon('heroicon-s-magnifying-glass')
                ->action(function (): void {
                    $record = $this->getRecord();
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new BuildEpgMapCandidatesJob($record->id));
                    Notification::make()
                        ->success()
                        ->title(__('Candidate review is being prepared'))
                        ->body(__('Channels are being scored in the background. Refresh this page in a few seconds.'))
                        ->duration(5000)
                        ->send();
                })
                ->visible(fn (): bool => $this->getRecord()->playlist_id !== null
                    && $this->getRecord()->user_id === auth()->id()
                    && $this->getRecord()->epg?->user_id === auth()->id())
                ->requiresConfirmation()
                ->modalDescription(__('Score every unresolved channel against the selected EPG source and store the top candidates for review. This runs in the background and replaces any previously built candidate list.'))
                ->modalSubmitActionLabel(__('Yes, build candidate list')),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Last Mapping Run'))
                    ->collapsible()
                    ->compact()
                    ->persistCollapsed()
                    ->schema([
                        Grid::make(2)
                            ->columnSpanFull()
                            ->schema([
                                Grid::make()
                                    ->columnSpan(1)
                                    ->schema([
                                        TextEntry::make('name')
                                            ->label(__('Map name')),
                                        TextEntry::make('status')
                                            ->badge()
                                            ->color(fn (Status $state): string => $state->getColor()),
                                        TextEntry::make('progress')
                                            ->label(__('Progress'))
                                            ->state(fn ($record): string => $record->status === Status::Processing || $record->status === Status::Pending
                                                ? __('In progress — :pct%', ['pct' => round((float) $record->progress)])
                                                : __('Complete')),
                                        TextEntry::make('mapped_at')
                                            ->label(__('Last ran'))
                                            ->since()
                                            ->placeholder(__('Never')),
                                    ]),
                                Grid::make()
                                    ->columnSpan(1)
                                    ->schema([
                                        TextEntry::make('epg.name')
                                            ->label(__('EPG source'))
                                            ->placeholder(__('—')),
                                        TextEntry::make('playlist.name')
                                            ->label(__('Playlist'))
                                            ->placeholder(__('Custom channel selection')),
                                        TextEntry::make('sync_time')
                                            ->label(__('Sync time'))
                                            ->state(fn ($record): string => $record->sync_time ? gmdate('H:i:s', (int) $record->sync_time) : '—'),
                                        TextEntry::make('errors')
                                            ->label(__('Errors'))
                                            ->placeholder(__('None'))
                                            ->color('danger')
                                            ->visible(fn ($record): bool => filled($record->errors)),
                                    ]),
                            ]),
                        Grid::make(4)
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('total_channel_count')
                                    ->label(__('Total Channels'))
                                    ->badge()
                                    ->tooltip(__('Total number of channels available for this mapping.')),
                                TextEntry::make('current_mapped_count')
                                    ->label(__('Currently Mapped'))
                                    ->badge()
                                    ->tooltip(__('Number of channels that were already mapped to an EPG entry.')),
                                TextEntry::make('channel_count')
                                    ->label(__('Search & Map'))
                                    ->badge()
                                    ->tooltip(__('Channels searched for a matching EPG entry in this run.')),
                                TextEntry::make('mapped_count')
                                    ->label(__('Newly Mapped'))
                                    ->badge()
                                    ->tooltip(__('Channels matched in this run. Zero is expected when Override is off and all channels are already mapped.')),
                            ]),
                        Grid::make(2)
                            ->columnSpanFull()
                            ->schema([
                                IconEntry::make('override')
                                    ->label(__('Override existing mappings'))
                                    ->boolean(),
                                IconEntry::make('recurring')
                                    ->label(__('Recurring on EPG sync'))
                                    ->boolean(),
                            ]),
                    ]),
            ]);
    }
}
