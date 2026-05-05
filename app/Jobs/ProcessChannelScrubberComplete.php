<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessChannelScrubberComplete implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public int $maxLogs = 15;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $scrubberId,
        public int $logId,
        public string $batchNo,
        public Carbon $start,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $scrubber = ChannelScrubber::find($this->scrubberId);
        if (! $scrubber || $scrubber->uuid !== $this->batchNo) {
            ChannelScrubberLog::where('id', $this->logId)->update(['status' => 'cancelled']);

            return;
        }

        if ($scrubber->status === Status::Cancelled) {
            ChannelScrubberLog::where('id', $this->logId)->update(['status' => 'cancelled']);

            return;
        }

        $runtime = round($this->start->diffInSeconds(now()), 2);

        $scrubber->refresh();
        $deadCount = $scrubber->dead_count;
        $channelCount = $scrubber->channel_count;

        // disabled_count and enabled_count are accumulated in-place by each chunk job — do not overwrite.
        ChannelScrubberLog::where('id', $this->logId)->update([
            'status' => 'completed',
            'channel_count' => $channelCount,
            'dead_count' => $deadCount,
            'runtime' => $runtime,
        ]);

        // Trim logs to max 15 entries
        $logsQuery = $scrubber->logs()->orderBy('created_at', 'asc');
        $logCount = $logsQuery->count();
        if ($logCount > $this->maxLogs) {
            $logsQuery->limit($logCount - $this->maxLogs)->delete();
        }

        $scrubber->update([
            'status' => Status::Completed,
            'sync_time' => $runtime,
            'progress' => 100,
            'processing' => false,
            'errors' => null,
        ]);

        Notification::make()
            ->success()
            ->title("Channel Scrubber \"{$scrubber->name}\" completed")
            ->body("Checked {$channelCount} channel(s), found {$deadCount} dead link(s). Completed in {$runtime} seconds.")
            ->broadcast($scrubber->user)
            ->sendToDatabase($scrubber->user);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("Channel scrubber complete job failed: {$exception->getMessage()}");
    }
}
