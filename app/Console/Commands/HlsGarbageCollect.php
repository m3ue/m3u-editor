<?php

namespace App\Console\Commands;

use App\Models\Network;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class HlsGarbageCollect extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --loop: run forever with sleep interval
     * --interval: seconds to sleep between loop iterations
     * --threshold: file age threshold in seconds (older files are deleted)
     * --dry-run: show what would be deleted
     */
    protected $signature = 'hls:gc
                            {--loop : Run continuously}
                            {--interval=600 : Sleep interval between runs (seconds)}
                            {--threshold=7200 : Age threshold for files (seconds)}
                            {--dry-run : Show files that would be deleted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Garbage collect old HLS segments and playlists';

    public function handle(Filesystem $files): int
    {
        $enabled = filter_var(env('HLS_GC_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            $this->info('HLS garbage collection is disabled (HLS_GC_ENABLED=false)');

            return 0;
        }

        $loop = $this->option('loop');
        $interval = (int) $this->option('interval');
        $threshold = (int) $this->option('threshold');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Starting HLS garbage collection (threshold={$threshold}s, interval={$interval}s)".($dryRun ? ' [dry-run]' : ''));

        do {
            $this->runOnce($files, $threshold, $dryRun);

            if ($loop) {
                sleep($interval);
            }
        } while ($loop);

        return 0;
    }

    protected function runOnce(Filesystem $files, int $threshold, bool $dryRun): void
    {
        $now = time();

        // First, clean up any stopped broadcasts immediately (regardless of age)
        $this->cleanupStoppedBroadcasts($files, $dryRun);

        // Locations to clean
        $networkBase = storage_path('app/networks');
        $tempBase = env('HLS_TEMP_DIR', storage_path('app/hls-segments'));

        $paths = array_filter([$networkBase, $tempBase]);

        $totalDeleted = 0;
        $totalBytesFreed = 0;

        foreach ($paths as $base) {
            if (! $files->isDirectory($base)) {
                continue;
            }

            foreach ($files->directories($base) as $dir) {
                [$deleted, $bytes] = $this->cleanDirectory($files, $dir, $now, $threshold, $dryRun);
                $totalDeleted += $deleted;
                $totalBytesFreed += $bytes;

                // Remove directory if empty
                $remaining = $files->files($dir);
                if (count($remaining) === 0) {
                    if ($dryRun) {
                        $this->line("[DRY] Would remove empty directory: {$dir}");
                    } else {
                        $this->info("Removing empty directory: {$dir}");
                        $files->deleteDirectory($dir);
                    }
                }
            }

            // Also clean files directly under base (for temp dir)
            if ($base === $tempBase) {
                [$d, $b] = $this->cleanDirectory($files, $base, $now, $threshold, $dryRun, true);
                $totalDeleted += $d;
                $totalBytesFreed += $b;
            }
        }

        // Summary
        if ($dryRun) {
            $this->line("[DRY] Summary: {$totalDeleted} files would be deleted, freeing approximately {$totalBytesFreed} bytes.");
        } else {
            $this->info("Summary: Deleted {$totalDeleted} files, freed approximately {$totalBytesFreed} bytes.");
            \Illuminate\Support\Facades\Log::info('HLS GC summary', ['deleted' => $totalDeleted, 'bytes_freed' => $totalBytesFreed]);
        }
    }

    /**
     * Clean a single directory for old HLS files.
     * If $skipTop is true, process files directly in the base dir.
     */
    protected function cleanDirectory(Filesystem $files, string $dir, int $now, int $threshold, bool $dryRun, bool $skipTop = false): array
    {
        $deleted = 0;
        $bytesFreed = 0;

        // Patterns to consider for cleanup
        $patterns = ['*.ts', '*.m3u8.tmp', '*.m3u8.old', '*.m3u8.bak'];

        // Also consider stale playlists and tempfiles
        foreach ($patterns as $pattern) {
            foreach ($files->glob("{$dir}/{$pattern}") as $file) {
                // If file is currently being written (mtime very recent), skip (safety window 5s)
                $age = $now - @filemtime($file);
                if ($age <= 5) {
                    continue;
                }

                if ($age >= $threshold) {
                    if ($dryRun) {
                        $this->line("[DRY] Would delete: {$file} (age={$age}s)");
                    } else {
                        $this->info("Deleting: {$file} (age={$age}s)");
                        try {
                            $bytesFreed += filesize($file) ?: 0;
                            $files->delete($file);
                            $deleted++;
                        } catch (\Throwable $e) {
                            $this->error("Failed to delete {$file}: {$e->getMessage()}");
                        }
                    }
                }
            }
        }

        // Also check for old .m3u8 files that are not the active live.m3u8
        foreach ($files->glob("{$dir}/*.m3u8") as $playlist) {
            // keep live.m3u8 if it's being updated recently
            if (Str::endsWith($playlist, 'live.m3u8')) {
                $age = $now - @filemtime($playlist);
                if ($age < $threshold) {
                    continue;
                }
            }

            $age = $now - @filemtime($playlist);
            if ($age >= $threshold) {
                if ($dryRun) {
                    $this->line("[DRY] Would delete playlist: {$playlist} (age={$age}s)");
                } else {
                    $this->info("Deleting playlist: {$playlist} (age={$age}s)");
                    try {
                        $bytesFreed += filesize($playlist) ?: 0;
                        $files->delete($playlist);
                        $deleted++;
                    } catch (\Throwable $e) {
                        $this->error("Failed to delete {$playlist}: {$e->getMessage()}");
                    }
                }
            }
        }

        return [$deleted, $bytesFreed];
    }

    /**
     * Clean up HLS files for networks that are not currently broadcasting.
     * This ensures stopped broadcasts are cleaned up immediately.
     */
    protected function cleanupStoppedBroadcasts(Filesystem $files, bool $dryRun): void
    {
        // Get all networks with HLS directories
        $networkBase = storage_path('app/networks');
        if (! $files->isDirectory($networkBase)) {
            return;
        }

        // Get UUIDs of networks that are currently broadcasting
        $activeBroadcasts = Network::whereNotNull('broadcast_pid')
            ->where('broadcast_enabled', true)
            ->pluck('uuid')
            ->toArray();

        $cleaned = 0;
        foreach ($files->directories($networkBase) as $dir) {
            $uuid = basename($dir);

            // Skip if this network is currently broadcasting
            if (in_array($uuid, $activeBroadcasts)) {
                continue;
            }

            // Check if there are any HLS files
            $hasFiles = count($files->glob("{$dir}/*.ts")) > 0 ||
                       count($files->glob("{$dir}/*.m3u8")) > 0 ||
                       count($files->glob("{$dir}/*.m3u8.tmp")) > 0;

            if ($hasFiles) {
                if ($dryRun) {
                    $this->line("[DRY] Would clean stopped broadcast: {$uuid}");
                } else {
                    $this->info("Cleaning stopped broadcast: {$uuid}");

                    // Remove HLS files
                    foreach ($files->glob("{$dir}/*.ts") as $file) {
                        $files->delete($file);
                    }
                    foreach ($files->glob("{$dir}/*.m3u8") as $file) {
                        $files->delete($file);
                    }
                    foreach ($files->glob("{$dir}/*.m3u8.tmp") as $file) {
                        $files->delete($file);
                    }

                    // Kill promoter if still running
                    $promotePidFile = "{$dir}/promote_pid";
                    if ($files->exists($promotePidFile)) {
                        try {
                            $promotePid = (int) $files->get($promotePidFile);
                            if ($promotePid > 0 && @posix_kill($promotePid, 0)) {
                                posix_kill($promotePid, SIGKILL);
                            }
                            $files->delete($promotePidFile);
                        } catch (\Throwable $e) {
                            // Ignore errors
                        }
                    }

                    $cleaned++;
                }
            }
        }

        if ($cleaned > 0) {
            $this->info("Cleaned up {$cleaned} stopped broadcast(s)");
        }
    }
}
