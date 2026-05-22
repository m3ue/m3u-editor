<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Jobs\SyncMediaServer;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Season;
use App\Models\Series;
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
                    ->label(__('Sync Now'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading(__('Sync Media Server'))
                    ->modalDescription(__('This will sync all content from the media server. For large libraries, this may take several minutes.'))
                    ->action(function () {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncMediaServer($this->record->id));

                        Notification::make()
                            ->success()
                            ->title(__('Sync Started'))
                            ->body("Syncing content from {$this->record->name}. You'll be notified when complete.")
                            ->send();
                    }),

                Action::make('test')
                    ->label(__('Test Connection'))
                    ->icon('heroicon-o-signal')
                    ->action(function () {
                        $service = MediaServerService::make($this->record);
                        $result = $service->testConnection();

                        if ($result['success']) {
                            Notification::make()
                                ->success()
                                ->title(__('Connection Successful'))
                                ->body("Connected to {$result['server_name']} (v{$result['version']})")
                                ->send();
                        } else {
                            Notification::make()
                                ->danger()
                                ->title(__('Connection Failed'))
                                ->body($result['message'])
                                ->send();
                        }
                    }),

                Action::make('viewPlaylist')
                    ->label(__('View Playlist'))
                    ->icon('heroicon-o-eye')
                    ->url(fn () => $this->record->playlist_id
                        ? PlaylistResource::getUrl('view', ['record' => $this->record->playlist_id])
                        : null
                    )
                    ->visible(fn () => $this->record->playlist_id !== null),

                Action::make('cleanupDuplicates')
                    ->label(__('Cleanup Duplicates'))
                    ->icon('heroicon-o-trash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('Cleanup Duplicate Series'))
                    ->modalDescription(__('This will find and merge duplicate series entries that were created due to sync format changes. Duplicate series without episodes will be removed, and their seasons will be merged into the series that has episodes.'))
                    ->action(function (MediaServerIntegration $record) {
                        $result = MediaServerIntegrationResource::cleanupDuplicateSeries($record);

                        if ($result['duplicates'] === 0) {
                            Notification::make()
                                ->info()
                                ->title(__('No Duplicates Found'))
                                ->body(__('No duplicate series were found for this media server.'))
                                ->send();
                        } else {
                            Notification::make()
                                ->success()
                                ->title(__('Cleanup Complete'))
                                ->body("Merged {$result['duplicates']} duplicate series and deleted {$result['deleted']} orphaned entries.")
                                ->send();
                        }
                    })
                    ->visible(fn ($record) => $record->playlist_id !== null),

                Action::make('flushLibrary')
                    ->label(__('Flush Library'))
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Flush Library'))
                    ->modalDescription(__('This will permanently delete ALL movies, series, episodes, seasons, and categories from this integration\'s playlist, then start a fresh sync. This cannot be undone.'))
                    ->modalSubmitActionLabel(__('Yes, flush and re-sync'))
                    ->action(function () {
                        $record = $this->record;
                        $playlist = $record->playlist;

                        if ($playlist) {
                            Episode::where('playlist_id', $playlist->id)->delete();
                            Season::where('playlist_id', $playlist->id)->delete();
                            Series::where('playlist_id', $playlist->id)->delete();
                            Category::where('playlist_id', $playlist->id)->delete();
                            Channel::where('playlist_id', $playlist->id)->where('is_custom', false)->delete();
                            Group::where('playlist_id', $playlist->id)->where('custom', false)->delete();
                        }

                        $record->update([
                            'status' => 'idle',
                            'progress' => 0,
                            'movie_progress' => 0,
                            'series_progress' => 0,
                            'total_movies' => 0,
                            'total_series' => 0,
                            'sync_stats' => null,
                        ]);

                        SyncMediaServer::dispatch($record->id);

                        Notification::make()
                            ->success()
                            ->title(__('Library Flushed'))
                            ->body(__('All library content cleared. A fresh sync has been queued.'))
                            ->send();
                    })
                    ->visible(fn () => $this->record->playlist_id !== null),

                Action::make('reset')
                    ->label(__('Reset Status'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription(__('Reset media server status so it can be synced again. Only perform this action if you are having problems with the media server syncing.'))
                    ->modalSubmitActionLabel(__('Yes, reset now'))
                    ->action(function () {
                        $this->record->update([
                            'status' => 'idle',
                            'progress' => 0,
                            'movie_progress' => 0,
                            'series_progress' => 0,
                            'total_movies' => 0,
                            'total_series' => 0,
                        ]);

                        Notification::make()
                            ->success()
                            ->title(__('Status Reset'))
                            ->body(__('Media server status has been reset.'))
                            ->send();
                    }),

                DeleteAction::make(),
            ])->button(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
