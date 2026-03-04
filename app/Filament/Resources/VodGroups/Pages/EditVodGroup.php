<?php

namespace App\Filament\Resources\VodGroups\Pages;

use App\Facades\SortFacade;
use App\Filament\Resources\VodGroups\VodGroupResource;
use App\Jobs\ProcessVodChannels;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Group;
use App\Services\PlaylistService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;

class EditVodGroup extends EditRecord
{
    protected static string $resource = VodGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                PlaylistService::getAddToPlaylistAction('add', 'channel', fn ($record) => $record->channels())
                    ->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Group channels added to custom playlist')
                            ->body('The groups channels have been added to the chosen custom playlist.')
                            ->send();
                    }),
                Action::make('move')
                    ->label('Move to Group')
                    ->schema([
                        Select::make('group')
                            ->required()
                            ->live()
                            ->label('Group')
                            ->helperText('Select the group you would like to move the channels to.')
                            ->options(fn (Get $get, $record) => Group::where([
                                'type' => 'vod',
                                'user_id' => auth()->id(),
                                'playlist_id' => $record->playlist_id,
                            ])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function ($record, array $data): void {
                        $group = Group::findOrFail($data['group']);
                        $record->channels()->update([
                            'group' => $group->name,
                            'group_id' => $group->id,
                        ]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Channels moved to group')
                            ->body('The group channels have been moved to the chosen group.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription('Move the group channels to the another group.')
                    ->modalSubmitActionLabel('Move now'),

                Action::make('recount')
                    ->label('Recount Channels')
                    ->icon('heroicon-o-hashtag')
                    ->schema([
                        TextInput::make('start')
                            ->label('Start Number')
                            ->numeric()
                            ->default(1)
                            ->required(),
                    ])
                    ->action(function (Group $record, array $data): void {
                        $start = (int) $data['start'];
                        SortFacade::bulkRecountGroupChannels($record, $start);
                    })
                    ->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Channels Recounted')
                            ->body('The channels in this group have been recounted.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-hashtag')
                    ->modalDescription('Recount all channels in this group sequentially?'),
                Action::make('sort_alpha')
                    ->label('Sort Alpha')
                    ->icon('heroicon-o-bars-arrow-down')
                    ->schema([
                        Select::make('column')
                            ->label('Sort By')
                            ->options([
                                'title' => 'Title (or override if set)',
                                'name' => 'Name (or override if set)',
                                'stream_id' => 'ID (or override if set)',
                                'channel' => 'Channel No.',
                            ])
                            ->default('title')
                            ->required(),
                        Select::make('sort')
                            ->label('Sort Order')
                            ->options([
                                'ASC' => 'A to Z or 0 to 9',
                                'DESC' => 'Z to A or 9 to 0',
                            ])
                            ->default('ASC')
                            ->required(),
                    ])
                    ->action(function (Group $record, array $data): void {
                        $order = $data['sort'] ?? 'ASC';
                        $column = $data['column'] ?? 'title';
                        SortFacade::bulkSortGroupChannels($record, $order, $column);
                    })
                    ->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Channels Sorted')
                            ->body('The channels in this group have been sorted alphabetically.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-bars-arrow-down')
                    ->modalDescription('Sort all channels in this group alphabetically? This will update the sort order.'),

                Action::make('process_vod')
                    ->label('Fetch Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label('Overwrite Existing Metadata')
                            ->helperText('Overwrite existing metadata? If disabled, it will only fetch and process metadata if it does not already exist.')
                            ->default(false),
                    ])
                    ->action(function ($record, array $data) {
                        foreach ($record->enabled_channels as $channel) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new ProcessVodChannels(
                                    channel: $channel,
                                    force: $data['overwrite_existing'] ?? false,
                                ));
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Fetching VOD metadata for channel')
                            ->body('The VOD metadata fetching and processing has been started for the group channels. Only enabled channels will be processed. You will be notified when it is complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Fetch and process VOD metadata for the group channels.')
                    ->modalSubmitActionLabel('Yes, process now'),

                Action::make('sync_vod')
                    ->label('Sync VOD .strm file')
                    ->action(function ($record) {
                        foreach ($record->enabled_channels as $channel) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new SyncVodStrmFiles(
                                    channel: $channel,
                                ));
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('.strm files are being synced for the group channels. Only enabled channels will be synced.')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription('Sync group VOD channels .strm files now? This will generate .strm files for the group channels.')
                    ->modalSubmitActionLabel('Yes, sync now'),

                Action::make('enable')
                    ->label('Enable group channels')
                    ->action(function ($record): void {
                        $record->channels()->update([
                            'enabled' => true,
                        ]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Group channels enabled')
                            ->body('The group channels have been enabled.')
                            ->send();
                    })
                    ->color('success')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalDescription('Enable group channels now?')
                    ->modalSubmitActionLabel('Yes, enable now'),
                Action::make('disable')
                    ->label('Disable group channels')
                    ->action(function ($record): void {
                        $record->channels()->update([
                            'enabled' => false,
                        ]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Group channels disabled')
                            ->body('The groups channels have been disabled.')
                            ->send();
                    })
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription('Disable group channels now?')
                    ->modalSubmitActionLabel('Yes, disable now'),

                DeleteAction::make()
                    ->hidden(fn ($record) => ! $record->custom),
            ])->button()->label('Actions'),
        ];
    }
}
