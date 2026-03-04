<?php

namespace App\Filament\Resources\MergedEpgs;

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Filament\Resources\MergedEpgs\Pages\EditMergedEpg;
use App\Filament\Resources\MergedEpgs\Pages\ListMergedEpgs;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use App\Rules\Cron;
use App\Traits\HasUserFiltering;
use Cron\CronExpression;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class MergedEpgResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Epg::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'Merged EPG';

    protected static ?string $pluralLabel = 'Merged EPGs';

    protected static string|\UnitEnum|null $navigationGroup = 'EPG';

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id())
            ->where('is_merged', true);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', Auth::id())
            ->where('is_merged', true);
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
            ->deferLoading()
            ->columns([
                TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source_epgs_count')
                    ->counts('sourceEpgs')
                    ->label('Source EPGs')
                    ->sortable(),
                TextColumn::make('channel_count')
                    ->label('Channels')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->toggleable()
                    ->badge()
                    ->color(fn (Status $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->label('Sync Progress')
                    ->tooltip('Progress of merged EPG import/sync')
                    ->sortable()
                    ->poll(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                ToggleColumn::make('auto_sync')
                    ->label('Auto Sync')
                    ->toggleable()
                    ->tooltip('Toggle auto-sync status')
                    ->sortable(),
                TextColumn::make('synced')
                    ->label('Last Synced')
                    ->since()
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('sync_interval')
                    ->label('Next Sync')
                    ->toggleable()
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->auto_sync && $record->sync_interval && CronExpression::isValidExpression($record->sync_interval)) {
                            return (new CronExpression($record->sync_interval))->getNextRunDate()->format('Y-m-d H:i:s');
                        }

                        return 'N/A';
                    })
                    ->sortable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('process')
                        ->label('Process')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'progress' => 0,
                                'sd_progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new ProcessEpgImport($record, force: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Merged EPG is processing')
                                ->body('Merged EPG is being processed in the background. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn ($record): bool => $record->status === Status::Processing)
                        ->requiresConfirmation()
                        ->modalDescription('Process merged EPG now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Action::make('download')
                        ->label('Download EPG')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn ($record) => route('epg.file', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Action::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Pending,
                                'processing' => false,
                                'progress' => 0,
                                'sd_progress' => 0,
                                'cache_progress' => 0,
                                'synced' => null,
                                'errors' => null,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('EPG status reset')
                                ->body('EPG status has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset EPG status so it can be processed again. Only perform this action if you are having problems with the EPG syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('process')
                        ->label('Process selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => Status::Processing,
                                    'progress' => 0,
                                    'sd_progress' => 0,
                                ]);
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new ProcessEpgImport($record, force: true));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected merged EPGs are processing')
                                ->body('The selected merged EPGs are being processed in the background.')
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalDescription('Process the selected merged EPG(s) now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    DeleteBulkAction::make(),
                ]),
            ])->checkIfRecordIsSelectableUsing(
                fn ($record): bool => $record->status !== Status::Processing,
            );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMergedEpgs::route('/'),
            // 'edit' => EditMergedEpg::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Hidden::make('is_merged')
                ->default(true)
                ->dehydrated(true),
            Hidden::make('source_type')
                ->default(EpgSourceType::URL->value)
                ->dehydrated(true),

            TextInput::make('name')
                ->columnSpan(1)
                ->required()
                ->helperText('Enter the name of the merged EPG. Internal use only.')
                ->maxLength(255),

            Select::make('sourceEpgs')
                ->label('Source EPGs')
                ->reorderable()
                ->relationship(
                    name: 'sourceEpgs',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query) => $query
                        ->select(['epgs.id', 'epgs.name'])
                        ->where('user_id', Auth::id())
                        ->where('is_merged', false)
                        ->orderBy('name')
                )
                ->loadStateFromRelationshipsUsing(static function (Select $component, $state): void {
                    if (filled($state)) {
                        return;
                    }

                    $record = $component->getRecord();

                    if (! $record instanceof Epg) {
                        return;
                    }

                    $component->state(
                        $record->sourceEpgs()
                            ->where('epgs.id', '!=', $record->id)
                            ->orderBy('merged_epg_epg.sort_order')
                            ->pluck('epgs.id')
                            ->map(static fn ($id): string => (string) $id)
                            ->values()
                            ->all()
                    );
                })
                ->saveRelationshipsUsing(static function (Select $component, Model $record, $state): void {
                    if (! $record instanceof Epg) {
                        return;
                    }

                    $sourceEpgIds = collect(is_array($state) ? $state : [])
                        ->map(static fn ($id): int => (int) $id)
                        ->filter(static fn (int $id): bool => $id > 0)
                        ->unique()
                        ->values();

                    $syncData = [];

                    foreach ($sourceEpgIds as $index => $sourceEpgId) {
                        $syncData[$sourceEpgId] = ['sort_order' => $index];
                    }

                    $record->sourceEpgs()->sync($syncData);
                })
                ->multiple()
                ->preload()
                ->searchable()
                ->required()
                ->minItems(2)
                ->hintIcon(
                    'heroicon-s-information-circle',
                    tooltip: 'Select 2 or more source EPGs to merge into a single EPG output. Duplicates will be ignored based on channel name and number.',
                )
                ->helperText('Drag to re-order the assigned EPG sources. The first will be given the highest priority.'),

            Section::make('Scheduling')
                ->description('Auto sync and scheduling options')
                ->columns(2)
                ->schema([
                    Toggle::make('auto_sync')
                        ->label('Automatically sync merged EPG')
                        ->helperText('When enabled, the merged EPG will be automatically regenerated at the specified interval.')
                        ->live()
                        ->inline(false)
                        ->default(true),
                    TextInput::make('sync_interval')
                        ->label('Sync Schedule')
                        ->suffix(config('app.timezone'))
                        ->rules([new Cron])
                        ->live()
                        ->default('0 */6 * * *')
                        ->placeholder('0 */6 * * *')
                        ->helperText(fn ($get) => $get('sync_interval') && CronExpression::isValidExpression($get('sync_interval'))
                            ? 'Next scheduled sync: '.(new CronExpression($get('sync_interval')))->getNextRunDate()->format('Y-m-d H:i:s')
                            : 'Specify the CRON schedule for automatic sync, e.g. "0 */6 * * *".')
                        ->hidden(fn (Get $get): bool => ! $get('auto_sync')),
                    DateTimePicker::make('synced')
                        ->columnSpanFull()
                        ->suffix(config('app.timezone'))
                        ->native(false)
                        ->label('Last Synced')
                        ->disabled()
                        ->hiddenOn('create')
                        ->hidden(fn (Get $get, $record): bool => ! $record?->id || ! $get('auto_sync'))
                        ->dehydrated(false),
                ]),
        ];
    }
}
