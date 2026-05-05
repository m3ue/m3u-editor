<?php

namespace App\Filament\Resources\ChannelScrubbers;

use App\Enums\Status;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\ChannelScrubbers\Pages\ListChannelScrubbers;
use App\Filament\Resources\ChannelScrubbers\Pages\ViewChannelScrubber;
use App\Filament\Resources\ChannelScrubbers\RelationManagers\ScrubberLogsRelationManager;
use App\Jobs\ProcessChannelScrubber;
use App\Models\ChannelScrubber;
use App\Models\Playlist;
use App\Tables\Columns\ProgressColumn;
use App\Traits\HasUserFiltering;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ChannelScrubberResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = ChannelScrubber::class;

    protected static ?string $label = 'Channel Scrubber';

    protected static ?string $pluralLabel = 'Channel Scrubbers';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getModelLabel(): string
    {
        return __('Channel Scrubber');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Channel Scrubbers');
    }

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
                    ->label(__('Playlist'))
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('check_method')
                    ->label(__('Method'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->color(fn (string $state): string => $state === 'ffprobe' ? 'warning' : 'info')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('channel_count')
                    ->label(__('Checked'))
                    ->tooltip(__('Total number of channels checked in the last run.'))
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('dead_count')
                    ->label(__('Dead Links'))
                    ->tooltip(__('Number of channels with dead links found in the last run.'))
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->toggleable()
                    ->sortable(),
                ToggleColumn::make('recurring')
                    ->tooltip(__('Run automatically after each playlist sync.'))
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('sync_time')
                    ->label(__('Sync Time'))
                    ->formatStateUsing(fn ($state): string => $state ? gmdate('H:i:s', (int) $state) : '-')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('last_run_at')
                    ->label(__('Last Ran'))
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
                    ->tooltip(__('View scrubber logs'))
                    ->icon('heroicon-s-document-text')
                    ->hiddenLabel(),
                Action::make('run')
                    ->label(__('Run Now'))
                    ->icon('heroicon-s-play-circle')
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-s-arrow-path')
                    ->modalDescription(__('Are you sure you want to manually trigger this scrubber to run? This will not modify the "Recurring" setting.'))
                    ->modalSubmitActionLabel(__('Run Now'))
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
                            ->title(__('Channel scrubber started'))
                            ->body(__('The scrubber has been initiated and will run in the background.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->hidden(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending)
                    ->tooltip(__('Manually trigger this scrubber to run.')),
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon('heroicon-s-x-circle')
                    ->button()
                    ->hiddenLabel()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-s-x-circle')
                    ->modalDescription(__('Cancel this scrubber run? The current run will be abandoned. Any channels already disabled during this run will remain disabled.'))
                    ->modalSubmitActionLabel(__('Cancel Run'))
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Cancelled,
                            'progress' => 0,
                            'processing' => false,
                        ]);
                    })->after(function () {
                        Notification::make()
                            ->warning()
                            ->title(__('Scrubber run cancelled'))
                            ->body(__('The run has been cancelled. In-progress checks will complete before stopping.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->hidden(fn ($record) => ! ($record->status === Status::Processing || $record->status === Status::Pending))
                    ->tooltip(__('Cancel the in-progress scrubber run.')),
                Action::make('restart')
                    ->label(__('Restart Now'))
                    ->icon('heroicon-s-arrow-path')
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-s-arrow-path')
                    ->modalDescription(__('Restart this scrubber? The existing run will be abandoned and a new one will begin.'))
                    ->modalSubmitActionLabel(__('Restart Now'))
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
                            ->title(__('Channel scrubber restarted'))
                            ->body(__('The scrubber has been re-initiated.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->hidden(fn ($record) => ! ($record->status === Status::Processing || $record->status === Status::Pending))
                    ->tooltip(__('Restart the in-progress scrubber run.')),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('run')
                        ->label(__('Run Now'))
                        ->icon('heroicon-s-play-circle')
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-s-arrow-path')
                        ->modalDescription(__('Run the selected scrubbers now? This will not modify the "Recurring" setting.'))
                        ->modalSubmitActionLabel(__('Run Now'))
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
                                ->title(__('Channel scrubbers started'))
                                ->body(__('The selected scrubbers have been initiated.'))
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
                ->label(__('Playlist'))
                ->helperText(__('Select the playlist whose channels you want to scrub.'))
                ->options(Playlist::where('user_id', Auth::id())->get(['name', 'id'])->pluck('name', 'id'))
                ->searchable(),
            Toggle::make('recurring')
                ->label(__('Recurring'))
                ->inline(false)
                ->helperText(__('Automatically run this scrubber after each playlist sync.'))
                ->default(false),
            Section::make(__('Check Method'))
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
            Section::make(__('Performance'))
                ->icon('heroicon-s-bolt')
                ->compact()
                ->columnSpanFull()
                ->schema([
                    Toggle::make('use_batching')
                        ->label(__('Parallel processing'))
                        ->hintIcon(
                            'heroicon-s-information-circle',
                            tooltip: 'Dispatches jobs simultaneously instead of sequentially. Leave off if you encounter issues or run into provider limitations.',
                        )
                        ->helperText(__('Process in parallel rather than one-at-a-time for significantly faster results.'))
                        ->default(false),
                    TextInput::make('probe_timeout')
                        ->label(__('Probe timeout (seconds)'))
                        ->numeric()
                        ->minValue(3)
                        ->maxValue(30)
                        ->default(10)
                        ->required()
                        ->hintIcon(
                            'heroicon-s-information-circle',
                            tooltip: 'How long to wait for each stream to respond before marking it dead. Lower values are faster but may produce false positives on slow providers.',
                        )
                        ->helperText(__('Seconds to wait per stream (3–30). Streams that do not respond within this window are marked dead.')),
                ]),
            Section::make(__('Scan Scope'))
                ->icon('heroicon-s-exclamation-triangle')
                ->compact()
                ->columnSpanFull()
                ->schema([
                    Grid::make()
                        ->columnSpanFull()
                        ->columns(2)
                        ->schema([
                            Toggle::make('scan_all')
                                ->label(__('Scan all channels (including disabled)'))
                                ->hintIcon(
                                    'heroicon-s-information-circle',
                                    tooltip: 'By default, only enabled channels are checked. Enabling this will also scan disabled channels. ',
                                )
                                ->helperText(__('Warning: this can result in a very large number of connections to the provider and significantly longer run times.'))
                                ->live()
                                ->default(false),
                            Toggle::make('include_vod')
                                ->label(__('Include VOD'))
                                ->helperText(__('Also check VOD channel links in addition to live channels.'))
                                ->default(false),
                            Toggle::make('disable_dead')
                                ->label(__('Disable dead channels'))
                                ->hintIcon(
                                    'heroicon-s-information-circle',
                                    tooltip: 'Channels that fail the probe will be disabled. Turn off to run the scrubber in read-only mode — dead links are logged but no channels are changed.',
                                )
                                ->helperText(__('Automatically disable channels whose stream URL is unreachable.'))
                                ->default(true),
                            Toggle::make('enable_live')
                                ->label(__('Re-enable live channels'))
                                ->hintIcon(
                                    'heroicon-s-information-circle',
                                    tooltip: 'Only useful when scanning all channels (including disabled). Channels that were previously disabled but are now responding will be re-enabled.',
                                )
                                ->helperText(__('Re-enable disabled channels that are found to be live. Requires "Scan all channels" to be on.'))
                                ->default(false)
                                ->visible(fn (Get $get): bool => (bool) $get('scan_all')),
                        ]),
                ]),
        ];
    }
}
