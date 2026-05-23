<?php

namespace App\Filament\Resources\DvrRecordings\Pages;

use App\Enums\DvrRecordingStatus;
use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
use App\Jobs\PostProcessDvrRecording;
use App\Jobs\StopDvrRecording;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewDvrRecording extends ViewRecord
{
    protected static string $resource = DvrRecordingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('Back to Recordings'))
                ->url(DvrRecordingResource::getUrl('index'))
                ->icon('heroicon-s-arrow-left')
                ->color('gray'),
            Action::make('retry')
                ->label(__('Retry Post-Processing'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === DvrRecordingStatus::Failed)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => DvrRecordingStatus::PostProcessing, 'error_message' => null]);
                    PostProcessDvrRecording::dispatch($this->record);

                    Notification::make()
                        ->success()
                        ->title(__('Post-processing queued'))
                        ->send();

                    $this->refreshFormData(['status', 'error_message', 'post_processing_step']);
                }),
            Action::make('cancel')
                ->label(__('Cancel Recording'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array(
                    $this->record->status,
                    [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording]
                ))
                ->requiresConfirmation()
                ->action(function (): void {
                    StopDvrRecording::dispatch($this->record->id);

                    Notification::make()
                        ->success()
                        ->title(__('Recording cancellation queued'))
                        ->send();
                }),
            DeleteAction::make()
                ->modalDescription(__('Are you sure you want to delete this recording? The file on disk and any linked VOD entry will also be removed.'))
                ->successRedirectUrl(DvrRecordingResource::getUrl('index')),
        ];
    }
}
