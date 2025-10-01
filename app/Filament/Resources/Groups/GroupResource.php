<?php

namespace App\Filament\Resources\Groups;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Actions\DeleteAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use App\Filament\Resources\Groups\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\Groups\RelationManagers\VodRelationManager;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\Pages\ViewGroup;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\TextInput;
use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Resources\GroupResource\RelationManagers;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'name_internal'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static string | \UnitEnum | null $navigationGroup = 'Channels & VOD';

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('live_channels')
                    ->withCount('enabled_live_channels')
                    ->withCount('vod_channels')
                    ->withCount('enabled_vod_channels');
            })
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->reorderRecordsTriggerAction(function ($action) {
                return $action->button()->label('Sort');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->columns([
                TextInputColumn::make('name')
                    ->label('Name')
                    ->rules(['min:0', 'max:255'])
                    ->placeholder(fn($record) => $record->name_internal)
                    ->searchable()
                    ->toggleable(),
                TextInputColumn::make('sort_order')
                    ->label('Sort Order')
                    ->rules(['min:0'])
                    ->type('number')
                    ->placeholder('Sort Order')
                    ->sortable()
                    ->tooltip(fn($record) => $record->playlist->auto_sort ? 'Playlist auto-sort enabled; any changes will be overwritten on next sync' : 'Group sort order')
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->label('Auto Enable')
                    ->toggleable()
                    ->tooltip('Auto enable newly added group channels')
                    ->sortable(),
                TextColumn::make('name_internal')
                    ->label('Default name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('live_channels_count')
                    ->label('Live Channels')
                    ->description(fn(Group $record): string => "Enabled: {$record->enabled_live_channels_count}")
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('vod_channels_count')
                    ->label('VOD Channels')
                    ->description(fn(Group $record): string => "Enabled: {$record->enabled_vod_channels_count}")
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('playlist.name')
                    ->numeric()
                    ->toggleable()
                    ->sortable(),
                IconColumn::make('custom')
                    ->label('Custom')
                    ->icon(fn(string $state): string => match ($state) {
                        '1' => 'heroicon-o-check-circle',
                        '0' => 'heroicon-o-minus-circle',
                        '' => 'heroicon-o-minus-circle',
                    })->color(fn(string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'danger',
                        '' => 'danger',
                    })->toggleable()->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // SelectFilter::make('playlist')
                //     ->relationship('playlist', 'name')
                //     ->multiple()
                //     ->preload()
                //     ->searchable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    Action::make('add')
                        ->label('Add to Custom Playlist')
                        ->schema([
                            Select::make('playlist')
                                ->required()
                                ->live()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected channel(s) to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->afterStateUpdated(function (Set $set, $state) {
                                    if ($state) {
                                        $set('category', null);
                                    }
                                })
                                ->searchable(),
                            Select::make('category')
                                ->label('Custom Group')
                                ->disabled(fn(Get $get) => !$get('playlist'))
                                ->helperText(fn(Get $get) => !$get('playlist') ? 'Select a custom playlist first.' : 'Select the group you would like to assign to the selected channel(s) to.')
                                ->options(function ($get) {
                                    $customList = CustomPlaylist::find($get('playlist'));
                                    return $customList ? $customList->groupTags()->get()
                                        ->mapWithKeys(fn($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
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
                        })->after(function () {
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
                        ->label('Move Channels to Group')
                        ->schema([
                            Select::make('group')
                                ->required()
                                ->live()
                                ->label('Group')
                                ->helperText('Select the group you would like to move the channels to.')
                                ->options(fn(Get $get, $record) => Group::where(['user_id' => auth()->id(), 'playlist_id' => $record->playlist_id])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function ($record, array $data): void {
                            $group = Group::findOrFail($data['group']);
                            $record->channels()->update([
                                'group' => $group->name,
                                'group_id' => $group->id,
                            ]);
                        })->after(function () {
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

                    Action::make('enable')
                        ->label('Enable group channels')
                        ->action(function ($record): void {
                            $record->channels()->update([
                                'enabled' => true,
                            ]);
                        })->after(function () {
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
                        })->after(function () {
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
                        ->hidden(fn($record) => !$record->custom),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('add')
                        ->label('Add to Custom Playlist')
                        ->schema([
                            Select::make('playlist')
                                ->required()
                                ->live()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected group channel(s) to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->afterStateUpdated(function (Set $set, $state) {
                                    if ($state) {
                                        $set('category', null);
                                    }
                                })
                                ->searchable(),
                            Select::make('category')
                                ->label('Custom Group')
                                ->disabled(fn(Get $get) => !$get('playlist'))
                                ->helperText(fn(Get $get) => !$get('playlist') ? 'Select a custom playlist first.' : 'Select the group you would like to assign to the selected channel(s) to.')
                                ->options(function ($get) {
                                    $customList = CustomPlaylist::find($get('playlist'));
                                    return $customList ? $customList->groupTags()->get()
                                        ->mapWithKeys(fn($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                        ->toArray() : [];
                                })
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            $tags = $playlist->groupTags()->get();
                            $tag = $data['category'] ? $playlist->groupTags()->where('name->en', $data['category'])->first() : null;
                            foreach ($records as $record) {
                                // Sync the channels to the custom playlist
                                // This will add the channels to the playlist without detaching existing ones
                                // Prevents duplicates in the playlist
                                $playlist->channels()->syncWithoutDetaching($record->channels()->pluck('id'));
                                if ($data['category']) {
                                    foreach ($record->channels()->cursor() as $channel) {
                                        // Need to detach any existing tags from this playlist first
                                        $channel->detachTags($tags);
                                        $channel->attachTag($tag);
                                    }
                                }
                            }
                        })->after(function () {
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
                    BulkAction::make('move')
                        ->label('Move Channels to Group')
                        ->schema([
                            Select::make('group')
                                ->required()
                                ->live()
                                ->label('Group')
                                ->helperText('Select the group you would like to move the channels to.')
                                ->options(
                                    fn() => Group::query()
                                        ->with(['playlist'])
                                        ->where(['user_id' => auth()->id()])
                                        ->get(['name', 'id', 'playlist_id'])
                                        ->transform(fn($group) => [
                                            'id' => $group->id,
                                            'name' => $group->name . ' (' . $group->playlist->name . ')',
                                        ])->pluck('name', 'id')
                                )->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $group = Group::findOrFail($data['group']);
                            foreach ($records as $record) {
                                // Update the channels to the new group
                                // This will change the group and group_id for the channels in the database
                                // to reflect the new group
                                if ($group->playlist_id !== $record->playlist_id) {
                                    Notification::make()
                                        ->warning()
                                        ->title('Warning')
                                        ->body("Cannot move \"{$group->name}\" to \"{$record->name}\" as they belong to different playlists.")
                                        ->persistent()
                                        ->send();
                                    continue;
                                }
                                $record->channels()->update([
                                    'group' => $group->name,
                                    'group_id' => $group->id,
                                ]);
                            }
                        })->after(function () {
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
                    BulkAction::make('enable')
                        ->label('Enable group channels')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->channels()->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected group channels enabled')
                                ->body('The selected group channels have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected group(s) channels now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    BulkAction::make('disable')
                        ->label('Disable group channels')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->channels()->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected group channels disabled')
                                ->body('The selected groups channels have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected group(s) channels now?')
                        ->modalSubmitActionLabel('Yes, disable now')
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChannelsRelationManager::class,
            VodRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            // 'create' => Pages\CreateGroup::route('/create'),
            'view' => ViewGroup::route('/{record}'),
            // 'edit' => Pages\EditGroup::route('/{record}/edit'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        // return parent::infolist($infolist);
        return $schema
            ->components([
                Section::make('Group Details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->badge(),
                        TextEntry::make('playlist.name')
                            ->label('Playlist')
                            //->badge(),
                            ->url(fn($record) => PlaylistResource::getUrl('edit', ['record' => $record->playlist_id])),
                    ])
            ]);
    }

    public static function getForm(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Select::make('playlist_id')
                ->required()
                ->label('Playlist')
                ->relationship(name: 'playlist', titleAttribute: 'name')
                ->helperText('Select the playlist you would like to add the group to.')
                ->preload()
                ->hiddenOn(['edit'])
                ->searchable(),
            TextInput::make('sort_order')
                ->label('Sort Order')
                ->numeric()
                ->default(9999)
                ->helperText('Enter a number to define the sort order (e.g., 1, 2, 3). Lower numbers appear first.')
                ->rules(['integer', 'min:0']),
        ];
    }
}
