<?php

namespace App\Filament\Resources\EpgResource\Pages;

use App\Filament\Infolists\Components\EpgViewer;
use App\Filament\Resources\EpgResource;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewEpg extends ViewRecord
{
    protected static string $resource = EpgResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh EPG')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $record = $this->getRecord();
                    $record->update([
                        'status' => \App\Enums\Status::Processing,
                        'progress' => 0,
                    ]);
                    \App\Jobs\ProcessEpgImport::dispatch($record, force: true);
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('EPG is processing')
                        ->body('EPG is being processed in the background. The view will update when complete.')
                        ->duration(5000)
                        ->send();
                })
                ->disabled(fn() => $this->getRecord()->status === \App\Enums\Status::Processing)
                ->requiresConfirmation()
                ->modalDescription('Process EPG now? This will reload the EPG data from the source.')
                ->modalSubmitActionLabel('Yes, refresh now'),

            Actions\Action::make('download')
                ->label('Download EPG')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn() => route('epg.file', ['uuid' => $this->getRecord()->uuid]))
                ->openUrlInNewTab(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('EPG Information')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn($state) => $state?->getColor()),
                        TextEntry::make('synced')
                            ->label('Last Synced')
                            ->since()
                            ->placeholder('Never'),
                        TextEntry::make('channels_count')
                            ->label('Total Channels')
                            ->formatStateUsing(fn($record) => $record->channels()->count()),
                    ])
                    ->columns(2),

                Section::make('EPG Guide')
                    ->schema([
                        EpgViewer::make(),
                    ])
                    ->collapsible(false),
            ]);
    }
}
