<?php

namespace App\Jobs;

use App\Models\DvrRecording;
use App\Services\DvrPostProcessorService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessComskipOnRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly int $recordingId)
    {
        $this->onQueue('dvr-post');
    }

    public function handle(DvrPostProcessorService $postProcessor): void
    {
        $recording = DvrRecording::with(['dvrSetting', 'user'])->find($this->recordingId);

        if (! $recording) {
            Log::warning("ProcessComskipOnRecording: recording {$this->recordingId} not found");

            return;
        }

        $postProcessor->runComskipOnRecording($recording);

        if ($user = $recording->user) {
            Notification::make()
                ->success()
                ->title('Comskip reprocessing complete')
                ->body($recording->display_title ?? $recording->title)
                ->broadcast($user)
                ->sendToDatabase($user);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProcessComskipOnRecording: all retries exhausted for recording {$this->recordingId}", [
            'exception' => $exception->getMessage(),
        ]);

        $recording = DvrRecording::with('user')->find($this->recordingId);

        if ($user = $recording?->user) {
            Notification::make()
                ->danger()
                ->title('Comskip reprocessing failed')
                ->body($recording->display_title ?? $recording->title)
                ->broadcast($user)
                ->sendToDatabase($user);
        }
    }
}
