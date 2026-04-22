<?php

namespace App\Services;

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use Exception;
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

        // Always route through the proxy — it handles stream URL refresh, reconnects,
        // and concurrent viewer access via its pooled stream feature.
        $channel = $recording->channel;
        $streamUrl = $channel?->getProxyUrl() ?? $recording->stream_url;

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
    }

    /**
     * Stop recording by signalling the proxy to terminate the broadcast.
     *
     * The proxy handles graceful FFmpeg shutdown and will call back the editor
     * via the dvr.callback route when done — PostProcessDvrRecording is dispatched
     * from the callback, not here.
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
        $this->stop($recording);

        $recording->update([
            'status' => DvrRecordingStatus::Cancelled->value,
            'error_message' => 'Cancelled by user',
        ]);
    }

    /**
     * Transition a stopped recording to POST_PROCESSING.
     * Called after the proxy broadcast has been signalled to stop.
     * The proxy callback will trigger the actual post-processing job.
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
            'proxy_network_id' => null,
        ]);
    }
}
