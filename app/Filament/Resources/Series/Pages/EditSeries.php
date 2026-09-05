<?php

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Resources\Series\SeriesResource;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SyncSeriesStrmFiles;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSeries extends EditRecord
{
    protected static string $resource = SeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label(__('View Series'))
                ->icon('heroicon-s-eye'),
            ActionGroup::make([
                Action::make('process')
                    ->label(__('Fetch Provider Metadata'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessM3uImportSeriesEpisodes(
                                playlistSeries: $record,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Series is being processed'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription(__('Process series now? This will fetch all episodes and seasons for this series.'))
                    ->modalSubmitActionLabel(__('Yes, process now')),
                Action::make('sync')
                    ->label(__('Sync Series .strm files'))
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncSeriesStrmFiles(
                                series: $record,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Series .strm files are being synced'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription(__('Sync series .strm files now? This will generate .strm files for this series at the path set for this series.'))
                    ->modalSubmitActionLabel(__('Yes, sync now')),

                Action::make('enable')
                    ->label(__('Enable all episodes'))
                    ->action(function ($record): void {
                        $record->episodes()->update([
                            'enabled' => true,
                        ]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title(__('Series episodes enabled'))
                            ->body(__('The series episodes have been enabled.'))
                            ->send();
                    })
                    ->color('success')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalDescription(__('Enable the series episodes now?'))
                    ->modalSubmitActionLabel(__('Yes, enable now')),
                Action::make('disable')
                    ->label(__('Disable all episodes'))
                    ->action(function ($record): void {
                        $record->episodes()->update([
                            'enabled' => false,
                        ]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title(__('Series episodes disabled'))
                            ->body(__('The series episodes have been disabled.'))
                            ->send();
                    })
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription(__('Disable the series episodes now?'))
                    ->modalSubmitActionLabel(__('Yes, disable now')),

                DeleteAction::make()
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription(__('Are you sure you want to delete this series? This will delete all episodes and seasons for this series. This action cannot be undone.'))
                    ->modalSubmitActionLabel(__('Yes, delete series')),
            ])->button(),
        ];
    }
}
