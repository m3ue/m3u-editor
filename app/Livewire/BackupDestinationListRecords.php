<?php

namespace App\Livewire;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackup;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination as SpatieBackupDestination;

class BackupDestinationListRecords extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    /**
     * @var array<int|string, array<string, string>|string>
     */
    protected $queryString = [
        'tableSortColumn',
        'tableSortDirection',
        'tableSearchQuery' => ['except' => ''],
    ];

    public function render(): View
    {
        return view('vendor.filament-spatie-backup.components.backup-destination-list-records');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(
                function (?string $sortColumn, ?string $sortDirection, ?string $search) {
                    $data = [];

                    foreach (FilamentSpatieLaravelBackup::getDisks() as $disk) {
                        $data = array_merge($data, FilamentSpatieLaravelBackup::getBackupDestinationData($disk));
                    }

                    return collect($data)
                        ->when(
                            filled($sortColumn),
                            fn ($data) => $data->sortBy(
                                $sortColumn,
                                SORT_NATURAL,
                                $sortDirection === 'desc',
                            ),
                        )
                        ->when(
                            filled($search),
                            fn ($data) => $data->filter(
                                fn (array $record): bool => Str::contains(
                                    Str::lower($record['path'].$record['disk'].$record['date']),
                                    Str::lower($search),
                                ),
                            ),
                        );
                }
            )
            ->columns([
                TextColumn::make('path')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.fields.path'))
                    ->searchable()
                    ->sortable(),
                // TextColumn::make('disk')
                //     ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.fields.disk'))
                //     ->searchable()
                //     ->sortable(),
                TextColumn::make('date')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.fields.date'))
                    ->dateTime()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('size')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.fields.size'))
                    ->badge(),
            ])
            ->filters([
                // Tables\Filters\SelectFilter::make('disk')
                //     ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.filters.disk'))
                //     ->options(FilamentSpatieLaravelBackup::getFilterDisks()),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.actions.delete'))
                    ->icon('heroicon-o-trash')
                    ->visible(auth()->user()->can('delete-backup'))
                    ->requiresConfirmation()
                    ->color('danger')
                    ->modalIcon('heroicon-o-trash')
                    ->button()->hiddenLabel()->size('sm')
                    ->action(function (array $record) {
                        SpatieBackupDestination::create($record['disk'], config('backup.backup.name'))
                            ->backups()
                            ->first(function (Backup $backup) use ($record) {
                                return $backup->path() === $record['path'];
                            })
                            ->delete();

                        Notification::make()
                            ->title(__('filament-spatie-backup::backup.pages.backups.messages.backup_delete_success'))
                            ->success()
                            ->send();
                    }),
                Action::make('download')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.actions.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(auth()->user()->can('download-backup'))
                    ->button()->hiddenLabel()->size('sm')
                    ->action(fn (array $record) => Storage::disk($record['disk'])->download($record['path'])),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('delete')
                        ->label('Delete selected')
                        ->action(function ($records): void {
                            foreach ($records as $record) {
                                SpatieBackupDestination::create($record['disk'], config('backup.backup.name'))
                                    ->backups()
                                    ->first(function (Backup $backup) use ($record) {
                                        return $backup->path() === $record['path'];
                                    })
                                    ->delete();
                            }
                        })->after(function () {
                            Notification::make()
                                ->title('Selected backups deleted successfully')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->modalIcon('heroicon-o-trash')
                        ->modalDescription('Delete the selected backup(s) now?')
                        ->modalSubmitActionLabel('Yes, delete now'),
                ]),
            ]);
    }

    #[Computed]
    public function interval(): string
    {
        /** @var FilamentSpatieLaravelBackupPlugin $plugin */
        $plugin = filament()->getPlugin('filament-spatie-backup');

        return $plugin->getPolingInterval();
    }
}
