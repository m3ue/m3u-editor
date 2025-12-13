<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Jobs\SyncMediaServer;
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
            Action::make('test')
                ->label('Test Connection')
                ->icon('heroicon-o-signal')
                ->color('info')
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

            Action::make('sync')
                ->label('Sync Now')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Sync Media Server')
                ->modalDescription('This will sync all content from the media server. For large libraries, this may take several minutes.')
                ->action(function () {
                    dispatch(new SyncMediaServer($this->record->id));

                    Notification::make()
                        ->success()
                        ->title('Sync Started')
                        ->body("Syncing content from {$this->record->name}. You'll be notified when complete.")
                        ->send();
                }),

            ActionGroup::make([
                Action::make('viewPlaylist')
                    ->label('View Playlist')
                    ->icon('heroicon-o-queue-list')
                    ->url(fn() => $this->record->playlist_id
                        ? route('filament.admin.resources.playlists.edit', $this->record->playlist_id)
                        : null
                    )
                    ->visible(fn() => $this->record->playlist_id !== null),

                DeleteAction::make(),
            ]),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
