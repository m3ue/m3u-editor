<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessChannelScrubber implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public $timeout = 60 * 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $channelScrubberId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $start = now();
        $batchNo = Str::orderedUuid()->toString();

        $scrubber = ChannelScrubber::find($this->channelScrubberId);
        if (! $scrubber) {
            return;
        }

        $scrubber->update([
            'uuid' => $batchNo,
            'progress' => 0,
            'status' => Status::Processing,
            'processing' => true,
            'last_run_at' => now(),
            'errors' => null,
        ]);

        try {
            $playlist = $scrubber->playlist;
            if (! $playlist) {
                $error = 'No playlist associated with this scrubber.';
                Log::error("Channel scrubber #{$scrubber->id}: {$error}");
                $scrubber->update([
                    'status' => Status::Failed,
                    'errors' => $error,
                    'progress' => 100,
                    'processing' => false,
                ]);

                return;
            }

            $query = $playlist->channels()
                ->when(! $scrubber->include_vod, fn ($q) => $q->where('is_vod', false))
                ->when(! $scrubber->scan_all, fn ($q) => $q->where('enabled', true));

            $channelCount = $query->count();

            $scrubber->update([
                'channel_count' => $channelCount,
                'dead_count' => 0,
                'progress' => 3,
            ]);

            $log = ChannelScrubberLog::create([
                'channel_scrubber_id' => $scrubber->id,
                'user_id' => $scrubber->user_id,
                'playlist_id' => $scrubber->playlist_id,
                'status' => 'processing',
                'channel_count' => $channelCount,
            ]);

            Notification::make()
                ->info()
                ->title("Channel Scrubber \"{$scrubber->name}\" started")
                ->body("Scanning {$channelCount} channel(s). You will be notified when complete.")
                ->broadcast($scrubber->user)
                ->sendToDatabase($scrubber->user);

            $channelIds = $query->pluck('id')->toArray();

            $chunkJobs = collect(array_chunk($channelIds, 50))
                ->map(fn (array $chunk) => new ProcessChannelScrubberChunk(
                    channelIds: $chunk,
                    scrubberId: $scrubber->id,
                    logId: $log->id,
                    checkMethod: $scrubber->check_method,
                    batchNo: $batchNo,
                    totalChannels: $channelCount,
                    probeTimeout: $scrubber->probe_timeout ?? 10,
                    disableDead: $scrubber->disable_dead ?? true,
                    enableLive: $scrubber->enable_live ?? false,
                ))
                ->all();

            if ($scrubber->use_batching) {
                $this->dispatchAsBatch($chunkJobs, $scrubber, $log->id, $batchNo, $start);
            } else {
                $this->dispatchAsChain($chunkJobs, $scrubber, $log->id, $batchNo, $start);
            }
        } catch (Exception $e) {
            Log::error("Error processing channel scrubber #{$scrubber->id}: {$e->getMessage()}");

            Notification::make()
                ->danger()
                ->title("Channel Scrubber \"{$scrubber->name}\" failed")
                ->body('Please view your notifications for details.')
                ->broadcast($scrubber->user);
            Notification::make()
                ->danger()
                ->title("Channel Scrubber \"{$scrubber->name}\" failed")
                ->body($e->getMessage())
                ->sendToDatabase($scrubber->user);

            $scrubber->update([
                'status' => Status::Failed,
                'errors' => $e->getMessage(),
                'progress' => 100,
                'processing' => false,
            ]);
        }
    }

    /**
     * Dispatch chunk jobs as a Bus::batch() so all chunks run in parallel across
     * available Horizon workers. ProcessChannelScrubberComplete is dispatched via
     * the batch's then() callback once every chunk has finished.
     *
     * @param  array<ProcessChannelScrubberChunk>  $chunkJobs
     */
    private function dispatchAsBatch(
        array $chunkJobs,
        ChannelScrubber $scrubber,
        int $logId,
        string $batchNo,
        Carbon $start,
    ): void {
        $scrubberId = $scrubber->id;

        Bus::batch($chunkJobs)
            ->then(function () use ($scrubberId, $logId, $batchNo, $start) {
                dispatch(new ProcessChannelScrubberComplete(
                    scrubberId: $scrubberId,
                    logId: $logId,
                    batchNo: $batchNo,
                    start: $start,
                ));
            })
            ->catch(fn (Batch $batch, Throwable $e) => self::handleScrubberFailure($scrubberId, $e))
            ->onConnection('redis')
            ->onQueue('import')
            ->allowFailures()
            ->dispatch();
    }

    /**
     * Dispatch chunk jobs as a Bus::chain() so chunks run one after another,
     * with ProcessChannelScrubberComplete appended as the final step.
     *
     * @param  array<ProcessChannelScrubberChunk>  $chunkJobs
     */
    private function dispatchAsChain(
        array $chunkJobs,
        ChannelScrubber $scrubber,
        int $logId,
        string $batchNo,
        Carbon $start,
    ): void {
        $scrubberId = $scrubber->id;

        Bus::chain([
            ...$chunkJobs,
            new ProcessChannelScrubberComplete(
                scrubberId: $scrubberId,
                logId: $logId,
                batchNo: $batchNo,
                start: $start,
            ),
        ])
            ->onConnection('redis')
            ->onQueue('import')
            ->catch(fn (Throwable $e) => self::handleScrubberFailure($scrubberId, $e))
            ->dispatch();
    }

    /**
     * Notify the scrubber owner of a failure and mark the scrubber as failed.
     * Safe for use inside closures where $this cannot be serialized.
     */
    private static function handleScrubberFailure(int $scrubberId, Throwable $e): void
    {
        $scrubber = ChannelScrubber::find($scrubberId);
        if (! $scrubber) {
            return;
        }

        $error = "Error running scrubber \"{$scrubber->name}\": {$e->getMessage()}";

        Notification::make()
            ->danger()
            ->title("Channel Scrubber \"{$scrubber->name}\" failed")
            ->body('Please view your notifications for details.')
            ->broadcast($scrubber->user);

        Notification::make()
            ->danger()
            ->title("Channel Scrubber \"{$scrubber->name}\" failed")
            ->body($error)
            ->sendToDatabase($scrubber->user);

        $scrubber->update([
            'status' => Status::Failed,
            'errors' => $error,
            'progress' => 100,
            'processing' => false,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("Channel scrubber job failed: {$exception->getMessage()}");
    }
}
