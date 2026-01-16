<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Services\MediaServerService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMediaServerIntegration extends EditRecord
{
    protected static string $resource = MediaServerIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('sync')
                    ->disabled(fn ($record) => $record->status === 'processing')
                    ->label('Sync Now')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading('Sync Media Server')
                    ->modalDescription('This will sync all content from the media server. For large libraries, this may take several minutes.')
                    ->action(function () {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncMediaServer($this->record->id));

                        Notification::make()
                            ->success()
                            ->title('Sync Started')
                            ->body("Syncing content from {$this->record->name}. You'll be notified when complete.")
                            ->send();
                    }),

                Action::make('test')
                    ->label('Test Connection')
                    ->icon('heroicon-o-signal')
                    ->action(function () {
                        $service = MediaServerService::make($this->record);
                        $result = $service->testConnection();

                        if ($result['success']) {
                            Notification::make()
                                ->success()
                                ->title('Connection Successful')
                                ->body("Connected to {$result['server_name']} (v{$result['version']})")
                                ->send();
                        } else {
                            Notification::make()
                                ->danger()
                                ->title('Connection Failed')
                                ->body($result['message'])
                                ->send();
                        }
                    }),

                Action::make('viewPlaylist')
                    ->label('View Playlist')
                    ->icon('heroicon-o-queue-list')
                    ->url(fn () => $this->record->playlist_id
                        ? route('filament.admin.resources.playlists.edit', $this->record->playlist_id)
                        : null
                    )
                    ->visible(fn () => $this->record->playlist_id !== null),

                Action::make('cleanupDuplicates')
                    ->label('Cleanup Duplicates')
                    ->icon('heroicon-o-trash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Cleanup Duplicate Series')
                    ->modalDescription('This will find and merge duplicate series entries that were created due to sync format changes. Duplicate series without episodes will be removed, and their seasons will be merged into the series that has episodes.')
                    ->action(function (MediaServerIntegration $record) {
                        $result = MediaServerIntegrationResource::cleanupDuplicateSeries($record);

                        if ($result['duplicates'] === 0) {
                            Notification::make()
                                ->info()
                                ->title('No Duplicates Found')
                                ->body('No duplicate series were found for this media server.')
                                ->send();
                        } else {
                            Notification::make()
                                ->success()
                                ->title('Cleanup Complete')
                                ->body("Merged {$result['duplicates']} duplicate series and deleted {$result['deleted']} orphaned entries.")
                                ->send();
                        }
                    })
                    ->visible(fn ($record) => $record->playlist_id !== null),

                DeleteAction::make(),
            ])->button(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
