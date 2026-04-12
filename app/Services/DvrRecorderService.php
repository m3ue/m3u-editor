<?php

namespace App\Services;

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DvrRecorderService — Manages FFmpeg child processes for active recordings.
 *
 * Responsibilities:
 * - Spawn ffmpeg in HLS mode for each recording
 * - Monitor process exit (success/failure)
 * - Graceful shutdown: SIGINT → wait → SIGKILL
 * - Crash recovery: mark stale RECORDING rows as FAILED on app boot
 */
class DvrRecorderService
{
    /**
     * Recover from a crash by marking any stale RECORDING rows as FAILED.
     * Should be called once on application boot (e.g. via a queued job or service provider).
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
            'pid' => null,
        ]);
    }

    /**
     * Start recording: spawn ffmpeg in HLS mode, update DB, detach the process.
     *
     * We use `proc_open` so we can track the PID. The process is intentionally
     * left running in the background — `StopDvrRecording` will terminate it.
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

        // Refresh stream URL at start time — IPTV stream URLs expire, so we get
        // a fresh URL from the channel. When proxy is enabled on the source
        // playlist, use getProxyUrl() to get a fresh URL via m3u-proxy.
        // Otherwise fall back to the channel's direct URL.
        $channel = $recording->channel;
        $streamUrl = null;

        if ($channel) {
            $playlist = $setting->playlist;
            if ($playlist && ! empty($playlist->proxy_options['enabled'])) {
                $streamUrl = $channel->getProxyUrl();
            }
        }

        if (empty($streamUrl) && $channel) {
            $streamUrl = $channel->url;
        }

        if (empty($streamUrl)) {
            $streamUrl = $recording->stream_url;
        }

        if (empty($streamUrl)) {
            throw new Exception("Recording {$recording->id} has no stream_url — cannot start");
        }

        // Update stored URL so post-processor/streamer uses the same URL we record from
        if ($streamUrl !== $recording->stream_url) {
            $recording->stream_url = $streamUrl;
            $recording->saveQuietly();
        }

        // 1. Generate temp paths if not already set
        $livePath = $recording->temp_path ?? 'live/'.$recording->uuid;
        $m3u8RelPath = $livePath.'/stream.m3u8';

        // Persist temp paths so post-processor can find them
        $recording->update([
            'temp_path' => $livePath,
            'temp_manifest_path' => $m3u8RelPath,
        ]);

        // 2. Create the HLS output directory on the dvr disk
        Storage::disk($setting->storage_disk ?: config('dvr.storage_disk'))
            ->makeDirectory($livePath);

        $fullLiveDir = Storage::disk($setting->storage_disk ?: config('dvr.storage_disk'))
            ->path($livePath);

        $m3u8Path = $fullLiveDir.'/stream.m3u8';
        $segmentPattern = $fullLiveDir.'/segment_%04d.ts';

        $ffmpegPath = $setting->getFfmpegPath();
        $segmentSeconds = (int) config('dvr.hls_segment_seconds', 6);

        $args = [
            $ffmpegPath,
            '-y',
            '-i', $streamUrl,
            '-c', 'copy',
            '-f', 'hls',
            '-hls_time', (string) $segmentSeconds,
            '-hls_list_size', '0',
            '-hls_flags', 'append_list+omit_endlist',
            '-hls_segment_filename', $segmentPattern,
            $m3u8Path,
        ];

        $cmd = implode(' ', array_map('escapeshellarg', $args));

        // 2. Spawn ffmpeg (stdout/stderr to log file, detached)
        $logFile = $fullLiveDir.'/ffmpeg.log';
        $descriptor = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'w'],
            2 => ['file', $logFile, 'a'],
        ];

        Log::info('DVR: Starting ffmpeg recording', [
            'recording_id' => $recording->id,
            'title' => $recording->title,
            'stream_url' => $streamUrl,
        ]);

        $process = proc_open($cmd, $descriptor, $pipes);

        if (! is_resource($process)) {
            throw new Exception("proc_open failed for recording {$recording->id}");
        }

        $status = proc_get_status($process);
        $pid = $status['pid'] ?? null;

        // 3. Update DB
        $recording->update([
            'status' => DvrRecordingStatus::Recording->value,
            'actual_start' => now(),
            'pid' => $pid,
        ]);

        // Close proc handle (process continues in background)
        proc_close($process);

        Log::info('DVR: Recording started', [
            'recording_id' => $recording->id,
            'pid' => $pid,
            'title' => $recording->title,
        ]);
    }

    /**
     * Stop recording: send SIGINT, wait for graceful exit, fall back to SIGKILL.
     *
     * After stopping, the recording is handed off to DvrPostProcessorService via
     * the PostProcessDvrRecording job.
     */
    public function stop(DvrRecording $recording): void
    {
        $pid = $recording->pid;

        if (! $pid) {
            Log::warning('DVR stop: no PID on recording — assuming already stopped', [
                'recording_id' => $recording->id,
            ]);
            $this->finalizeStop($recording, false);

            return;
        }

        Log::info('DVR: Stopping recording (SIGINT)', [
            'recording_id' => $recording->id,
            'pid' => $pid,
        ]);

        // Send SIGINT for graceful stop
        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGINT);
        } else {
            exec("kill -INT {$pid} 2>/dev/null");
        }

        // Wait up to the configured timeout for the process to exit
        $timeout = (int) config('dvr.graceful_stop_timeout_seconds', 10);
        $waited = 0;
        $exited = false;

        while ($waited < $timeout) {
            sleep(1);
            $waited++;
            if (! $this->isProcessRunning($pid)) {
                $exited = true;
                break;
            }
        }

        if (! $exited) {
            Log::warning('DVR: ffmpeg did not exit after SIGINT — sending SIGKILL', [
                'recording_id' => $recording->id,
                'pid' => $pid,
            ]);

            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGKILL);
            } else {
                exec("kill -9 {$pid} 2>/dev/null");
            }

            sleep(1);
        }

        $this->finalizeStop($recording, $exited);
    }

    /**
     * Cancel a recording (same as stop but marks status as CANCELLED).
     * Note: we do NOT override the status here because cancel() is called from
     * stop() which transitions to PostProcessing. Overriding would break the
     * PostProcessDvrRecording job. The error_message set by stop() is sufficient.
     */
    public function cancel(DvrRecording $recording): void
    {
        $this->stop($recording);

        $recording->update([
            'error_message' => 'Cancelled by user',
        ]);
    }

    /**
     * After ffmpeg exits, transition status to POST_PROCESSING (or FAILED).
     */
    private function finalizeStop(DvrRecording $recording, bool $cleanExit): void
    {
        // Don't overwrite CANCELLED or FAILED status
        if (in_array($recording->status, [
            DvrRecordingStatus::Cancelled,
            DvrRecordingStatus::Failed,
        ])) {
            return;
        }

        $recording->update([
            'status' => DvrRecordingStatus::PostProcessing->value,
            'actual_end' => now(),
            'pid' => null,
            'error_message' => $cleanExit ? null : 'ffmpeg did not exit cleanly',
        ]);
    }

    /**
     * Check if a process with the given PID is still running.
     */
    private function isProcessRunning(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        exec("kill -0 {$pid} 2>/dev/null", $output, $exitCode);

        return $exitCode === 0;
    }
}
