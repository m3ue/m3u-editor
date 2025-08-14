<?php

namespace App\Filament\Resources;

use App\Enums\Status;
use App\Filament\Resources\EpgMapResource\Pages;
use App\Filament\Resources\EpgMapResource\RelationManagers;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Playlist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class EpgMapResource extends Resource
{
    protected static ?string $model = EpgMap::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $label = 'EPG Map';
    protected static ?string $pluralLabel = 'EPG Maps';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 6;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm(showPlaylist: false, showEpg: false));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll()
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(fn(Status $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->sortable()
                    ->poll(fn($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total_channel_count')
                    ->label('Total Channels')
                    ->tooltip('Total number of channels available for this mapping.')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_mapped_count')
                    ->label('Currently Mapped')
                    ->tooltip('Number of channels that were already mapped to an EPG entry.')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel_count')
                    ->label('Search & Map')
                    ->tooltip('Number of channels that were searched for a matching EPG entry in this mapping. If the "Override" option is enabled, this will also include channels that were previously mapped. If the "Override" option is disabled, this will only include channels that were not previously mapped.')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('mapped_count')
                    ->label('Newly Mapped')
                    ->tooltip('Number of channels that were successfully matched to an EPG entry in this mapping. When "Override" is disabled, it is normal for this count to be 0 on subsequent syncs.')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('override')
                    ->toggleable()
                    ->tooltip((fn(EpgMap $record) => $record->playlist_id !== null ? 'Override existing EPG mappings' : 'Not available for custom channel mappings'))
                    ->disabled((fn(EpgMap $record) => $record->playlist_id === null))
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('recurring')
                    ->toggleable()
                    ->tooltip((fn(EpgMap $record) => $record->playlist_id !== null ? 'Run again on EPG sync' : 'Not available for custom channel mappings'))
                    ->disabled((fn(EpgMap $record) => $record->playlist_id === null))
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_time')
                    ->label('Sync Time')
                    ->formatStateUsing(fn(string $state): string => gmdate('H:i:s', (int)$state))
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('mapped_at')
                    ->label('Last ran')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel(),
                Tables\Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel(),
                Tables\Actions\Action::make('run')
                    ->label('Run Now')
                    ->icon('heroicon-s-play-circle')
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-s-arrow-path')
                    ->modalDescription('Are you sure you want to manually trigger this EPG mapping to run again? This will not modify the "Recurring" setting.')
                    ->modalSubmitActionLabel('Run Now')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Processing,
                            'progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new MapPlaylistChannelsToEpg(
                                epg: $record->epg_id,
                                playlist: $record->playlist_id,
                                epgMapId: $record->id,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('EPG mapping started')
                            ->body('The EPG mapping process has been initiated.')
                            ->duration(10000)
                            ->send();
                    })
                    ->disabled(fn($record) => $record->status === Status::Processing || $record->status === Status::Pending)
                    ->tooltip(fn($record) => $record->status === Status::Processing || $record->status === Status::Pending ? 'Mapping in progress' : 'Manually trigger this EPG mapping to run again. This will not modify the "Recurring" setting.'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('run')
                        ->label('Run Now')
                        ->icon('heroicon-s-play-circle')
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-s-arrow-path')
                        ->modalDescription('Are you sure you want to manually trigger this EPG mapping to run again? This will not modify the "Recurring" setting.')
                        ->modalSubmitActionLabel('Run Now')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                if ($record->status === Status::Processing || $record->status === Status::Pending) {
                                    // Skip records that are already processing
                                    continue;
                                }
                                $record->update([
                                    'status' => Status::Processing,
                                    'progress' => 0,
                                ]);
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new MapPlaylistChannelsToEpg(
                                        epg: $record->epg_id,
                                        playlist: $record->playlist_id,
                                        epgMapId: $record->id,
                                    ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('EPG mapping started')
                                ->body('The EPG mapping process has been initiated for the selected mappings.')
                                ->duration(10000)
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListEpgMaps::route('/'),
            // 'create' => Pages\CreateEpgMap::route('/create'),
            // 'edit' => Pages\EditEpgMap::route('/{record}/edit'),
        ];
    }

    public static function getForm(
        $showPlaylist = true,
        $showEpg = true
    ): array {
        return [
            Forms\Components\Select::make('epg_id')
                ->required()
                ->label('EPG')
                ->helperText('Select the EPG you would like to map from.')
                ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->hidden(!$showEpg)
                ->searchable(),
            Forms\Components\Select::make('playlist_id')
                ->required()
                ->label('Playlist')
                ->helperText('Select the playlist you would like to map to.')
                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->hidden(!$showPlaylist)
                ->searchable(),
            Forms\Components\Toggle::make('override')
                ->label('Overwrite')
                ->disabled((fn($record) => $record && $record->playlist_id === null))
                ->helperText((fn($record): string => $record && $record->playlist_id === null ? 'Not available for custom channel mappings' : 'Overwrite channels with existing mappings?'))
                ->hintIcon((fn($record) => $record && $record->playlist_id === null ? 'heroicon-o-lock-closed' : ''))
                ->default(false),
            Forms\Components\Toggle::make('recurring')
                ->label('Recurring')
                ->disabled((fn($record) => $record && $record->playlist_id === null))
                ->helperText((fn($record): string => $record && $record->playlist_id === null ? 'Not available for custom channel mappings' : 'Re-run this mapping everytime the EPG is synced?'))
                ->hintIcon((fn($record) => $record && $record->playlist_id === null ? 'heroicon-o-lock-closed' : ''))
                ->default(false),
            Forms\Components\Fieldset::make('Advanced Settings')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Toggle::make('settings.use_regex')
                        ->label('Use regex for filtering')
                        ->columnSpanFull()
                        ->inline(true)
                        ->live()
                        ->default(false)
                        ->helperText('When enabled, channel attributes will be cleaned based on regex pattern instead of prefix before matching.'),
                    Forms\Components\TagsInput::make('settings.exclude_prefixes')
                        ->label(fn(Get $get) => !$get('settings.use_regex') ? 'Channel prefixes to remove before matching' : 'Regex patterns to remove before matching')
                        ->helperText('Press [tab] or [return] to add item. Leave empty to disable.')
                        ->columnSpanFull()
                        ->suggestions([
                            'US: ',
                            'UK: ',
                            'CA: ',
                            '^(US|UK|CA): ',
                            '\s*(FHD|HD)\s*',
                            '\s+(FHD|HD).*$',
                            '\[.*\]'
                        ])
                        ->splitKeys(['Tab', 'Return']),
                ]),
        ];
    }
}
