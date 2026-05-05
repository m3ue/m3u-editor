<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Category;
use App\Services\PlaylistService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                PlaylistService::getAddGroupsToPlaylistAction('add', 'series'),
                Action::make('move')
                    ->label(__('Move Series to Category'))
                    ->schema([
                        Select::make('category')
                            ->required()
                            ->live()
                            ->label(__('Category'))
                            ->helperText(__('Select the category you would like to move the series to.'))
                            ->options(fn (Get $get, $record) => Category::where(['user_id' => auth()->id(), 'playlist_id' => $record->playlist_id])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function ($record, array $data): void {
                        $category = Category::findOrFail($data['category']);
                        $record->series()->update([
                            'category_id' => $category->id,
                        ]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title(__('Series moved to category'))
                            ->body(__('The series have been moved to the chosen category.'))
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription(__('Move the series to another category.'))
                    ->modalSubmitActionLabel(__('Move now')),
                Action::make('process')
                    ->label(__('Fetch Series Metadata'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($record) {
                        foreach ($record->enabled_series as $series) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new ProcessM3uImportSeriesEpisodes(
                                    playlistSeries: $series,
                                ));
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Series are being processed'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription(__('Process series for this category now? Only enabled series will be processed. This will fetch all episodes and seasons for the category series. This may take a while depending on the number of series in the category.'))
                    ->modalSubmitActionLabel(__('Yes, process now')),
                Action::make('sync')
                    ->label(__('Sync Series .strm files'))
                    ->action(function ($record) {
                        $seriesIds = $record->enabled_series->pluck('id')->all();
                        if (empty($seriesIds)) {
                            return;
                        }
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncSeriesStrmFiles(
                                user_id: auth()->id(),
                                series_ids: $seriesIds,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('.strm files are being synced for current category series. Only enabled series will be synced.'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription(__('Sync category series .strm files now? This will generate .strm files for the enabled series at the path set for the series.'))
                    ->modalSubmitActionLabel(__('Yes, sync now')),
                Action::make('enable')
                    ->label(__('Enable category series'))
                    ->action(function ($record): void {
                        $record->series()->update(['enabled' => true]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title(__('Current category series enabled'))
                            ->body(__('The current category series have been enabled.'))
                            ->send();
                    })
                    ->color('success')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalDescription(__('Enable the current category series now?'))
                    ->modalSubmitActionLabel(__('Yes, enable now')),
                Action::make('disable')
                    ->label(__('Disable category series'))
                    ->action(function ($record): void {
                        $record->series()->update(['enabled' => false]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title(__('Current category series disabled'))
                            ->body(__('The current category series have been disabled.'))
                            ->send();
                    })
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription(__('Disable the current category series now?'))
                    ->modalSubmitActionLabel(__('Yes, disable now')),
            ])->button()->label(__('Actions')),
        ];
    }
}
