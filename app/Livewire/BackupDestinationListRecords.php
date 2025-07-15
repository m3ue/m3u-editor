<?php

namespace App\Livewire;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackup;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;
use ShuvroRoy\FilamentSpatieLaravelBackup\Models\BackupDestination;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination as SpatieBackupDestination;

class BackupDestinationListRecords extends Component implements HasForms, HasTable
{
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
            ->query(BackupDestination::query())
            ->columns([
                Tables\Columns\TextColumn::make('path')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.fields.path'))
                    ->searchable()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('disk')
                //     ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.fields.disk'))
                //     ->searchable()
                //     ->sortable(),
                Tables\Columns\TextColumn::make('date')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.fields.date'))
                    ->dateTime()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('size')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.fields.size'))
                    ->badge(),
            ])
            ->filters([
                // Tables\Filters\SelectFilter::make('disk')
                //     ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.filters.disk'))
                //     ->options(FilamentSpatieLaravelBackup::getFilterDisks()),
            ])
            ->actions([
                Tables\Actions\Action::make('delete')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.actions.delete'))
                    ->icon('heroicon-o-trash')
                    ->visible(auth()->user()->can('delete-backup'))
                    ->requiresConfirmation()
                    ->color('danger')
                    ->modalIcon('heroicon-o-trash')
                    ->button()->hiddenLabel()->size('sm')
                    ->action(function (BackupDestination $record) {
                        SpatieBackupDestination::create($record->disk, config('backup.backup.name'))
                            ->backups()
                            ->first(function (Backup $backup) use ($record) {
                                return $backup->path() === $record->path;
                            })
                            ->delete();

                        Notification::make()
                            ->title(__('filament-spatie-backup::backup.pages.backups.messages.backup_delete_success'))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('download')
                    ->label(__('filament-spatie-backup::backup.components.backup_destination_list.table.actions.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(auth()->user()->can('download-backup'))
                    ->button()->hiddenLabel()->size('sm')
                    ->action(fn(BackupDestination $record) => Storage::disk($record->disk)->download($record->path)),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('delete')
                        ->label('Delete selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                SpatieBackupDestination::create($record->disk, config('backup.backup.name'))
                                    ->backups()
                                    ->first(function (Backup $backup) use ($record) {
                                        return $backup->path() === $record->path;
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
