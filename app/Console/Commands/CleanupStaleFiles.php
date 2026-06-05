<?php

namespace App\Console\Commands;

use App\Models\Epg;
use App\Models\Playlist;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class CleanupStaleFiles extends Command
{
    protected $signature = 'app:cleanup-stale-files
                                {--force : Skip confirmation prompt}
                                {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Remove stale files for Playlists and EPGs that no longer exist in the database';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('[DRY RUN] No files will be deleted.');
        } elseif (! $this->option('force') && ! $this->confirm('This will delete stale playlist and EPG files. Continue?')) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $disk = Storage::disk('local');
        $counts = ['dirs' => 0, 'files' => 0];

        $playlistUuids = Playlist::query()->pluck('uuid')->all();
        $counts = $this->cleanupSubdirectories($disk, 'playlist', $playlistUuids, $isDryRun, $counts);
        $counts = $this->cleanupLooseFiles($disk, 'playlist', $playlistUuids, $isDryRun, $counts);
        $counts = $this->cleanupSubdirectories($disk, 'playlist-epg-files', $playlistUuids, $isDryRun, $counts);

        $epgUuids = Epg::query()->pluck('uuid')->all();
        $counts = $this->cleanupSubdirectories($disk, 'epg', $epgUuids, $isDryRun, $counts);
        $counts = $this->cleanupLooseFiles($disk, 'epg', $epgUuids, $isDryRun, $counts);
        $counts = $this->cleanupSubdirectories($disk, 'epg-cache', $epgUuids, $isDryRun, $counts);

        $label = $isDryRun ? 'Would remove' : 'Removed';
        $this->info(
            "{$label} {$counts['dirs']} director".($counts['dirs'] === 1 ? 'y' : 'ies').
            " and {$counts['files']} file".($counts['files'] === 1 ? '' : 's').'.'
        );

        return Command::SUCCESS;
    }

    /**
     * Delete subdirectories whose basename (uuid) is not in the known set.
     *
     * @param  string[]  $knownUuids
     * @param  array{dirs: int, files: int}  $counts
     * @return array{dirs: int, files: int}
     */
    private function cleanupSubdirectories(Filesystem $disk, string $baseDir, array $knownUuids, bool $isDryRun, array $counts): array
    {
        if (! $disk->exists($baseDir)) {
            return $counts;
        }

        $knownSet = array_flip($knownUuids);

        foreach ($disk->directories($baseDir) as $dir) {
            $uuid = basename($dir);
            if (! isset($knownSet[$uuid])) {
                $this->line("  Removing directory: {$dir}");
                if (! $isDryRun) {
                    $disk->deleteDirectory($dir);
                }
                $counts['dirs']++;
            }
        }

        return $counts;
    }

    /**
     * Delete loose files directly inside $baseDir whose stem (filename without extension) is not a known uuid.
     *
     * @param  string[]  $knownUuids
     * @param  array{dirs: int, files: int}  $counts
     * @return array{dirs: int, files: int}
     */
    private function cleanupLooseFiles(Filesystem $disk, string $baseDir, array $knownUuids, bool $isDryRun, array $counts): array
    {
        if (! $disk->exists($baseDir)) {
            return $counts;
        }

        $knownSet = array_flip($knownUuids);

        foreach ($disk->files($baseDir) as $file) {
            $stem = pathinfo($file, PATHINFO_FILENAME);
            if (! isset($knownSet[$stem])) {
                $this->line("  Removing file: {$file}");
                if (! $isDryRun) {
                    $disk->delete($file);
                }
                $counts['files']++;
            }
        }

        return $counts;
    }
}
