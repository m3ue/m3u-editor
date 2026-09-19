<?php

namespace App\Services;

use App\Models\Epg;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Owns the on-disk shape of one EPG's cache: a single directory holding
 * `metadata.json`, `channels.json` and the canonical `programmes.sqlite` store.
 *
 * There is exactly one cache file set per EPG - no generation directories, no
 * pointer file and no retained copies. A rebuild writes staging files next to
 * the canonical ones and swaps them in with atomic renames under the per-EPG
 * mutation lock, so readers observe either the previous or the rebuilt cache
 * and the directory never holds a second copy of the programme store.
 */
class EpgCacheStorage
{
    public const METADATA_FILE = 'metadata.json';

    public const CHANNELS_FILE = 'channels.json';

    public const PROGRAMMES_DB_FILE = 'programmes.sqlite';

    private const CACHE_VERSION = 'v2';

    /** Older cache versions that are still served for reads until the next rebuild writes v2. */
    private const PREVIOUS_CACHE_VERSIONS = ['v1'];

    /** Staging files are named `<file>.building-<pid>-<random>`. */
    private const STAGING_MARKER = '.building-';

    /**
     * How long a staging file abandoned by a crashed rebuild is kept before the
     * next rebuild prunes it. Comfortably longer than any healthy rebuild, so a
     * concurrent rebuild of the same EPG is never touched.
     */
    private const ABANDONED_STAGING_SECONDS = 6 * 3600;

    /**
     * Publication order. The programme store goes first and `metadata.json`
     * last, so the cache-validity marker (`cache_created`) only ever flips once
     * every other file of the rebuild is already in place.
     *
     * @var list<string>
     */
    private const PUBLISH_ORDER = [self::PROGRAMMES_DB_FILE, self::CHANNELS_FILE, self::METADATA_FILE];

    /**
     * The directory reads and writes resolve to: the canonical v2 directory, or
     * a legacy version directory that is still on disk, so an older cache keeps
     * being served until the next rebuild replaces it.
     */
    public function resolve(Epg $epg): string
    {
        $disk = Storage::disk('local');
        $directory = $this->directory($epg);
        if ($disk->exists($directory.'/'.self::METADATA_FILE)) {
            return $directory;
        }

        foreach (self::PREVIOUS_CACHE_VERSIONS as $version) {
            $legacyDirectory = "epg-cache/{$epg->uuid}/{$version}";
            if ($disk->exists($legacyDirectory.'/'.self::METADATA_FILE)) {
                return $legacyDirectory;
            }
        }

        return $directory;
    }

    /** The one canonical cache directory of an EPG. */
    public function directory(Epg $epg): string
    {
        return "epg-cache/{$epg->uuid}/".self::CACHE_VERSION;
    }

    /** Path of one canonical cache file. */
    public function path(Epg $epg, string $file): string
    {
        return $this->directory($epg).'/'.$file;
    }

    /** Whether the resolved directory is the canonical cache rather than a legacy layout. */
    public function isCanonical(Epg $epg, string $directory): bool
    {
        return $directory === $this->directory($epg);
    }

    /**
     * Prepare an in-place rebuild: make sure the canonical directory exists and
     * prune staging files a crashed rebuild left behind.
     */
    public function beginRebuild(Epg $epg): string
    {
        $directory = $this->directory($epg);
        Storage::disk('local')->makeDirectory($directory);
        $this->pruneAbandonedStagingFiles($directory);

        return $directory;
    }

    /**
     * Path a rebuild writes one file to before {@see publishRebuild()} swaps it
     * in. Unique per run, so overlapping rebuilds of the same EPG cannot write
     * into each other's staging file.
     */
    public function stagedPath(string $directory, string $file): string
    {
        return $directory.'/'.$file.self::STAGING_MARKER.getmypid().'-'.bin2hex(random_bytes(4));
    }

    /**
     * Atomically swap a completed rebuild into the canonical cache. Every staged
     * file must exist, `metadata.json` and `channels.json` must hold JSON
     * objects, and the staged programme store must be structurally sound before
     * the first rename, and the renames run under the per-EPG mutation lock so a
     * concurrent enrichment commit can never target a file that is being
     * replaced.
     *
     * @param  array<string, string>  $staged  canonical filename => staged path
     *
     * @throws RuntimeException when the rebuild is incomplete or unusable
     */
    public function publishRebuild(Epg $epg, array $staged): void
    {
        $disk = Storage::disk('local');
        foreach (self::PUBLISH_ORDER as $file) {
            if (! isset($staged[$file]) || ! is_string($staged[$file]) || ! $disk->exists($staged[$file])) {
                throw new RuntimeException("Cannot publish an incomplete EPG cache rebuild: {$file} is missing.");
            }
        }

        foreach ([self::CHANNELS_FILE, self::METADATA_FILE] as $file) {
            if (! $this->isJsonObjectOrArray($disk->get($staged[$file]))) {
                throw new RuntimeException("Cannot publish an EPG cache rebuild: {$file} does not hold a JSON object.");
            }
        }

        try {
            $store = EpgProgrammeStore::openRead($disk->path($staged[self::PROGRAMMES_DB_FILE]));
            try {
                $usable = $store->quickCheck();
            } finally {
                $store->close();
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Cannot publish an unusable EPG programme store.', previous: $e);
        }

        if (! $usable) {
            throw new RuntimeException('Cannot publish an unusable EPG programme store.');
        }

        $this->mutate($epg, function () use ($disk, $epg, $staged): void {
            foreach (self::PUBLISH_ORDER as $file) {
                $finalPath = $disk->path($this->path($epg, $file));
                if (! @rename($disk->path($staged[$file]), $finalPath)) {
                    throw new RuntimeException("Failed to move EPG cache file into place at {$finalPath}");
                }
            }
        });
    }

    /**
     * Whether `$contents` decodes to a JSON object or array - the only shape the
     * `metadata.json` and `channels.json` of a cache are ever allowed to have.
     * A truncated or scalar payload would otherwise be published and served to
     * readers as if the rebuild had succeeded.
     */
    private function isJsonObjectOrArray(?string $contents): bool
    {
        if ($contents === null) {
            return false;
        }

        try {
            return is_array(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Remove the staging files of a rebuild that never published.
     *
     * @param  array<string, string>  $staged  canonical filename => staged path
     */
    public function discardStaged(array $staged): void
    {
        foreach ($staged as $path) {
            if (is_string($path) && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /**
     * Run a short mutation that reads and writes the canonical cache. Callers
     * must do all slow work before entering this critical section.
     */
    public function mutate(Epg $epg, Closure $callback): mixed
    {
        return Cache::lock($this->mutationLockName($epg), 30)->block(10, $callback);
    }

    public function mutationLockName(Epg $epg): string
    {
        return 'epg-cache-mutation:'.$epg->uuid;
    }

    /**
     * Prune staging files that are old enough to certainly belong to a crashed
     * run. Best effort: pruning must never fail the rebuild it runs before.
     */
    private function pruneAbandonedStagingFiles(string $directory): void
    {
        try {
            $disk = Storage::disk('local');
            $cutoff = now()->subSeconds(self::ABANDONED_STAGING_SECONDS)->getTimestamp();
            foreach ($disk->files($directory) as $file) {
                if (! str_contains(basename($file), self::STAGING_MARKER)) {
                    continue;
                }
                if ($disk->lastModified($file) < $cutoff) {
                    $disk->delete($file);
                }
            }
        } catch (Throwable) {
            // Best effort: an unreadable directory is reported by the rebuild itself.
        }
    }
}
