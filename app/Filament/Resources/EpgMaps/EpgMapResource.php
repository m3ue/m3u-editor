<?php

namespace App\Filament\Resources\EpgMaps;

use App\Enums\Status;
use App\Filament\Resources\EpgMapResource\Pages;
use App\Filament\Resources\EpgMaps\Pages\ListEpgMaps;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Playlist;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class EpgMapResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = EpgMap::class;

    protected static ?string $label = 'EPG Map';

    protected static ?string $pluralLabel = 'EPG Maps';

    protected static string|\UnitEnum|null $navigationGroup = 'EPG';

    public static function getNavigationSort(): ?int
    {
        return 6;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm(showPlaylist: false, showEpg: false));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll()
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(fn (Status $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->sortable()
                    ->poll(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                TextColumn::make('total_channel_count')
                    ->label('Total Channels')
                    ->tooltip('Total number of channels available for this mapping.')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('current_mapped_count')
                    ->label('Currently Mapped')
                    ->tooltip('Number of channels that were already mapped to an EPG entry.')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('channel_count')
                    ->label('Search & Map')
                    ->tooltip('Number of channels that were searched for a matching EPG entry in this mapping. If the "Override" option is enabled, this will also include channels that were previously mapped. If the "Override" option is disabled, this will only include channels that were not previously mapped.')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('mapped_count')
                    ->label('Newly Mapped')
                    ->tooltip('Number of channels that were successfully matched to an EPG entry in this mapping. When "Override" is disabled, it is normal for this count to be 0 on subsequent syncs.')
                    ->toggleable()
                    ->sortable(),
                ToggleColumn::make('override')
                    ->toggleable()
                    ->tooltip((fn (EpgMap $record) => $record->playlist_id !== null ? 'Override existing EPG mappings' : 'Not available for custom channel mappings'))
                    ->disabled((fn (EpgMap $record) => $record->playlist_id === null))
                    ->sortable(),
                ToggleColumn::make('recurring')
                    ->toggleable()
                    ->tooltip((fn (EpgMap $record) => $record->playlist_id !== null ? 'Run again on EPG sync' : 'Not available for custom channel mappings'))
                    ->disabled((fn (EpgMap $record) => $record->playlist_id === null))
                    ->sortable(),
                TextColumn::make('sync_time')
                    ->label('Sync Time')
                    ->formatStateUsing(fn (string $state): string => gmdate('H:i:s', (int) $state))
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('mapped_at')
                    ->label('Last ran')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()
                    ->hiddenLabel(),
                EditAction::make()
                    ->button()
                    ->hiddenLabel(),
                Action::make('run')
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
                    ->hidden(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending)
                    ->tooltip('Manually trigger this EPG mapping to run again. This will not modify the "Recurring" setting.'),
                Action::make('restart')
                    ->label('Restart Now')
                    ->icon('heroicon-s-arrow-path')
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-s-arrow-path')
                    ->modalDescription('Manually restart this EPG mapping? This will restart the existing mapping process.')
                    ->modalSubmitActionLabel('Restart Now')
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
                            ->title('EPG mapping restarted')
                            ->body('The EPG mapping process has been re-initiated.')
                            ->duration(10000)
                            ->send();
                    })
                    ->hidden(fn ($record) => ! ($record->status === Status::Processing || $record->status === Status::Pending))
                    ->tooltip('Restart existing mapping process.'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('run')
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
                    DeleteBulkAction::make(),
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
            'index' => ListEpgMaps::route('/'),
            // 'create' => Pages\CreateEpgMap::route('/create'),
            // 'edit' => Pages\EditEpgMap::route('/{record}/edit'),
        ];
    }

    public static function getForm(
        $showPlaylist = true,
        $showEpg = true
    ): array {
        return [
            Select::make('epg_id')
                ->required()
                ->label('EPG')
                ->helperText('Select the EPG you would like to map from.')
                ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->hidden(! $showEpg)
                ->searchable(),
            Select::make('playlist_id')
                ->required()
                ->label('Playlist')
                ->helperText('Select the playlist you would like to map to.')
                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->hidden(! $showPlaylist)
                ->searchable(),
            Toggle::make('override')
                ->label('Overwrite')
                ->helperText('Overwrite channels with existing mappings?')
                ->default(false),
            Toggle::make('recurring')
                ->label('Recurring')
                ->helperText('Re-run this mapping everytime the EPG is synced?')
                ->default(false),
            Fieldset::make('Advanced Settings')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('settings.use_regex')
                        ->label('Use regex for filtering')
                        ->columnSpanFull()
                        ->inline(true)
                        ->live()
                        ->default(false)
                        ->helperText('When enabled, channel attributes will be cleaned based on regex pattern instead of prefix before matching.'),
                    Toggle::make('settings.remove_quality_indicators')
                        ->label('Remove quality indicators')
                        ->columnSpanFull()
                        ->inline(true)
                        ->default(false)
                        ->helperText('When enabled, quality indicators (HD, FHD, UHD, 4K, 720p, 1080p, etc.) will be removed during fuzzy matching. Disable this if channels have similar names but different quality levels (e.g., "Sport HD" vs "Sport FHD").'),

                    Toggle::make('settings.prioritize_name_match')
                        ->label('Prioritize name/display name matching')
                        ->columnSpanFull()
                        ->inline(true)
                        ->default(false)
                        ->helperText('When enabled, exact matches on channel name/display name will be prioritized over channel_id matches. Enable this if your EPG has duplicate channel_ids for different quality versions (e.g., DasErsteHD for "Das Erste HDraw", "Das Erste HDraw²", etc.). Disable if your EPG uses unique channel_ids.'),

                    Fieldset::make('Matching Thresholds')
                        ->schema([
                            Forms\Components\TextInput::make('settings.similarity_threshold')
                                ->label('Minimum Similarity (%)')
                                ->numeric()
                                ->default(70)
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->helperText('Minimum similarity percentage required for a match (0-100). Higher = stricter matching. Default: 70%'),

                            Forms\Components\TextInput::make('settings.fuzzy_max_distance')
                                ->label('Maximum Fuzzy Distance')
                                ->numeric()
                                ->default(25)
                                ->minValue(0)
                                ->maxValue(100)
                                ->helperText('Maximum Levenshtein distance allowed for fuzzy matching. Lower = stricter matching. Default: 25'),

                            Forms\Components\TextInput::make('settings.exact_match_distance')
                                ->label('Exact Match Distance')
                                ->numeric()
                                ->default(8)
                                ->minValue(0)
                                ->maxValue(50)
                                ->helperText('Maximum distance for exact matches. Lower = stricter exact matching. Default: 8'),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),

                    TagsInput::make('settings.exclude_prefixes')
                        ->label(fn (Get $get) => ! $get('settings.use_regex') ? 'Channel prefixes to remove before matching' : 'Regex patterns to remove before matching')
                        ->helperText('Press [tab] or [return] to add item. Leave empty to disable.')
                        ->columnSpanFull()
                        ->suggestions([
                            'US: ',
                            'UK: ',
                            'CA: ',
                            '^(US|UK|CA): ',
                            '\s*(FHD|HD)\s*',
                            '\s+(FHD|HD).*$',
                            '\[.*\]',
                        ])
                        ->splitKeys(['Tab', 'Return']),
                ]),
        ];
    }
}
