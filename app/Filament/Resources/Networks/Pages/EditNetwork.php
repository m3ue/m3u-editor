<?php

namespace App\Filament\Resources\Networks\Pages;

use App\Filament\Resources\Networks\NetworkResource;
use App\Models\Network;
use App\Services\NetworkBroadcastService;
use App\Services\NetworkScheduleService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditNetwork extends EditRecord
{
    protected static string $resource = NetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startBroadcast')
                ->label('Start Broadcast')
                ->tooltip('Start continuous HLS broadcasting')
                ->icon('heroicon-s-play')
                ->color('success')
                ->hiddenLabel()
                ->requiresConfirmation()
                ->modalHeading('Start Broadcasting')
                ->modalDescription(function (Network $record): string {
                    $base = 'Start continuous HLS broadcasting for this network. The stream will be available at the network\'s HLS URL.';

                    if ($record->broadcast_schedule_enabled && $record->broadcast_scheduled_start && now()->lt($record->broadcast_scheduled_start)) {
                        return $base."\n\nNote: Broadcast is scheduled to start at ".$record->broadcast_scheduled_start->format('M j, Y H:i:s').' ('.$record->broadcast_scheduled_start->diffForHumans().')';
                    }

                    return $base;
                })
                ->visible(fn (Network $record): bool => $record->broadcast_enabled && ! $record->isBroadcasting())
                ->disabled(fn (Network $record): bool => $record->network_playlist_id === null || $record->programmes()->count() === 0)
                ->tooltip(function (Network $record): ?string {
                    if ($record->network_playlist_id === null) {
                        return 'Assign to a playlist first';
                    }
                    if ($record->programmes()->count() === 0) {
                        return 'Generate schedule first';
                    }

                    return null;
                })
                ->action(function (Network $record) {
                    $service = app(NetworkBroadcastService::class);

                    // Mark as requested so worker will start it when time comes
                    $record->update(['broadcast_requested' => true]);

                    $result = $service->start($record);

                    // Refresh to get updated error message
                    $record->refresh();

                    if ($result) {
                        Notification::make()
                            ->success()
                            ->title('Broadcast Started')
                            ->body("Broadcasting started for {$record->name}")
                            ->send();
                    } elseif ($record->broadcast_schedule_enabled && $record->broadcast_scheduled_start && now()->lt($record->broadcast_scheduled_start)) {
                        Notification::make()
                            ->info()
                            ->title('Broadcast Scheduled')
                            ->body("Broadcast will start at {$record->broadcast_scheduled_start->format('M j, Y H:i:s')} ({$record->broadcast_scheduled_start->diffForHumans()})")
                            ->send();
                    } else {
                        $errorMsg = $record->broadcast_error ?? 'Could not start broadcast. Check that there is content scheduled.';

                        Notification::make()
                            ->danger()
                            ->title('Failed to Start')
                            ->body($errorMsg)
                            ->send();
                    }
                }),

            Action::make('stopBroadcast')
                ->label('Stop Broadcast')
                ->tooltip('Stop the current broadcast')
                ->icon('heroicon-s-stop')
                ->color('danger')
                ->hiddenLabel()
                ->requiresConfirmation()
                ->modalHeading('Stop Broadcasting')
                ->modalDescription('Stop the current broadcast. Viewers will be disconnected.')
                ->visible(fn (Network $record): bool => $record->isBroadcasting())
                ->action(function (Network $record) {
                    $service = app(NetworkBroadcastService::class);
                    $service->stop($record);

                    Notification::make()
                        ->warning()
                        ->title('Broadcast Stopped')
                        ->body("Broadcasting stopped for {$record->name}")
                        ->send();
                }),

            DeleteAction::make(),

            ActionGroup::make([
                Action::make('generateSchedule')
                    ->label('Generate Schedule')
                    ->icon('heroicon-o-calendar')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Generate Schedule')
                    ->modalDescription(fn (): string => 'This will generate a '.($this->record->schedule_window_days ?? 7).'-day programme schedule for this network. Existing future programmes will be replaced.')
                    ->disabled(fn (): bool => $this->record->network_playlist_id === null)
                    ->tooltip(fn (): ?string => $this->record->network_playlist_id === null ? 'Assign to a playlist first' : null)
                    ->action(function () {
                        $service = app(NetworkScheduleService::class);
                        $service->generateSchedule($this->record);

                        Notification::make()
                            ->success()
                            ->title('Schedule Generated')
                            ->body("Generated programme schedule for {$this->record->name}")
                            ->send();

                        $this->refreshFormData(['schedule_generated_at']);
                    }),

                Action::make('viewPlaylist')
                    ->label('View Playlist')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (Network $record): bool => $record->network_playlist_id !== null)
                    ->url(fn (Network $record): string => \App\Filament\Resources\Playlists\PlaylistResource::getUrl('view', ['record' => $record->network_playlist_id])),

            ])->button(),
        ];
    }
}
