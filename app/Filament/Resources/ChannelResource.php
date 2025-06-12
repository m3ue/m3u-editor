<?php

namespace App\Filament\Resources;

use App\Enums\ChannelLogoType;
use App\Facades\ProxyFacade;
use App\Filament\Resources\ChannelResource\Pages;
use App\Filament\Resources\ChannelResource\RelationManagers;
use App\Infolists\Components\VideoPreview;
use App\Livewire\ChannelStreamStats;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

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
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['epgChannel', 'playlist'])
                    ->withCount(['failovers']);
            })
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\ImageColumn::make('logo')
                    ->label('Logo')
                    ->checkFileExistence(false)
                    ->height(30)
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
                    ->tooltip(fn($record) => !$record->is_custom && $record->playlist?->auto_sort ? 'Playlist auto-sort enabled; disable to change' : 'Channel sort order')
                    ->disabled(fn($record) => !$record->is_custom && $record->playlist?->auto_sort)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('failovers_count')
                    ->label('Failovers')
                    ->counts('failovers')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('stream_id_custom')
                    ->label('ID')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn($record) => $record->stream_id)
                    ->placeholder(fn($record) => $record->stream_id)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('title_custom')
                    ->label('Title')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn($record) => $record->title)
                    ->placeholder(fn($record) => $record->title)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('name_custom')
                    ->label('Name')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn($record) => $record->name)
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
                    ->label('Time Shift')
                    ->rules(['numeric', 'min:0'])
                    ->type('number')
                    ->placeholder('Time Shift')
                    ->tooltip('Time Shift')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group')
                    ->hidden(fn() => $relationId)
                    ->toggleable()
                    ->searchable(query: function ($query, string $search): Builder {
                        $connection = $query->getConnection();
                        $driver = $connection->getDriverName();

                        switch ($driver) {
                            case 'pgsql':
                                return $query->orWhereRaw('LOWER("group"::text) LIKE ?', ["%{$search}%"]);
                            case 'mysql':
                                return $query->orWhereRaw('LOWER(`group`) LIKE ?', ["%{$search}%"]);
                            case 'sqlite':
                                return $query->orWhereRaw('LOWER("group") LIKE ?', ["%{$search}%"]);
                            default:
                                // Fallback using Laravel's database abstraction
                                return $query->orWhere(DB::raw('LOWER(group)'), 'LIKE', "%{$search}%");
                        }
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('epgChannel.name')
                    ->label('EPG Channel')
                    ->toggleable()
                    ->searchable()
                    ->limit(40)
                    ->sortable(),
                Tables\Columns\TextInputColumn::make('tvg_shift')
                    ->label('EPG Shift')
                    ->rules(['numeric'])
                    ->placeholder('EPG Shift')
                    ->tooltip('EPG Shift')
                    ->toggleable()
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
                Tables\Filters\Filter::make('enabled')
                    ->label('Channel is enabled')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('enabled', true);
                    }),
                Tables\Filters\Filter::make('disabled')
                    ->label('Channel is disabled')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('enabled', false);
                    }),
                Tables\Filters\Filter::make('mapped')
                    ->label('EPG is mapped')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('epg_channel_id', '!=', null);
                    }),
                Tables\Filters\Filter::make('un_mapped')
                    ->label('EPG is not mapped')
                    ->toggle()
                    ->query(function ($query) {
                        return $query->where('epg_channel_id', '=', null);
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make('edit_custom')
                        ->slideOver()
                        ->form(fn(Tables\Actions\EditAction $action): array => [
                            Forms\Components\Grid::make()
                                ->schema(self::getForm(edit: true))
                                ->columns(2)
                        ]),
                    Tables\Actions\DeleteAction::make()
                ])->button()->hiddenLabel()->size('sm')->hidden(fn(Model $record) => !$record->is_custom),
                Tables\Actions\EditAction::make('edit')
                    ->slideOver()
                    ->form(fn(Tables\Actions\EditAction $action): array => [
                        Forms\Components\Grid::make()
                            ->schema(self::getForm(edit: true))
                            ->columns(2)
                    ])
                    ->button()
                    ->hiddenLabel()
                    ->disabled(fn(Model $record) => $record->is_custom)
                    ->hidden(fn(Model $record) => $record->is_custom),
                Tables\Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->slideOver(),
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
                        ->form(EpgMapResource::getForm(showPlaylist: false, showEpg: true))
                        ->action(function (Collection $records, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\MapPlaylistChannelsToEpg(
                                    epg: (int)$data['epg_id'],
                                    channels: $records->pluck('id')->toArray(),
                                    force: $data['override'],
                                    settings: $data['settings'] ?? [],
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
                    Tables\Actions\BulkAction::make('failover')
                        ->label('Add as failover')
                        ->form(function (Collection $records) {
                            $existingFailoverIds = $records->pluck('id')->toArray();
                            $initialMasterOptions = [];
                            foreach ($records as $record) {
                                $displayTitle = $record->title_custom ?: $record->title;
                                $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';
                                $initialMasterOptions[$record->id] = "{$displayTitle} [{$playlistName}]";
                            }
                            return [
                                Forms\Components\ToggleButtons::make('master_source')
                                    ->label('Choose master from?')
                                    ->options([
                                        'selected' => 'Selected Channels',
                                        'searched' => 'Channel Search',
                                    ])
                                    ->icons([
                                        'selected' => 'heroicon-o-check',
                                        'searched' => 'heroicon-o-magnifying-glass',
                                    ])
                                    ->default('selected')
                                    ->live()
                                    ->grouped(),
                                Forms\Components\Select::make('selected_master_id')
                                    ->label('Select master channel')
                                    ->helperText('From the selected channels')
                                    ->options($initialMasterOptions)
                                    ->required()
                                    ->hidden(fn(Get $get) => $get('master_source') !== 'selected')
                                    ->searchable(),
                                Forms\Components\Select::make('master_channel_id')
                                    ->label('Search for master channel')
                                    ->searchable()
                                    ->required()
                                    ->hidden(fn(Get $get) => $get('master_source') !== 'searched')
                                    ->getSearchResultsUsing(function (string $search) use ($existingFailoverIds) {
                                        $searchLower = strtolower($search);
                                        $channels = Auth::user()->channels()
                                            ->withoutEagerLoads()
                                            ->with('playlist')
                                            ->whereNotIn('id', $existingFailoverIds)
                                            ->where(function ($query) use ($searchLower) {
                                                $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                            })
                                            ->limit(50) // Keep a reasonable limit
                                            ->get();

                                        // Create options array
                                        $options = [];
                                        foreach ($channels as $channel) {
                                            $displayTitle = $channel->title_custom ?: $channel->title;
                                            $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                            $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                        }

                                        return $options;
                                    })
                                    ->helperText('To use as the master for the selected channel.')
                                    ->required(),
                            ];
                        })
                        ->action(function (Collection $records, array $data): void {
                            // Filter out the master channel from the records to be added as failovers
                            $masterRecordId = $data['master_source'] === 'selected'
                                ? $data['selected_master_id']
                                : $data['master_channel_id'];
                            $failoverRecords = $records->filter(function ($record) use ($masterRecordId) {
                                return (int)$record->id !== (int)$masterRecordId;
                            });

                            foreach ($failoverRecords as $record) {
                                ChannelFailover::updateOrCreate([
                                    'channel_id' => $masterRecordId,
                                    'channel_failover_id' => $record->id,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channels as failover')
                                ->body('The selected channels have been added as failovers.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->modalIcon('heroicon-o-arrow-path-rounded-square')
                        ->modalDescription('Add the selected channel(s) to the chosen channel as failover sources.')
                        ->modalSubmitActionLabel('Add failovers now'),

                    Tables\Actions\BulkAction::make('find-replace')
                        ->label('Find & Replace')
                        ->form([
                            Forms\Components\Toggle::make('use_regex')
                                ->label('Use Regex')
                                ->live()
                                ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                                ->default(true),
                            Forms\Components\Select::make('column')
                                ->label('Column to modify')
                                ->options([
                                    'title' => 'Channel Title',
                                    'name' => 'Channel Name (tvg-name)',
                                ])
                                ->default('title')
                                ->required()
                                ->columnSpan(1),
                            Forms\Components\TextInput::make('find_replace')
                                ->label(fn(Get $get) =>  !$get('use_regex') ? 'String to replace' : 'Pattern to replace')
                                ->required()
                                ->placeholder(
                                    fn(Get $get) => $get('use_regex')
                                        ? '^(US- |UK- |CA- )'
                                        : 'US -'
                                )->helperText(
                                    fn(Get $get) => !$get('use_regex')
                                        ? 'This is the string you want to find and replace.'
                                        : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
                                ),
                            Forms\Components\TextInput::make('replace_with')
                                ->label('Replace with (optional)')
                                ->placeholder('Leave empty to remove')

                        ])
                        ->action(function (Collection $records, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ChannelFindAndReplace(
                                    user_id: auth()->id(), // The ID of the user who owns the content
                                    use_regex: $data['use_regex'] ?? true,
                                    column: $data['column'] ?? 'title',
                                    find_replace: $data['find_replace'] ?? null,
                                    replace_with: $data['replace_with'] ?? '',
                                    channels: $records
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Find & Replace started')
                                ->body('Find & Replace working in the background. You will be notified once the process is complete.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-magnifying-glass')
                        ->color('gray')
                        ->modalIcon('heroicon-o-magnifying-glass')
                        ->modalDescription('Select what you would like to find and replace in the selected channels.')
                        ->modalSubmitActionLabel('Replace now'),
                    Tables\Actions\BulkAction::make('find-replace-reset')
                        ->label('Undo Find & Replace')
                        ->form([
                            Forms\Components\Select::make('column')
                                ->label('Column to reset')
                                ->options([
                                    'title' => 'Channel Title',
                                    'name' => 'Channel Name (tvg-name)',
                                ])
                                ->default('title')
                                ->required()
                                ->columnSpan(1),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ChannelFindAndReplaceReset(
                                    user_id: auth()->id(), // The ID of the user who owns the content
                                    column: $data['column'] ?? 'title',
                                    channels: $records
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Find & Replace reset started')
                                ->body('Find & Replace reset working in the background. You will be notified once the process is complete.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset Find & Replace results back to playlist defaults for the selected channels. This will remove any custom values set in the selected column.')
                        ->modalSubmitActionLabel('Reset now'),
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
            //'view' => Pages\ViewChannel::route('/{record}'),
            //'create' => Pages\CreateChannel::route('/create'),
            // 'edit' => Pages\EditChannel::route('/{record}/edit'),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                VideoPreview::make('preview')
                    ->columnSpanFull()
                    ->hiddenLabel(),
                Infolists\Components\Section::make('Channel Details')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('url')
                            ->label('URL')->columnSpanFull(),
                        Infolists\Components\TextEntry::make('proxy_url')
                            ->label('Proxy URL')->columnSpanFull(),
                        Infolists\Components\TextEntry::make('stream_id')
                            ->label('ID'),
                        Infolists\Components\TextEntry::make('title')
                            ->label('Title'),
                        Infolists\Components\TextEntry::make('name')
                            ->label('Name'),
                        Infolists\Components\TextEntry::make('channel')
                            ->label('Channel'),
                        Infolists\Components\TextEntry::make('group')
                            ->label('Group'),
                    ]),
                Infolists\Components\Section::make('Stream Info')
                    ->description('Click to load stream info')
                    ->icon('heroicon-m-wifi')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Infolists\Components\Livewire::make(ChannelStreamStats::class)
                            ->label('Stream Stats')
                            ->columnSpanFull()
                            ->lazy(),
                    ]),
            ]);
    }

    public static function getForm($customPlaylist = null, $edit = false): array
    {
        return [
            // Customizable channel fields
            Forms\Components\Toggle::make('enabled')
                ->default(true)
                ->columnSpan('full'),
            Forms\Components\Fieldset::make('Playlist Type (choose one)')
                ->schema([
                    Forms\Components\Toggle::make('is_custom')
                        ->default(true)
                        ->hidden()
                        ->columnSpan('full'),
                    Forms\Components\Select::make('playlist_id')
                        ->label('Playlist')
                        ->options(fn() => Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, $state) {
                            if ($state) {
                                $set('custom_playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                            }
                        })
                        ->requiredWithout('custom_playlist_id')
                        ->hidden($customPlaylist !== null)
                        ->validationMessages([
                            'required_without' => 'Playlist is required if not using a custom playlist.',
                        ])
                        ->rules(['exists:playlists,id']),
                    Forms\Components\Select::make('custom_playlist_id')
                        ->label('Custom Playlist')
                        ->options(fn() => CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable()
                        ->disabled($customPlaylist !== null)
                        ->default($customPlaylist ? $customPlaylist->id : null)
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, $state) {
                            if ($state) {
                                $set('playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                            }
                        })
                        ->requiredWithout('playlist_id')
                        ->validationMessages([
                            'required_without' => 'Custom Playlist is required if not using a standard playlist.',
                        ])
                        ->dehydrated(true)
                        ->rules(['exists:custom_playlists,id'])
                ])->hidden($edit),
            Forms\Components\Fieldset::make('General Settings')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Title')
                        ->columnSpan(1)
                        ->required()
                        ->hidden($edit)
                        ->rules(['min:1', 'max:255']),
                    Forms\Components\TextInput::make('title_custom')
                        ->label('Title')
                        ->placeholder(fn(Get $get) => $get('title'))
                        ->helperText("Leave empty to use default value.")
                        ->columnSpan(1)
                        ->rules(['min:1', 'max:255'])
                        ->hiddenOn('create'),
                    Forms\Components\TextInput::make('name_custom')
                        ->label('Name')
                        ->hint('tvg-name')
                        ->placeholder(fn(Get $get) => $get('name'))
                        ->helperText(fn(Get $get) => $get('is_custom') ? "" : "Leave empty to use default value.")
                        ->columnSpan(1)
                        ->rules(['min:1', 'max:255']),
                    Forms\Components\TextInput::make('stream_id_custom')
                        ->label('ID')
                        ->hint('tvg-id')
                        ->columnSpan(1)
                        ->placeholder(fn(Get $get) => $get('stream_id'))
                        ->helperText(fn(Get $get) => $get('is_custom') ? "" : "Leave empty to use default value.")
                        ->rules(['min:1', 'max:255']),
                    Forms\Components\TextInput::make('station_id')
                        ->label('Station ID')
                        ->hint('tvc-guide-stationid')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Gracenote station ID is a unique identifier for a TV channel in the Gracenote database. It is used to associate the channel with its metadata, such as program listings and other information.'
                        )
                        ->columnSpan(1)
                        ->helperText("Gracenote station ID")
                        ->type('number')
                        ->rules(['numeric', 'min:0']),
                    Forms\Components\TextInput::make('channel')
                        ->label('Channel No.')
                        ->hint('tvg-chno')
                        ->columnSpan(1)
                        ->rules(['numeric', 'min:0']),
                    Forms\Components\TextInput::make('shift')
                        ->label("Time Shift")
                        ->hint('timeshift')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Time-shift is features that enable you to access content that has already been broadcast or is currently being broadcast, but at a different time than the original schedule. Time-shift allows you to pause, rewind, or fast-forward live TV, giving you more control over your viewing experience. Your provider must support this feature for it to work.'
                        )
                        ->placeholder(0)
                        ->columnSpan(1)
                        ->rules(['numeric', 'min:0']),
                    Forms\Components\Grid::make()
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Hidden::make('group'),
                            Forms\Components\Select::make('group_id')
                                ->label('Group')
                                ->hint('group-title')
                                ->options(fn(Get $get) => Group::where('playlist_id', $get('playlist_id'))->get(['name', 'id'])->pluck('name', 'id'))
                                ->columnSpanFull()
                                ->placeholder('Select a group')
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                    $group = Group::find($get('group_id'));
                                    $set('group', $group->name ?? null);
                                })
                                ->rules(['numeric', 'min:0']),
                        ])->hidden(fn(Get $get) => !$get('playlist_id')),
                    Forms\Components\TextInput::make('group')
                        ->columnSpanFull()
                        ->placeholder('Enter a group title')
                        ->hint('group-title')
                        ->hiddenOn('create')
                        ->rules(['min:1', 'max:255'])
                        ->hidden(fn(Get $get) => !$get('custom_playlist_id')),
                ]),
            Forms\Components\Fieldset::make('URL Settings')
                ->schema([
                    Forms\Components\TextInput::make('url')
                        ->label(fn(Get $get) => $get('is_custom') ? 'URL' : 'Provider URL')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            icon: fn(Get $get) => $get('is_custom') ? null : 'heroicon-m-question-mark-circle',
                            tooltip: fn(Get $get) => $get('is_custom') ? null : 'The original URL from the playlist provider. This is read-only and cannot be modified. This URL is automatically updated on Playlist sync.'
                        )
                        ->formatStateUsing(fn($record) => $record?->url)
                        ->disabled(fn(Get $get) => !$get('is_custom')) // make it read-only but copyable for non-custom channels
                        ->dehydrated(fn(Get $get) => $get('is_custom')) // don't save the value in the database for custom channels
                        ->type('url'),
                    Forms\Components\TextInput::make('url_custom')
                        ->label('URL Override')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Override the provider URL with your own custom URL. This URL will be used instead of the provider URL.'
                        )
                        ->helperText("Leave empty to use provider URL.")
                        ->rules(['min:1'])
                        ->type('url')
                        ->hidden(fn(Get $get) => $get('is_custom')),
                    Forms\Components\TextInput::make('url_proxy')
                        ->label('Proxy URL')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Use m3u editor proxy to access this channel. Format is defined in playlist proxy options.'
                        )
                        ->formatStateUsing(function ($record) {
                            if (!$record || !$record->id) {
                                return null;
                            }
                            try {
                                return ProxyFacade::getProxyUrlForChannel(
                                    $record->id,
                                    $record->playlist->proxy_options['output'] ?? 'ts'
                                );
                            } catch (\Exception $e) {
                                return null;
                            }
                        })
                        ->helperText("m3u editor proxy url.")
                        ->disabled() // make it read-only but copyable
                        ->dehydrated(false) // don't save the value in the database
                        ->type('url')
                        ->hiddenOn('create'),
                    Forms\Components\TextInput::make('logo')
                        ->label('Logo')
                        ->hint('tvg-logo')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->url(),
                ]),
            Forms\Components\Fieldset::make('EPG Settings')
                ->schema([
                    Forms\Components\Select::make('epg_channel_id')
                        ->label('EPG Channel')
                        ->helperText('Select an associated EPG channel for this channel.')
                        ->relationship('epgChannel', 'name')
                        ->getOptionLabelFromRecordUsing(fn($record) => "$record->name [{$record->epg->name}]")
                        ->getSearchResultsUsing(function (string $search) {
                            $searchLower = strtolower($search);
                            $channels = Auth::user()->epgChannels()
                                ->withoutEagerLoads()
                                ->with('epg')
                                ->where(function ($query) use ($searchLower) {
                                    $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                        ->orWhereRaw('LOWER(display_name) LIKE ?', ["%{$searchLower}%"])
                                        ->orWhereRaw('LOWER(channel_id) LIKE ?', ["%{$searchLower}%"]);
                                })
                                ->limit(50) // Keep a reasonable limit
                                ->get();

                            // Create options array
                            $options = [];
                            foreach ($channels as $channel) {
                                $displayTitle = $channel->name;
                                $epgName = $channel->epg->name ?? 'Unknown';
                                $options[$channel->id] = "{$displayTitle} [{$epgName}]";
                            }
                            return $options;
                        })
                        ->searchable()
                        ->columnSpan(1),
                    Forms\Components\Select::make('logo_type')
                        ->label('Preferred Icon')
                        ->helperText('Prefer icon from channel or EPG.')
                        ->options([
                            'channel' => 'Channel',
                            'epg' => 'EPG',
                        ])
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('tvg_shift')
                        ->label('EPG Shift')
                        ->hint('tvg-shift')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'The "tvg-shift" attribute is used in your generated M3U playlist to shift the EPG (Electronic Program Guide) time for specific channels by a certain number of hours. This allows for adjusting the EPG data for individual channels rather than applying a global shift.'
                        )
                        ->columnSpan(1)
                        ->rules(['numeric'])
                        ->placeholder('0')
                        ->helperText('Indicates the shift of the program schedule, use the values -1,-1.5,0,1.5,1,.. and so on.')
                        ->rules(['nullable', 'numeric', 'min:0']),
                ]),
            Forms\Components\Fieldset::make('Failover Channels')
                ->schema([
                    Forms\Components\Repeater::make('failovers')
                        ->relationship()
                        ->label('')
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->orderColumn('sort')
                        ->simple(
                            Forms\Components\Select::make('channel_failover_id')
                                ->label('Failover Channel')
                                ->options(function ($state, $record) {
                                    // Get the current channel ID to exclude it from options
                                    if (!$state) {
                                        return [];
                                    }
                                    $channel = \App\Models\Channel::find($state);
                                    if (!$channel) {
                                        return [];
                                    }

                                    // Return the single channel as the only results if not searching
                                    $displayTitle = $channel->title_custom ?: $channel->title;
                                    $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                    return [$channel->id => "{$displayTitle} [{$playlistName}]"];
                                })
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search, $get, $livewire) {
                                    $existingFailoverIds = collect($get('../../failovers') ?? [])
                                        ->filter(fn($failover) => $failover['channel_failover_id'] ?? null)
                                        ->pluck('channel_failover_id')
                                        ->toArray();

                                    // Get parent record ID to exclude it from search results
                                    $parentRecordId = $livewire->mountedTableActionsData[0]['id'] ?? null;
                                    if ($parentRecordId) {
                                        $existingFailoverIds[] = $parentRecordId;
                                    }

                                    // Always include the selected value if it exists
                                    $searchLower = strtolower($search);
                                    $channels = Auth::user()->channels()
                                        ->withoutEagerLoads()
                                        ->with('playlist')
                                        ->whereNotIn('id', $existingFailoverIds)
                                        ->where(function ($query) use ($searchLower) {
                                            $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                        })
                                        ->limit(50) // Keep a reasonable limit
                                        ->get();

                                    // Create options array
                                    $options = [];
                                    foreach ($channels as $channel) {
                                        $displayTitle = $channel->title_custom ?: $channel->title;
                                        $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                        $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                    }

                                    return $options;
                                })->required()
                        )
                        ->distinct()
                        ->columns(1)
                        ->addActionLabel('Add failover channel')
                        ->columnSpanFull()
                        ->defaultItems(0)
                ])
        ];
    }

    /**
     * Create a custom channel with the provided data.
     * 
     * This method is used to create a channel with custom data, typically for a Custom Playlist.
     * 
     * @param array $data The data for the channel.
     * @param string $model The model class to use for creating the channel.
     * @return Model The created channel model.
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \Illuminate\Database\QueryException
     * @throws \Exception
     */
    public static function createCustomChannel(array $data, string $model): Model
    {
        $data['user_id'] = auth()->id();
        $data['is_custom'] = true;
        if (!$data['shift']) {
            $data['shift'] = 0; // Default shift to 0 if not provided
        }
        if (!$data['logo_type']) {
            $data['logo_type'] = 'channel'; // Default to channel if not provided
        }
        $channel = $model::create($data);

        // If the channel is created for a Custom Playlist, we need to associate it with the Custom Playlist
        if (isset($data['custom_playlist_id']) && $data['custom_playlist_id']) {
            $channel->customPlaylists()
                ->syncWithoutDetaching([$data['custom_playlist_id']]);

            $channel->save();
        }
        return $channel;
    }
}
