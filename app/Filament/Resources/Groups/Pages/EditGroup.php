<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Facades\SortFacade;
use App\Filament\Resources\Groups\GroupResource;
use App\Models\CustomPlaylist;
use App\Models\Group;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

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
                            ->helperText('Select the custom playlist you would like to add group channels to.')
                            ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state) {
                                    $set('category', null);
                                }
                            })
                            ->searchable(),
                        Select::make('category')
                            ->label('Custom Group')
                            ->disabled(fn (Get $get) => ! $get('playlist'))
                            ->helperText(fn (Get $get) => ! $get('playlist') ? 'Select a custom playlist first.' : 'Select the group you would like to assign to the channels to.')
                            ->options(function ($get) {
                                $customList = CustomPlaylist::find($get('playlist'));

                                return $customList ? $customList->groupTags()->get()
                                    ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                    ->toArray() : [];
                            })
                            ->searchable(),
                    ])
                    ->action(function ($record, array $data): void {
                        $playlist = CustomPlaylist::findOrFail($data['playlist']);
                        $playlist->channels()->syncWithoutDetaching($record->channels()->pluck('id'));
                        if ($data['category']) {
                            $tags = $playlist->groupTags()->get();
                            $tag = $playlist->groupTags()->where('name->en', $data['category'])->first();
                            foreach ($record->channels()->cursor() as $channel) {
                                // Need to detach any existing tags from this playlist first
                                $channel->detachTags($tags);
                                $channel->attachTag($tag);
                            }
                        }
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Group channels added to custom playlist')
                            ->body('The groups channels have been added to the chosen custom playlist.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-play')
                    ->modalIcon('heroicon-o-play')
                    ->modalDescription('Add the group channels to the chosen custom playlist.')
                    ->modalSubmitActionLabel('Add now'),
                Action::make('move')
                    ->label('Move to Group')
                    ->schema([
                        Select::make('group')
                            ->required()
                            ->live()
                            ->label('Group')
                            ->helperText('Select the group you would like to move the channels to.')
                            ->options(fn (Get $get, $record) => Group::where([
                                'type' => 'live',
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
                    ->modalDescription('Recount all channels in this group sequentially? Channel numbers will be assigned based on the current sort order.'),
                Action::make('sort_alpha')
                    ->label('Sort Alpha')
                    ->icon('heroicon-o-bars-arrow-down')
                    ->schema([
                        Select::make('sort')
                            ->label('Sort Order')
                            ->options([
                                'ASC' => 'A to Z',
                                'DESC' => 'Z to A',
                            ])
                            ->default('ASC')
                            ->required(),
                    ])
                    ->action(function (Group $record, array $data): void {
                        // Sort by title_custom (if present) then title, matching the UI column sort
                        $order = $data['sort'] ?? 'ASC';
                        SortFacade::bulkSortGroupChannels($record, $order);
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
