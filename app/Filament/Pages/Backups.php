<?php

namespace App\Filament\Pages;

use App\Jobs\CreateBackup;
use App\Jobs\RestoreBackup;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackup;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups as BaseBackups;

class Backups extends BaseBackups
{
    protected static string|\BackedEnum|null $navigationIcon = '';

    protected static ?string $navigationLabel = 'Backup & Restore';

    protected ?string $subheading = 'NOTE: Restoring a backup will overwrite any existing data. Your manually uploaded EPG and Playlist files will NOT be restored. You will need to download the backup and manually re-upload where needed.';

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    /**
     * Check if the user can access this page.
     * Only admin users can access the Backups page.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    protected function getActions(): array
    {
        $data = [];
        foreach (FilamentSpatieLaravelBackup::getDisks() as $disk) {
            $data = array_merge($data, FilamentSpatieLaravelBackup::getBackupDestinationData($disk));
        }
        $data = collect($data)->sortByDesc('date');

        return [
            ActionGroup::make([
                Action::make('Restore Backup')
                    ->schema([
                        Select::make('backup')
                            ->required()
                            ->label('Backup file')
                            ->helperText('Select the backup you would like to restore.')
                            ->options($data->pluck('path', 'path'))
                            ->searchable(),
                    ])
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new RestoreBackup($data['backup']));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Backup is being restored')
                            ->body('Backup is being restored in the background. Depending on the size of the backup, this could take a while.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalDescription('NOTE: Only the database will be restored, which will overwrite any existing data with the backup data. Files will not be automatically restored, you will need to manually re-upload them where needed.')
                    ->modalSubmitActionLabel('Restore now'),
                Action::make('Upload Backup')
                    ->schema([
                        FileUpload::make('backup')
                            ->required()
                            ->label('Backup file (.ZIP files only)')
                            ->helperText('Select the backup file you would like to upload.')
                            ->preserveFilenames()
                            ->moveFiles()
                            ->disk('local')
                            ->directory('m3u-editor-backups')
                            ->acceptedFileTypes([
                                'application/x-rar-compressed',
                                'application/zip',
                                'application/x-zip-compressed',
                                'application/x-compressed',
                                'multipart/x-zip',
                            ]),
                    ])
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Backup has been uploaded')
                            ->body('Backup file has been uploaded, you can now restore it if needed.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->modalIcon('heroicon-o-arrow-up-tray')
                    ->modalDescription('NOTE: Only properly formatted backups will be accepted. If the backup is not valid, you will receive an error when attempting to restore.')
                    ->modalSubmitActionLabel('Upload now'),
                Action::make('Create Backup')
                    ->schema([
                        Toggle::make('include_files')
                            ->label('Include Files')
                            ->helperText('When enabled, the backup will include your uploaded Playlist and EPG files.'),
                    ])
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new CreateBackup($data['include_files'] ?? false));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Backup is being created')
                            ->body('Backup is being created in the background. Depending on the size of your database and files, this could take a while.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('primary')
                    ->modalIcon('heroicon-o-archive-box-arrow-down')
                    ->modalDescription('NOTE: When restoring a backup, only the database will be restored, files will not be automatically restored. You will need to manually re-upload them where needed.')
                    ->modalSubmitActionLabel('Create now'),
            ])->button()->label('Actions'),
        ];
    }

    public function getHeading(): string|Htmlable
    {
        return 'Manage Backups';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Tools';
    }

    public function shouldDisplayStatusListRecords(): bool
    {
        return false;
    }
}
