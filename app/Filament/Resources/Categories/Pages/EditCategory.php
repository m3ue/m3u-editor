<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Category;
use App\Models\CustomPlaylist;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('add')
                    ->label('Add to Custom Playlist')
                    ->schema([
                        Select::make('playlist')
                            ->required()
                            ->live()
                            ->label('Custom Playlist')
                            ->helperText('Select the custom playlist you would like to add the category series to.')
                            ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state) {
                                    $set('category', null);
                                }
                            })
                            ->searchable(),
                        Select::make('category')
                            ->label('Custom Category')
                            ->disabled(fn (Get $get) => ! $get('playlist'))
                            ->helperText(fn (Get $get) => ! $get('playlist') ? 'Select a custom playlist first.' : 'Select the category you would like to assign to the series to.')
                            ->options(function ($get) {
                                $customList = CustomPlaylist::find($get('playlist'));

                                return $customList ? $customList->categoryTags()->get()
                                    ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                    ->toArray() : [];
                            })
                            ->searchable(),
                    ])
                    ->action(function ($record, array $data): void {
                        $playlist = CustomPlaylist::findOrFail($data['playlist']);
                        $playlist->series()->syncWithoutDetaching($record->series()->pluck('id'));
                        if ($data['category']) {
                            $tags = $playlist->categoryTags()->get();
                            $tag = $playlist->categoryTags()->where('name->en', $data['category'])->first();
                            foreach ($record->series()->cursor() as $series) {
                                // Need to detach any existing tags from this playlist first
                                $series->detachTags($tags);
                                $series->attachTag($tag);
                            }
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series added to custom playlist')
                            ->body('The selected series have been added to the chosen custom playlist.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-play')
                    ->modalIcon('heroicon-o-play')
                    ->modalDescription('Add the selected series to the chosen custom playlist.')
                    ->modalSubmitActionLabel('Add now'),
                Action::make('move')
                    ->label('Move Series to Category')
                    ->schema([
                        Select::make('category')
                            ->required()
                            ->live()
                            ->label('Category')
                            ->helperText('Select the category you would like to move the series to.')
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
                            ->title('Series moved to category')
                            ->body('The series have been moved to the chosen category.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription('Move the series to another category.')
                    ->modalSubmitActionLabel('Move now'),
                Action::make('process')
                    ->label('Fetch Series Metadata')
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
                            ->title('Series are being processed')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Process series for this category now? Only enabled series will be processed. This will fetch all episodes and seasons for the category series. This may take a while depending on the number of series in the category.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Action::make('sync')
                    ->label('Sync Series .strm files')
                    ->action(function ($record) {
                        foreach ($record->enabled_series as $series) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new SyncSeriesStrmFiles(
                                    series: $series,
                                ));
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('.strm files are being synced for current category series. Only enabled series will be synced.')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription('Sync category series .strm files now? This will generate .strm files for the enabled series at the path set for the series.')
                    ->modalSubmitActionLabel('Yes, sync now'),
                Action::make('enable')
                    ->label('Enable category series')
                    ->action(function ($record): void {
                        $record->series()->update(['enabled' => true]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Current category series enabled')
                            ->body('The current category series have been enabled.')
                            ->send();
                    })
                    ->color('success')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalDescription('Enable the current category series now?')
                    ->modalSubmitActionLabel('Yes, enable now'),
                Action::make('disable')
                    ->label('Disable category series')
                    ->action(function ($record): void {
                        $record->series()->update(['enabled' => false]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Current category series disabled')
                            ->body('The current category series have been disabled.')
                            ->send();
                    })
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription('Disable the current category series now?')
                    ->modalSubmitActionLabel('Yes, disable now'),
            ])->button()->label('Actions'),
        ];
    }
}
