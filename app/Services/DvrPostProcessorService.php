<?php

namespace App\Services;

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Jobs\EnrichDvrMetadata;
use App\Jobs\IntegrateDvrRecordingToVod;
use App\Models\DvrRecording;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DvrPostProcessorService — Runs after ffmpeg stops.
 *
 * Pipeline:
 *   Step 1 (FATAL)     — Concatenate HLS segments → single .ts file via ffmpeg
 *   Step 2 (non-fatal) — Move file to final library path, update DB
 *   Step 3 (non-fatal) — Dispatch EnrichDvrMetadata job
 *   Step 4             — Cleanup live/ temp dir, update DB to COMPLETED
 */
class DvrPostProcessorService
{
    /**
     * Run the full post-processing pipeline for a recording.
     */
    public function run(DvrRecording $recording): void
    {
        Log::info("DVR post-processing started for recording {$recording->id}", [
            'title' => $recording->title,
        ]);

        if ($recording->status !== DvrRecordingStatus::PostProcessing) {
            Log::warning("DVR post-processor: recording {$recording->id} not in POST_PROCESSING state — skipping", [
                'status' => $recording->status->value,
            ]);

            return;
        }

        $setting = $recording->dvrSetting;
        if (! $setting) {
            $this->markFailed($recording, 'DvrSetting not found during post-processing');

            return;
        }

        $disk = $setting->storage_disk ?: config('dvr.storage_disk');
        $livePath = $recording->temp_path; // e.g. live/{uuid}
        $m3u8RelPath = $recording->temp_manifest_path; // e.g. live/{uuid}/stream.m3u8

        $m3u8FullPath = Storage::disk($disk)->path($m3u8RelPath);

        if (! file_exists($m3u8FullPath)) {
            // FFmpeg writes to stream.m3u8.tmp while recording; if we caught it mid-segment,
            // only the .tmp file may exist. Try that before giving up.
            $tmpPath = preg_replace('/\.m3u8$/', '.m3u8.tmp', $m3u8FullPath);
            if (file_exists($tmpPath)) {
                Log::warning("DVR: Finalized .m3u8 not found, using .m3u8.tmp for recording {$recording->id}");
                $m3u8FullPath = $tmpPath;
            } else {
                $this->markFailed($recording, "HLS manifest not found at {$m3u8FullPath}");

                return;
            }
        }

        // ── Step 1: Concatenate HLS → single .ts ────────────────────────────
        $outputRelPath = $this->buildOutputPath($recording);
        $outputFullPath = Storage::disk($disk)->path($outputRelPath);
        $outputDir = dirname($outputFullPath);

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $ffmpegPath = $setting->getFfmpegPath();

        try {
            $this->concatHls($ffmpegPath, $m3u8FullPath, $outputFullPath);
        } catch (Exception $e) {
            $this->markFailed($recording, "HLS concat failed: {$e->getMessage()}");

            return;
        }

        $fileSize = file_exists($outputFullPath) ? filesize($outputFullPath) : null;
        $duration = $this->estimateDuration($recording);

        Log::info('DVR post-processing step 1 complete: HLS concatenated', [
            'recording_id' => $recording->id,
            'output_path' => $outputRelPath,
            'file_size' => $fileSize,
        ]);

        // ── Step 2: Update DB with file location ────────────────────────────
        try {
            $recording->update([
                'file_path' => $outputRelPath,
                'file_size_bytes' => $fileSize,
                'duration_seconds' => $duration,
            ]);
        } catch (Exception $e) {
            Log::warning("DVR post-processing step 2 failed: could not update file_path: {$e->getMessage()}");
        }

        // ── Step 3: Dispatch metadata enrichment (non-fatal) ────────────────
        try {
            if ($setting->enable_metadata_enrichment) {
                EnrichDvrMetadata::dispatch($recording->id)->onQueue('dvr-meta');
            } else {
                // Metadata enrichment disabled — integrate directly without metadata
                IntegrateDvrRecordingToVod::dispatch($recording->id)->onQueue('dvr-post');
            }
        } catch (Exception $e) {
            Log::warning("DVR post-processing step 3: could not dispatch metadata enrichment: {$e->getMessage()}");
        }

        // ── Step 4: Cleanup temp dir + mark COMPLETED ───────────────────────
        try {
            Storage::disk($disk)->deleteDirectory($livePath);
        } catch (Exception $e) {
            Log::warning("DVR post-processing step 4: could not delete temp dir {$livePath}: {$e->getMessage()}");
        }

        $recording->update([
            'status' => DvrRecordingStatus::Completed->value,
        ]);

        // Disable "once" rules after a successful recording so they don't re-schedule
        $rule = $recording->recordingRule;
        if ($rule && $rule->type === DvrRuleType::Once) {
            $rule->update(['enabled' => false]);
        }

        Log::info("DVR recording completed: {$recording->title}", [
            'recording_id' => $recording->id,
            'file_path' => $outputRelPath,
        ]);
    }

    /**
     * Build the final output path for the recording (relative to the dvr disk).
     *
     * Pattern: library/{Year}/{Title}/Title - SXXEYY.ts
     * or:       library/{Year}/{Title}/Title - YYYY-MM-DD.ts
     */
    public function buildOutputPath(DvrRecording $recording): string
    {
        $title = $this->sanitizeFileName($recording->title);
        $year = ($recording->programme_start ?? $recording->scheduled_start)?->format('Y') ?? now()->format('Y');

        if ($recording->season !== null && $recording->episode !== null) {
            $episode = sprintf('%s - S%02dE%02d', $title, $recording->season, $recording->episode);
        } else {
            $date = ($recording->programme_start ?? $recording->scheduled_start)?->format('Y-m-d') ?? now()->format('Y-m-d');
            $episode = "{$title} - {$date}";
        }

        return "library/{$year}/{$title}/{$episode}.ts";
    }

    /**
     * Concatenate HLS segments into a single .ts file using ffmpeg's concat demuxer.
     *
     * We build an explicit file list from the .m3u8 manifest and pass it via the
     * concat demuxer (-f concat) rather than feeding the .m3u8 directly as input.
     * This avoids issues with corrupt/bogus #EXTINF durations in the manifest that
     * can cause ffmpeg to treat a segment as hours long and stall at near-zero speed.
     */
    private function concatHls(string $ffmpegPath, string $m3u8Path, string $outputPath): void
    {
        $segmentDir = dirname($m3u8Path);
        $concatListPath = $segmentDir.'/concat-list.txt';
        $logFile = $segmentDir.'/ffmpeg-concat.log';

        // Parse segment filenames from the .m3u8 manifest (lines not starting with #)
        $segments = array_filter(
            array_map('trim', file($m3u8Path)),
            fn (string $line) => $line !== '' && ! str_starts_with($line, '#')
        );

        if (empty($segments)) {
            throw new Exception('No segments found in HLS manifest');
        }

        // Write the ffmpeg concat list file
        $listContent = implode("\n", array_map(
            fn (string $seg) => "file '".addslashes("{$segmentDir}/{$seg}")."'",
            $segments
        ));
        file_put_contents($concatListPath, $listContent);

        $args = [
            $ffmpegPath,
            '-y',
            '-f', 'concat',
            '-safe', '0',
            '-i', $concatListPath,
            '-c', 'copy',
            '-f', 'mpegts',
            $outputPath,
        ];

        $cmd = implode(' ', array_map('escapeshellarg', $args)).' 2>&1';

        $descriptor = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'w'],
            2 => ['file', $logFile, 'a'],
        ];

        $process = proc_open($cmd, $descriptor, $pipes);

        if (! is_resource($process)) {
            throw new Exception('proc_open failed for HLS concat');
        }

        $exitCode = proc_close($process);

        // Clean up concat list file
        @unlink($concatListPath);

        if ($exitCode !== 0) {
            $log = file_exists($logFile) ? file_get_contents($logFile) : 'no log';
            throw new Exception("ffmpeg concat exited with code {$exitCode}: ".substr($log, -500));
        }
    }

    /**
     * Estimate duration in seconds from actual or scheduled times.
     */
    private function estimateDuration(DvrRecording $recording): ?int
    {
        if ($recording->actual_start && $recording->actual_end) {
            return (int) abs($recording->actual_end->diffInSeconds($recording->actual_start));
        }

        if ($recording->scheduled_start && $recording->scheduled_end) {
            return (int) abs($recording->scheduled_end->diffInSeconds($recording->scheduled_start));
        }

        return null;
    }

    /**
     * Mark a recording as FAILED.
     */
    private function markFailed(DvrRecording $recording, string $reason): void
    {
        Log::error("DVR post-processing FAILED for recording {$recording->id}: {$reason}");

        $recording->update([
            'status' => DvrRecordingStatus::Failed->value,
            'error_message' => $reason,
        ]);
    }

    /**
     * Sanitize a string for use as a file/directory name.
     */
    private function sanitizeFileName(string $name): string
    {
        // Remove characters not suitable for file names
        $name = preg_replace('/[\/\\\\:*?"<>|]/', '', $name);
        $name = trim($name, '. ');

        return $name ?: 'Unknown';
    }
}
