<?php

namespace App\Filament\Resources\EpgMaps\Pages;

use App\Enums\Status;
use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Jobs\BuildEpgMapCandidatesJob;
use App\Models\EpgMap;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ViewEpgMap extends ViewRecord
{
    protected static string $resource = EpgMapResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name ?? __('View EPG Map');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // Wrap everything in a polling Grid so the infolist re-renders
                // every 3 s while a candidate build is running, mirroring the
                // pattern used by SyncRunResource. When idle the poll stops.
                Grid::make()
                    ->columnSpanFull()
                    ->poll(fn ($record): ?string => $record->candidates_building ? '3s' : null)
                    ->schema([
                        Section::make(__('Building Candidates'))
                            ->compact()
                            ->schema([
                                View::make('infolists.components.progress'),
                            ])
                            ->visible(fn ($record): bool => (bool) $record->candidates_building),
                        Section::make(__('Last Mapping Run'))
                            ->collapsible()
                            ->compact()
                            ->persistCollapsed()
                            ->schema([
                                Grid::make(4)
                                    ->columnSpanFull()
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
                                TextEntry::make('errors')
                                    ->columnSpanFull()
                                    ->label(__('Errors'))
                                    ->placeholder(__('None'))
                                    ->color('danger')
                                    ->visible(fn ($record): bool => filled($record->errors)),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('buildCandidates')
                ->label(fn (EpgMap $record): string => $record->candidates_building ? __('Building…') : ($record->candidates()->exists() ? __('Rebuild Candidates') : __('Build Candidates')))
                ->icon(fn (EpgMap $record): string => $record->candidates_building ? 'heroicon-s-arrow-path' : ($record->candidates()->exists() ? 'heroicon-s-arrow-path' : 'heroicon-s-magnifying-glass'))
                ->color(fn (EpgMap $record): string => $record->candidates()->exists() ? 'warning' : 'primary')
                ->action(function (): void {
                    $record = $this->getRecord();
                    if ($record->candidates()->exists()) {
                        $record->candidates()->delete();
                    }
                    $record->update([
                        'candidates_building' => true,
                        'candidates_built_at' => now(),
                        'candidates_progress' => 0,
                    ]);
                    dispatch(new BuildEpgMapCandidatesJob($record->id));
                    Notification::make()
                        ->success()
                        ->title(__('Candidate review is being prepared'))
                        ->body(__('Channels are being scored in the background. This page will refresh automatically when the list is ready.'))
                        ->duration(5000)
                        ->send();
                })
                ->visible(fn (EpgMap $record): bool => $record->playlist_id !== null
                    && $record->user_id === auth()->id()
                    && $record->epg?->user_id === auth()->id())
                ->disabled(fn (EpgMap $record): bool => $record->candidates_building)
                ->requiresConfirmation()
                ->modalDescription(fn (EpgMap $record): string => $record->candidates()->exists()
                    ? __('Rebuild the candidate list for this EPG map? This will replace any previously built candidate list.')
                    : __('Score every unresolved channel against the selected EPG source and store the top candidates for review. This runs in the background and replaces any previously built candidate list.'))
                ->modalSubmitActionLabel(__('Yes, build candidate list')),
            Action::make('resetCandidates')
                ->label(__('Reset Candidates'))
                ->icon('heroicon-s-arrow-uturn-left')
                ->color('warning')
                ->action(function (): void {
                    $record = $this->getRecord();
                    $record->update(['candidates_building' => false]);
                    $record->candidates()->delete();
                    Notification::make()
                        ->success()
                        ->title(__('Candidate review reset'))
                        ->body(__('The candidate list has been cleared. You can rebuild it at any time.'))
                        ->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record->getKey()]));
                })
                ->visible(fn (EpgMap $record): bool => $record->candidates_building)
                ->requiresConfirmation()
                ->modalDescription(__('Clear the processing state and candidate list for this EPG map? This cannot be undone.'))
                ->modalSubmitActionLabel(__('Yes, clear and reset')),
        ];
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof EpgMap) {
            return null;
        }

        if ($record->candidates_building) {
            return __('Building candidate review — please wait, this page will refresh automatically.');
        }

        if ($record->candidates_built_at) {
            return __('Candidates last built :when.', ['when' => $record->candidates_built_at->diffForHumans()]);
        }

        return null;
    }
}
