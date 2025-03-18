<?php

namespace App\Filament\Resources;

use App\Enums\ChannelLogoType;
use App\Filament\Resources\ChannelResource\Pages;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'title_custom', 'name', 'name_custom', 'url', 'stream_id', 'stream_id_custom'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return self::setupTable($table);
    }

    public static function setupTable(Table $table, $relationId = null): Table
    {
//        $livewire = $table->getLivewire();
        return $table->persistFiltersInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['epgChannel', 'playlist']);
            })
            ->deferLoading()
//            ->contentGrid(fn() => $livewire->isListLayout()
//                ? null
//                : [
//                    'md' => 2,
//                    'lg' => 3,
//                    'xl' => 4,
//                    '2xl' => 5,
//                ])
//            ->reorderable('sort', $relationId !== null)
//            ->reorderRecordsTriggerAction(
//                fn(Tables\Actions\Action $action, bool $isReordering) => $action
//                    ->button()
//                    ->tooltip('')
//                    ->label($isReordering ? 'Disable reordering' : 'Enable reordering'),
//            )
            ->paginated([10, 25, 50, 100])
            ->paginatedWhileReordering()
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label('Icon')
                    ->checkFileExistence(false)
                    ->height(40)
                    ->width('auto')
                    ->getStateUsing(function ($record) {
                        if ($record->logo_type === ChannelLogoType::Channel) {
                            return $record->logo;
                        }
                        return $record->epgChannel?->icon ?? $record->logo;
                    })
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('sort')
                    ->label('Sort Order')
                    ->rules(['min:0'])
                    ->type('number')
                    ->placeholder('Sort Order')
                    ->sortable()
                    ->tooltip(fn($record) => $record->playlist->auto_sort ? 'Playlist auto-sort enabled; disable to change' : 'Channel sort order')
                    ->disabled(fn($record) => $record->playlist->auto_sort)
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('stream_id_custom')
                    ->label('ID')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip('Channel/stream ID')
                    ->placeholder(fn($record) => $record->stream_id)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('title_custom')
                    ->label('Title')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip('Channel title')
                    ->placeholder(fn($record) => $record->title)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('name_custom')
                    ->label('Name')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip('Channel name')
                    ->placeholder(fn($record) => $record->name)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip('Toggle channel status')
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('channel')
                    ->rules(['numeric', 'min:0'])
                    ->type('number')
                    ->placeholder('Channel No.')
                    ->tooltip('Channel number')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('url_custom')
                    ->label('URL')
                    ->rules(['url'])
                    ->type('url')
                    ->tooltip('Channel url')
                    ->placeholder(fn($record) => $record->url)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('shift')
                    ->rules(['numeric', 'min:0'])
                    ->type('number')
                    ->placeholder('Shift')
                    ->tooltip('Shift')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group')
                    ->hidden(fn() => $relationId)
                    ->toggleable()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('epgChannel.name')
                    ->label('EPG Channel')
                    ->toggleable()
                    ->searchable()
                    ->limit(40)
                    ->sortable(),
                Tables\Columns\SelectColumn::make('logo_type')
                    ->label('Preferred Icon')
                    ->options([
                        'channel' => 'Channel',
                        'epg' => 'EPG',
                    ])
                    ->sortable()
                    ->tooltip('Preferred icon source')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('lang')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('playlist.name')
                    ->hidden(fn() => $relationId)
                    ->numeric()
                    ->toggleable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stream_id')
                    ->label('Default ID')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('title')
                    ->label('Default Title')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')
                    ->label('Default Name')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('url')
                    ->label('Default URL')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('playlist')
                    ->relationship('playlist', 'name')
                    ->hidden(fn() => $relationId)
                    ->multiple()
                    ->preload()
                    ->searchable(),
                Tables\Filters\SelectFilter::make('group')
                    ->relationship('group', 'name')
                    ->hidden(fn() => $relationId)
                    ->multiple()
                    ->preload()
                    ->searchable(),
                Tables\Filters\Filter::make('enabled')
                    ->label('Channel is enabled')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('enabled', true);
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('add')
                        ->label('Add to custom playlist')
                        ->form([
                            Forms\Components\Select::make('playlist')
                                ->required()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected channel(s) to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            $playlist->channels()->syncWithoutDetaching($records->pluck('id'));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channels added to custom playlist')
                                ->body('The selected channels have been added to the chosen custom playlist.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-play')
                        ->modalIcon('heroicon-o-play')
                        ->modalDescription('Add the selected channel(s) to the chosen custom playlist.')
                        ->modalSubmitActionLabel('Add now'),
                    Tables\Actions\BulkAction::make('move')
                        ->label('Move to group')
                        ->form([
                            Forms\Components\Select::make('playlist')
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Forms\Set $set) {
                                    $set('group', null);
                                })
                                ->label('Playlist')
                                ->helperText('Select a playlist - only channels in the selected playlist will be moved. Any channels selected from another playlist will be ignored.')
                                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                            Forms\Components\Select::make('group')
                                ->required()
                                ->live()
                                ->label('Group')
                                ->helperText(fn(Get $get) => $get('playlist') === null ? 'Select a playlist first...' : 'Select the group you would like to move the items to.')
                                ->options(fn(Get $get) => Group::where(['user_id' => auth()->id(), 'playlist_id' => $get('playlist')])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable()
                                ->disabled(fn(Get $get) => $get('playlist') === null),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $filtered = $records->where('playlist_id', $data['playlist']);
                            $group = Group::findOrFail($data['group']);
                            foreach ($filtered as $record) {
                                $record->update([
                                    'group' => $group->name,
                                    'group_id' => $group->id,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channels moved to group')
                                ->body('The selected channels have been moved to the chosen group.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription('Move the selected channel(s) to the chosen group.')
                        ->modalSubmitActionLabel('Move now'),
                    Tables\Actions\BulkAction::make('map')
                        ->label('Map EPG to selected')
                        ->form([
                            Forms\Components\Select::make('epg')
                                ->required()
                                ->label('EPG')
                                ->helperText('Select the EPG you would like to map from.')
                                ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                            Forms\Components\Toggle::make('overwrite')
                                ->label('Overwrite')
                                ->helperText('Overwrite channels with existing mappings?')
                                ->default(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\MapPlaylistChannelsToEpg(
                                    epg: (int)$data['epg'],
                                    channels: $records->pluck('id')->toArray(),
                                    force: $data['overwrite'],
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('EPG to Channel mapping')
                                ->body('Mapping started, you will be notified when the process is complete.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-link')
                        ->modalIcon('heroicon-o-link')
                        ->modalDescription('Map the selected EPG to the selected channel(s).')
                        ->modalSubmitActionLabel('Map now'),
                    Tables\Actions\BulkAction::make('preferred_logo')
                        ->label('Update preferred icon')
                        ->form([
                            Forms\Components\Select::make('logo_type')
                                ->label('Preferred Icon')
                                ->helperText('Prefer logo from channel or EPG.')
                                ->options([
                                    'channel' => 'Channel',
                                    'epg' => 'EPG',
                                ])
                                ->searchable(),

                        ])
                        ->action(function (Collection $records, array $data): void {
                            Channel::whereIn('id', $records->pluck('id')->toArray())
                                ->update([
                                    'logo_type' => $data['logo_type'],
                                ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Preferred icon updated')
                                ->body('The preferred icon has been updated.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-photo')
                        ->modalIcon('heroicon-o-photo')
                        ->modalDescription('Update the preferred icon for the selected channel(s).')
                        ->modalSubmitActionLabel('Update now'),
                    Tables\Actions\BulkAction::make('enable')
                        ->label('Enable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected channels enabled')
                                ->body('The selected channels have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected channel(s) now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Tables\Actions\BulkAction::make('disable')
                        ->label('Disable selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected channels disabled')
                                ->body('The selected channels have been disabled.')
                                ->send();
                        })
                        ->color('danger')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected channel(s) now?')
                        ->modalSubmitActionLabel('Yes, disable now')
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChannels::route('/'),
            //'create' => Pages\CreateChannel::route('/create'),
            //'edit' => Pages\EditChannel::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            // Customizable channel fields
            Forms\Components\Toggle::make('enabled')
                ->columnSpan('full')
                ->helperText('Toggle channel status')
                ->inline(false)
                ->required(),
            Forms\Components\TextInput::make('logo')
                ->label('Icon')
                ->columnSpan(1)
                ->prefixIcon('heroicon-m-globe-alt')
                ->url(),
            Forms\Components\TextInput::make('stream_id_custom')
                ->label('ID')
                ->columnSpan(1)
                ->placeholder(fn(Get $get) => $get('stream_id'))
                ->helperText("Leave empty to use playlist default value.")
                ->rules(['min:1', 'max:255']),
            Forms\Components\TextInput::make('title_custom')
                ->label('Title')
                ->placeholder(fn(Get $get) => $get('title'))
                ->helperText("Leave empty to use playlist default value.")
                ->columnSpan(1)
                ->rules(['min:1', 'max:255']),
            Forms\Components\TextInput::make('name_custom')
                ->label('Name')
                ->placeholder(fn(Get $get) => $get('name'))
                ->helperText("Leave empty to use playlist default value.")
                ->columnSpan(1)
                ->rules(['min:1', 'max:255']),
            Forms\Components\TextInput::make('channel')
                ->columnSpan(1)
                ->rules(['numeric', 'min:0']),
            Forms\Components\TextInput::make('shift')
                ->columnSpan(1)
                ->rules(['numeric', 'min:0']),
            Forms\Components\TextInput::make('url_custom')
                ->label('URL')
                ->columnSpan('full')
                ->prefixIcon('heroicon-m-globe-alt')
                ->placeholder(fn(Get $get) => $get('url'))
                ->helperText("Leave empty to use playlist default value.")
                ->rules(['min:1', 'max:255'])
                ->suffixAction(
                    Forms\Components\Actions\Action::make('copy')
                        ->icon('heroicon-s-clipboard-document-check')
                        ->action(function (Get $get, $livewire, $state) {
                            $url = $state ?? $get('url');
                            $livewire->js(
                                'window.navigator.clipboard.writeText("' . $url . '");
                                $tooltip("' . __('Copied to clipboard') . '", { timeout: 1500 });'
                            );
                        })
                )
                ->type('url'),
            Forms\Components\Select::make('epg_channel_id')
                ->label('EPG Channel')
                ->helperText('Select an associated EPG channel for this channel.')
                ->relationship('epgChannel', 'name')
                ->getOptionLabelFromRecordUsing(fn($record) => "$record->name [{$record->epg->name}]")
                ->searchable()
                ->columnSpan(1),
            Forms\Components\Select::make('logo_type')
                ->label('Preferred Icon')
                ->helperText('Prefer icon from channel or EPG.')
                ->options([
                    'channel' => 'Channel',
                    'epg' => 'EPG',
                ])
                ->searchable()
                ->columnSpan(1),
        ];
    }
}
