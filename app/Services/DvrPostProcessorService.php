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
 *   Step 1 (FATAL)     — Concatenate HLS segments → single .mp4 file via ffmpeg
 *   Step 2 (non-fatal) — Move file to final library path, update DB
 *   Step 3 (non-fatal) — Dispatch EnrichDvrMetadata or IntegrateDvrRecordingToVod
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
            'recording_id' => $recording->id,
            'title' => $recording->title,
            'status' => $recording->status->value,
        ]);

        if ($recording->status !== DvrRecordingStatus::PostProcessing) {
            Log::warning("DVR post-processor: recording {$recording->id} not in POST_PROCESSING state — skipping", [
                'recording_id' => $recording->id,
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
        $livePath = $recording->temp_path ?? 'live/'.$recording->uuid;
        $m3u8RelPath = $recording->temp_manifest_path ?? ($livePath.'/stream.m3u8');
        $m3u8FullPath = Storage::disk($disk)->path($m3u8RelPath);

        Log::debug('DVR post-processing: locating HLS manifest', [
            'recording_id' => $recording->id,
            'disk' => $disk,
            'manifest_path' => $m3u8FullPath,
        ]);

        if (! file_exists($m3u8FullPath)) {
            // FFmpeg writes to stream.m3u8.tmp while recording; if we caught it mid-segment,
            // only the .tmp file may exist. Try that before giving up.
            $tmpPath = preg_replace('/\.m3u8$/', '.m3u8.tmp', $m3u8FullPath);
            if (file_exists($tmpPath)) {
                Log::warning('DVR post-processing: finalized .m3u8 not found — falling back to .m3u8.tmp', [
                    'recording_id' => $recording->id,
                    'tmp_path' => $tmpPath,
                ]);
                $m3u8FullPath = $tmpPath;
            } else {
                $this->markFailed($recording, "HLS manifest not found at {$m3u8FullPath}");

                return;
            }
        }

        // ── Step 1: Concatenate HLS → single .mp4 ───────────────────────────
        $outputRelPath = $this->buildOutputPath($recording);
        $outputFullPath = Storage::disk($disk)->path($outputRelPath);
        $outputDir = dirname($outputFullPath);

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $ffmpegPath = $setting->getFfmpegPath();

        $outputFormat = $setting->dvr_output_format ?? 'mp4';
        $this->setStep($recording, 'Concatenating HLS segments to '.strtoupper($outputFormat));

        try {
            $segmentCount = $this->countSegments($m3u8FullPath);
            Log::info('DVR post-processing step 1: concatenating HLS segments', [
                'recording_id' => $recording->id,
                'segment_count' => $segmentCount,
                'output_path' => $outputRelPath,
            ]);

            $this->concatHls($ffmpegPath, $m3u8FullPath, $outputFullPath, $outputFormat);
        } catch (Exception $e) {
            $this->markFailed($recording, "HLS concat failed: {$e->getMessage()}");

            return;
        }

        $fileSize = file_exists($outputFullPath) ? filesize($outputFullPath) : null;
        $duration = $this->estimateDuration($recording);

        Log::info('DVR post-processing step 1 complete: '.strtoupper($outputFormat).' concatenated', [
            'recording_id' => $recording->id,
            'output_path' => $outputRelPath,
            'file_size_bytes' => $fileSize,
            'file_size_mb' => $fileSize ? round($fileSize / 1024 / 1024, 1) : null,
            'duration_seconds' => $duration,
        ]);

        // ── Step 2: Update DB with file location ────────────────────────────
        $this->setStep($recording, 'Saving to library');

        try {
            $recording->update([
                'file_path' => $outputRelPath,
                'file_size_bytes' => $fileSize,
                'duration_seconds' => $duration,
            ]);

            Log::debug('DVR post-processing step 2 complete: file_path saved', [
                'recording_id' => $recording->id,
                'file_path' => $outputRelPath,
            ]);
        } catch (Exception $e) {
            Log::warning("DVR post-processing step 2 failed: could not update file_path: {$e->getMessage()}", [
                'recording_id' => $recording->id,
            ]);
        }

        // ── Step 3: Dispatch metadata enrichment or VOD integration ─────────
        try {
            if ($setting->enable_metadata_enrichment) {
                $this->setStep($recording, 'Queuing metadata enrichment');

                Log::info('DVR post-processing step 3: dispatching metadata enrichment', [
                    'recording_id' => $recording->id,
                ]);

                EnrichDvrMetadata::dispatch($recording->id)->onQueue('dvr-meta');
            } else {
                $this->setStep($recording, 'Queuing VOD integration');

                Log::info('DVR post-processing step 3: metadata enrichment disabled — dispatching VOD integration directly', [
                    'recording_id' => $recording->id,
                ]);

                IntegrateDvrRecordingToVod::dispatch($recording->id)->onQueue('dvr-post');
            }
        } catch (Exception $e) {
            Log::warning("DVR post-processing step 3: failed to dispatch next job: {$e->getMessage()}", [
                'recording_id' => $recording->id,
            ]);
        }

        // ── Step 4: Cleanup temp dir + mark COMPLETED ───────────────────────
        try {
            Storage::disk($disk)->deleteDirectory($livePath);

            Log::debug('DVR post-processing step 4: temp directory cleaned up', [
                'recording_id' => $recording->id,
                'live_path' => $livePath,
            ]);
        } catch (Exception $e) {
            Log::warning("DVR post-processing step 4: could not delete temp dir {$livePath}: {$e->getMessage()}", [
                'recording_id' => $recording->id,
            ]);
        }

        $recording->update([
            'status' => DvrRecordingStatus::Completed->value,
            'post_processing_step' => null,
        ]);

        // Disable "once" rules after a successful recording so they don't re-schedule
        $rule = $recording->recordingRule;
        if ($rule && $rule->type === DvrRuleType::Once) {
            $rule->update(['enabled' => false]);

            Log::debug('DVR post-processing: disabled once-rule after successful recording', [
                'recording_id' => $recording->id,
                'rule_id' => $rule->id,
            ]);
        }

        Log::info('DVR post-processing complete', [
            'recording_id' => $recording->id,
            'title' => $recording->title,
            'file_path' => $outputRelPath,
            'file_size_mb' => $fileSize ? round($fileSize / 1024 / 1024, 1) : null,
            'duration_seconds' => $duration,
        ]);
    }

    /**
     * Build the final output path for the recording (relative to the dvr disk).
     *
     * Pattern: library/{Year}/{Title}/Title - SXXEYY.{ext}
     * or:       library/{Year}/{Title}/Title - YYYY-MM-DD.{ext}
     *
     * The extension is derived from the DvrSetting's dvr_output_format (mp4, mkv, ts).
     */
    public function buildOutputPath(DvrRecording $recording): string
    {
        $title = $this->sanitizeFileName($recording->title);
        $year = ($recording->programme_start ?? $recording->scheduled_start)?->format('Y') ?? now()->format('Y');
        $ext = $recording->dvrSetting?->dvr_output_format ?? 'mp4';

        if ($recording->season !== null && $recording->episode !== null) {
            $episode = sprintf('%s - S%02dE%02d', $title, $recording->season, $recording->episode);
        } else {
            $date = ($recording->programme_start ?? $recording->scheduled_start)?->format('Y-m-d') ?? now()->format('Y-m-d');
            $episode = "{$title} - {$date}";
        }

        return "library/{$year}/{$title}/{$episode}.{$ext}";
    }

    /**
     * Update the post_processing_step on the recording and emit a log entry.
     * Refreshes the model in-place so subsequent reads see the updated value.
     */
    private function setStep(DvrRecording $recording, string $step): void
    {
        $recording->update(['post_processing_step' => $step]);

        Log::debug("DVR post-processing step: {$step}", [
            'recording_id' => $recording->id,
            'title' => $recording->title,
        ]);
    }

    /**
     * Concatenate HLS segments into a single output file.
     *
     * For MPEG-TS output: binary-concatenates the raw .ts segment files directly —
     * no FFmpeg needed, fastest possible path, zero re-encoding overhead.
     *
     * For MP4/MKV output: uses FFmpeg's concat demuxer with -c copy (stream copy only,
     * no re-encoding). We build an explicit file list from the .m3u8 manifest rather than
     * feeding the manifest directly, which avoids stalls caused by corrupt #EXTINF durations.
     *
     * Output format is determined by the $format parameter: 'ts', 'mp4', or 'mkv'.
     */
    private function concatHls(string $ffmpegPath, string $m3u8Path, string $outputPath, string $format = 'ts'): void
    {
        $segmentDir = dirname($m3u8Path);

        // Parse segment filenames from the .m3u8 manifest (lines not starting with #)
        $segments = array_values(array_filter(
            array_map('trim', file($m3u8Path)),
            fn (string $line) => $line !== '' && ! str_starts_with($line, '#')
        ));

        if (empty($segments)) {
            throw new Exception('No segments found in HLS manifest');
        }

        // Fast path: MPEG-TS is just a binary concat of the segment files
        if ($format === 'ts') {
            $out = fopen($outputPath, 'wb');
            if (! $out) {
                throw new Exception("Could not open output file for writing: {$outputPath}");
            }
            foreach ($segments as $seg) {
                $segPath = "{$segmentDir}/{$seg}";
                if (! file_exists($segPath)) {
                    fclose($out);
                    throw new Exception("Segment not found: {$segPath}");
                }
                $in = fopen($segPath, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);

            return;
        }

        // MP4 / MKV: use FFmpeg concat demuxer with stream copy
        $concatListPath = $segmentDir.'/concat-list.txt';
        $logFile = $segmentDir.'/ffmpeg-concat.log';

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
        ];

        match ($format) {
            'mkv' => array_push($args, '-f', 'matroska'),
            default => array_push($args, '-movflags', '+faststart'), // mp4
        };

        $args[] = $outputPath;

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
     * Count the number of .ts segments listed in an HLS manifest.
     */
    private function countSegments(string $m3u8Path): int
    {
        if (! file_exists($m3u8Path)) {
            return 0;
        }

        return count(array_filter(
            array_map('trim', file($m3u8Path)),
            fn (string $line) => $line !== '' && ! str_starts_with($line, '#')
        ));
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
     * Mark a recording as FAILED and clear any in-progress step label.
     */
    private function markFailed(DvrRecording $recording, string $reason): void
    {
        Log::error("DVR post-processing FAILED for recording {$recording->id}: {$reason}", [
            'recording_id' => $recording->id,
            'title' => $recording->title,
        ]);

        $recording->update([
            'status' => DvrRecordingStatus::Failed->value,
            'error_message' => $reason,
            'post_processing_step' => null,
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
