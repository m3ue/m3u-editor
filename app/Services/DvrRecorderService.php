<?php

namespace App\Services;

use App\Enums\DvrRecordingStatus;
use App\Jobs\PostProcessDvrRecording;
use App\Models\DvrRecording;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * DvrRecorderService — Delegates FFmpeg process management to the m3u-proxy.
 *
 * Responsibilities:
 * - Start a DVR broadcast on the proxy (via BroadcastManager)
 * - Stop a running broadcast via the proxy API
 * - Recover stale RECORDING rows on app boot
 *
 * The proxy handles all FFmpeg lifecycle, HLS segment preservation (dvr_mode),
 * hardware acceleration, and post-recording callbacks. No PIDs or sleep loops here.
 */
class DvrRecorderService
{
    public function __construct(protected M3uProxyService $proxy) {}

    /**
     * Recover from a crash by marking any stale RECORDING rows as FAILED.
     * Called once on application boot.
     */
    public function recoverFromCrash(): void
    {
        $stale = DvrRecording::recording()->get();

        if ($stale->isEmpty()) {
            return;
        }

        Log::warning('DVR crash recovery: marking stale RECORDING entries as FAILED', [
            'count' => $stale->count(),
        ]);

        DvrRecording::recording()->update([
            'status' => DvrRecordingStatus::Failed->value,
            'error_message' => 'Server restarted during recording',
            'proxy_network_id' => null,
        ]);
    }

    /**
     * Start recording by launching a DVR broadcast on the proxy.
     *
     * The proxy manages the FFmpeg process, preserves all HLS segments (dvr_mode=true),
     * and calls back the editor when the recording ends or fails.
     */
    public function start(DvrRecording $recording): void
    {
        if ($recording->status !== DvrRecordingStatus::Scheduled) {
            Log::warning('DVR start skipped — recording not in SCHEDULED state', [
                'recording_id' => $recording->id,
                'status' => $recording->status->value,
            ]);

            return;
        }

        $setting = $recording->dvrSetting;
        if (! $setting) {
            throw new Exception("DvrSetting not found for recording {$recording->id}");
        }

        // Use the raw source URL so the proxy connects directly to the provider.
        // getProxyUrl() would generate an editor-routed URL (/live/…?proxy=true) that
        // loops back through XtreamStreamController → m3u-proxy pooled stream, causing
        // double-proxying. The DVR broadcast already runs inside the proxy, which handles
        // its own reconnects and buffering natively.
        $channel = $recording->channel;
        if ($channel) {
            $streamUrl = $channel->url_custom ?? $channel->url ?? $recording->stream_url;
        } else {
            $streamUrl = $recording->stream_url;
        }

        if (empty($streamUrl)) {
            throw new Exception("Recording {$recording->id} has no stream_url — cannot start");
        }

        if ($streamUrl !== $recording->stream_url) {
            $recording->stream_url = $streamUrl;
            $recording->saveQuietly();
        }

        Log::info('DVR: Starting proxy broadcast', [
            'recording_id' => $recording->id,
            'title' => $recording->title,
            'stream_url' => $streamUrl,
        ]);

        $networkId = $this->proxy->startDvrBroadcast($recording, $setting, $streamUrl);

        $recording->update([
            'status' => DvrRecordingStatus::Recording->value,
            'actual_start' => now(),
            'proxy_network_id' => $networkId,
        ]);

        Log::info('DVR: Proxy broadcast started', [
            'recording_id' => $recording->id,
            'proxy_network_id' => $networkId,
            'title' => $recording->title,
        ]);

        if ($user = $recording->user) {
            Notification::make()
                ->success()
                ->title('Recording Started')
                ->body($recording->title)
                ->broadcast($user)
                ->sendToDatabase($user);
        }
    }

    /**
     * Stop recording by signalling the proxy to terminate the broadcast.
     *
     * The proxy retains segment files after /stop, so post-processing can fetch
     * them via HTTP afterward. We do NOT clear proxy_network_id here — the
     * downloader needs it. It is cleared after successful cleanup in post-processing.
     */
    public function stop(DvrRecording $recording): void
    {
        $networkId = $recording->proxy_network_id;

        if (! $networkId) {
            Log::warning('DVR stop: no proxy_network_id on recording — assuming already stopped', [
                'recording_id' => $recording->id,
            ]);
            $this->finalizeStop($recording);

            return;
        }

        Log::info('DVR: Stopping proxy broadcast', [
            'recording_id' => $recording->id,
            'proxy_network_id' => $networkId,
        ]);

        $this->proxy->stopDvrBroadcast($networkId);

        $this->finalizeStop($recording);
    }

    /**
     * Cancel a recording — stops the proxy broadcast and marks as cancelled.
     */
    public function cancel(DvrRecording $recording): void
    {
        $networkId = $recording->proxy_network_id;

        if ($networkId) {
            $this->proxy->stopDvrBroadcast($networkId);
            // Cancelled recordings won't be post-processed, so cleanup proxy files now.
            $this->proxy->cleanupDvrBroadcast($networkId);
        }

        $recording->update([
            'status' => DvrRecordingStatus::Cancelled->value,
            'actual_end' => now(),
            'proxy_network_id' => null,
            'error_message' => 'Cancelled by user',
        ]);
    }

    /**
     * Transition a stopped recording to POST_PROCESSING and dispatch the concat job.
     *
     * proxy_network_id is intentionally preserved through post-processing so the
     * HLS downloader can fetch segments from the proxy. It will be cleared after
     * successful cleanup at the end of post-processing.
     *
     * FFmpeg may still be flushing its final segment for a few seconds after the stop
     * signal. We delay the job by 10 seconds to give it time to finish writing.
     */
    private function finalizeStop(DvrRecording $recording): void
    {
        if (in_array($recording->status, [
            DvrRecordingStatus::Cancelled,
            DvrRecordingStatus::Failed,
        ])) {
            return;
        }

        $recording->update([
            'status' => DvrRecordingStatus::PostProcessing->value,
            'actual_end' => now(),
        ]);

        // Delay 10 s so FFmpeg finishes flushing the final HLS segment before we
        // try to read the manifest. The proxy callback (if it arrives) will be a
        // no-op because DvrCallbackController skips Completed/Failed recordings.
        PostProcessDvrRecording::dispatch($recording->id)
            ->onQueue('dvr-post')
            ->delay(now()->addSeconds(10));

        Log::info('DVR: Post-processing queued', [
            'recording_id' => $recording->id,
            'proxy_network_id' => $recording->proxy_network_id,
        ]);
    }
}
