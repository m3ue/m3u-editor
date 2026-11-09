<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Reads the status docker/8.4/pg-cluster.sh writes on boot, and safely
 * reclaims disk used by clusters it retained for rollback after an opt-in
 * PostgreSQL major upgrade (the pre-upgrade cluster left at the mount root,
 * and any pg<N>.rolled-back-<timestamp> directories from an explicit
 * POSTGRES_ROLLBACK=true).
 */
class EmbeddedPostgresStatus
{
    /**
     * Postgres file/directory names a root cluster can contain. Deleting
     * PG_VERSION first makes the cluster unbootable before anything else is
     * touched, so a delete interrupted partway through never leaves behind
     * something pg_cluster_find_active() could mistake for a real cluster -
     * it just stops looking like one. Only these names are ever removed;
     * the active pg<N>/ directory and the status file are never in this
     * list, so they cannot be deleted even if this ran against the wrong path.
     */
    private const ROOT_CLUSTER_ENTRIES = [
        'PG_VERSION', 'base', 'global', 'pg_wal', 'pg_xact', 'pg_multixact',
        'pg_subtrans', 'pg_twophase', 'pg_commit_ts', 'pg_notify', 'pg_replslot',
        'pg_serial', 'pg_snapshots', 'pg_stat', 'pg_stat_tmp', 'pg_logical',
        'pg_tblspc', 'pg_dynshmem', 'postgresql.conf', 'postgresql.auto.conf',
        'pg_hba.conf', 'pg_ident.conf', 'postmaster.opts', 'postmaster.pid',
        'pg_control',
    ];

    public function mountPath(): string
    {
        return dirname($this->statusPath());
    }

    public function statusPath(): string
    {
        return config('database.embedded_postgres_status_path');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if (! File::exists($this->statusPath())) {
            return null;
        }

        $decoded = json_decode(File::get($this->statusPath()), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Clusters pg-cluster.sh retained for rollback, derived from the
     * filesystem (not tracked as a list in the status file) so this is
     * always accurate even after a copy is deleted outside of
     * deleteRetainedCopies(), or the container is restarted.
     *
     * @return list<array{kind: string, version: string, path: string, bytes: int, created_at: ?string}>
     */
    public function retainedCopies(): array
    {
        $mount = $this->mountPath();
        $copies = [];

        $status = $this->read();
        $activePath = $status['active_path'] ?? null;

        // The pre-upgrade cluster stays at the mount root, untouched, once
        // the active cluster has moved to a nested pg<N>/ directory.
        if ($activePath && $activePath !== $mount && File::exists("{$mount}/PG_VERSION")) {
            $copies[] = [
                'kind' => 'previous_cluster',
                'version' => trim(File::get("{$mount}/PG_VERSION")),
                'path' => $mount,
                'bytes' => $this->rootClusterSize($mount),
                'created_at' => null,
            ];
        }

        foreach (File::glob("{$mount}/pg*.rolled-back-*") ?: [] as $dir) {
            if (! File::isDirectory($dir)) {
                continue;
            }

            preg_match('/^pg(\d+)\.rolled-back-(\d{14})$/', basename($dir), $matches);

            $copies[] = [
                'kind' => 'rolled_back',
                'version' => $matches[1] ?? '?',
                'path' => $dir,
                'bytes' => $this->directorySize($dir),
                'created_at' => isset($matches[2]) ? Carbon::createFromFormat('YmdHis', $matches[2])->toDateTimeString() : null,
            ];
        }

        return $copies;
    }

    /**
     * Deletes every retained copy found by retainedCopies(). Never touches
     * the currently active cluster - a rolled-back or pre-upgrade cluster is
     * by definition not the one active_path currently points to.
     */
    public function deleteRetainedCopies(): void
    {
        $mount = $this->mountPath();

        foreach ($this->retainedCopies() as $copy) {
            Cache::forget(self::sizeCacheKey($copy['path']));

            if ($copy['kind'] === 'rolled_back') {
                File::deleteDirectory($copy['path']);

                continue;
            }

            File::delete("{$mount}/PG_VERSION");

            foreach (self::ROOT_CLUSTER_ENTRIES as $entry) {
                $target = "{$mount}/{$entry}";
                if (File::isDirectory($target)) {
                    File::deleteDirectory($target);
                } elseif (File::exists($target)) {
                    File::delete($target);
                }
            }
        }
    }

    /**
     * Sums the root cluster's own files, excluding any nested pg<N> (the
     * active upgraded cluster) or pg<N>.rolled-back-<timestamp> directories
     * that happen to live alongside it at the same mount root.
     *
     * A retained cluster can hold tens of thousands of files, and this is
     * recomputed whenever the System Health report's 30-second cache
     * expires. A retained copy's size only ever changes when it's deleted
     * (nothing writes to it once set aside), so the walk itself is cached
     * separately, much longer - deleteRetainedCopies() forgets this cache
     * entry for whatever it removes, so a deletion is still reflected
     * immediately rather than waiting out the TTL.
     */
    private function rootClusterSize(string $mount): int
    {
        return Cache::remember(self::sizeCacheKey($mount), 300, function () use ($mount): int {
            $size = 0;

            foreach (File::allFiles($mount) as $file) {
                $topSegment = explode(DIRECTORY_SEPARATOR, $file->getRelativePath())[0] ?? '';
                if (preg_match('/^pg\d+(\.rolled-back-.*)?$/', $topSegment)) {
                    continue;
                }

                $size += $file->getSize();
            }

            return $size;
        });
    }

    private function directorySize(string $path): int
    {
        return Cache::remember(self::sizeCacheKey($path), 300, function () use ($path): int {
            $size = 0;

            foreach (File::allFiles($path) as $file) {
                $size += $file->getSize();
            }

            return $size;
        });
    }

    private static function sizeCacheKey(string $path): string
    {
        return 'embedded_postgres_retained_size:'.md5($path);
    }
}
