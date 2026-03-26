<?php

namespace App\Filament\Resources\ChannelScrubbers;

use App\Enums\Status;
use App\Filament\Resources\ChannelScrubbers\Pages\ListChannelScrubbers;
use App\Filament\Resources\ChannelScrubbers\Pages\ViewChannelScrubber;
use App\Filament\Resources\ChannelScrubbers\RelationManagers\ScrubberLogsRelationManager;
use App\Jobs\ProcessChannelScrubber;
use App\Models\ChannelScrubber;
use App\Models\Playlist;
use App\Tables\Columns\ProgressColumn;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ChannelScrubberResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = ChannelScrubber::class;

    protected static ?string $label = 'Channel Scrubber';

    protected static ?string $pluralLabel = 'Channel Scrubbers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Playlist';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseScrubber();
    }

    public static function getNavigationSort(): ?int
    {
        return 8;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
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
                TextColumn::make('playlist.name')
                    ->label('Playlist')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('check_method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->color(fn (string $state): string => $state === 'ffprobe' ? 'warning' : 'info')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('channel_count')
                    ->label('Checked')
                    ->tooltip('Total number of channels checked in the last run.')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('dead_count')
                    ->label('Dead Links')
                    ->tooltip('Number of channels with dead links found in the last run.')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->toggleable()
                    ->sortable(),
                ToggleColumn::make('include_vod')
                    ->label('VOD')
                    ->tooltip('Include VOD channels in the scrub.')
                    ->toggleable()
                    ->sortable(),
                ToggleColumn::make('recurring')
                    ->tooltip('Run automatically after each playlist sync.')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('sync_time')
                    ->label('Sync Time')
                    ->formatStateUsing(fn ($state): string => $state ? gmdate('H:i:s', (int) $state) : '-')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('last_run_at')
                    ->label('Last Ran')
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
                ViewAction::make()
                    ->button()
                    ->tooltip('View scrubber logs')
                    ->icon('heroicon-s-document-text')
                    ->hiddenLabel(),
                Action::make('run')
                    ->label('Run Now')
                    ->icon('heroicon-s-play-circle')
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-s-arrow-path')
                    ->modalDescription('Are you sure you want to manually trigger this scrubber to run? This will not modify the "Recurring" setting.')
                    ->modalSubmitActionLabel('Run Now')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Processing,
                            'progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessChannelScrubber($record->id));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channel scrubber started')
                            ->body('The scrubber has been initiated and will run in the background.')
                            ->duration(10000)
                            ->send();
                    })
                    ->hidden(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending)
                    ->tooltip('Manually trigger this scrubber to run.'),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-s-x-circle')
                    ->button()
                    ->hiddenLabel()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-s-x-circle')
                    ->modalDescription('Cancel this scrubber run? The current run will be abandoned. Any channels already disabled during this run will remain disabled.')
                    ->modalSubmitActionLabel('Cancel Run')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Cancelled,
                            'progress' => 0,
                            'processing' => false,
                        ]);
                    })->after(function () {
                        Notification::make()
                            ->warning()
                            ->title('Scrubber run cancelled')
                            ->body('The run has been cancelled. In-progress checks will complete before stopping.')
                            ->duration(10000)
                            ->send();
                    })
                    ->hidden(fn ($record) => ! ($record->status === Status::Processing || $record->status === Status::Pending))
                    ->tooltip('Cancel the in-progress scrubber run.'),
                Action::make('restart')
                    ->label('Restart Now')
                    ->icon('heroicon-s-arrow-path')
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-s-arrow-path')
                    ->modalDescription('Restart this scrubber? The existing run will be abandoned and a new one will begin.')
                    ->modalSubmitActionLabel('Restart Now')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Processing,
                            'progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessChannelScrubber($record->id));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channel scrubber restarted')
                            ->body('The scrubber has been re-initiated.')
                            ->duration(10000)
                            ->send();
                    })
                    ->hidden(fn ($record) => ! ($record->status === Status::Processing || $record->status === Status::Pending))
                    ->tooltip('Restart the in-progress scrubber run.'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('run')
                        ->label('Run Now')
                        ->icon('heroicon-s-play-circle')
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-s-arrow-path')
                        ->modalDescription('Run the selected scrubbers now? This will not modify the "Recurring" setting.')
                        ->modalSubmitActionLabel('Run Now')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                if ($record->status === Status::Processing || $record->status === Status::Pending) {
                                    continue;
                                }
                                $record->update([
                                    'status' => Status::Processing,
                                    'progress' => 0,
                                ]);
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new ProcessChannelScrubber($record->id));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channel scrubbers started')
                                ->body('The selected scrubbers have been initiated.')
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
            ScrubberLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChannelScrubbers::route('/'),
            'view' => ViewChannelScrubber::route('/{record}'),
        ];
    }

    public static function getForm(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Select::make('playlist_id')
                ->required()
                ->label('Playlist')
                ->helperText('Select the playlist whose channels you want to scrub.')
                ->options(Playlist::where('user_id', Auth::id())->get(['name', 'id'])->pluck('name', 'id'))
                ->searchable(),
            Toggle::make('recurring')
                ->label('Recurring')
                ->inline(false)
                ->helperText('Automatically run this scrubber after each playlist sync.')
                ->default(false),
            Section::make('Check Method')
                ->icon('heroicon-s-signal')
                ->compact()
                ->columnSpanFull()
                ->schema([
                    Radio::make('check_method')
                        ->label(false)
                        ->options([
                            'http' => 'HTTP',
                            'ffprobe' => 'FFprobe',
                        ])
                        ->descriptions([
                            'http' => 'Sends a quick HEAD request to each stream URL. Very fast, but may produce false positives for streams that require active connections (e.g. some providers reject HEAD requests). Recommended for large playlists.',
                            'ffprobe' => 'Uses ffprobe to attempt to open each stream and verify it returns valid media data. Much more reliable and accurate, but significantly slower — expect long run times for large playlists.',
                        ])
                        ->default('http')
                        ->required(),
                ]),
            Section::make('Scan Scope')
                ->icon('heroicon-s-exclamation-triangle')
                ->compact()
                ->columnSpanFull()
                ->schema([
                    Grid::make()
                        ->columnSpanFull()
                        ->columns(2)
                        ->schema([
                            Toggle::make('scan_all')
                                ->label('Scan all channels (including disabled)')
                                ->hintIcon(
                                    'heroicon-s-information-circle',
                                    tooltip: 'By default, only enabled channels are checked. Enabling this will also scan disabled channels. ',
                                )
                                ->helperText('Warning: this can result in a very large number of connections to the provider and significantly longer run times.')
                                ->default(false),
                            Toggle::make('include_vod')
                                ->label('Include VOD')
                                ->helperText('Also check VOD channel links in addition to live channels.')
                                ->default(false),
                        ]),
                ]),
        ];
    }
}
