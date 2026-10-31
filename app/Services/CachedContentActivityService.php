<?php

namespace App\Services;

use App\Models\CachedContentActivityLease;
use App\Models\CachedContentFile;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CachedContentActivityService
{
    private const int PLAYBACK_TTL_SECONDS = 120;

    private const int READER_TTL_SECONDS = 120;

    private const int LOCK_TIMEOUT_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 5;

    /** @param array{mapping_uuid: string, revision: string, canonical_id: string, variant_key: string} $target */
    public function acquirePlayback(
        User $user,
        MediaServerIntegration $integration,
        string $leaseId,
        int $sequence,
        array $target,
    ): CachedContentActivityLease {
        $lease = CachedContentActivityLease::firstOrNew([
            'integration_id' => $integration->id,
            'kind' => 'playback',
            'lease_id' => $leaseId,
        ]);

        if ($lease->exists && $lease->terminal_until?->isFuture()) {
            return $lease;
        }

        if ($lease->exists && $sequence < $lease->sequence) {
            return $lease;
        }

        $now = now();
        if ($lease->exists && $sequence === $lease->sequence) {
            return $lease;
        }
        $lease->fill([
            'user_id' => $user->id,
            'mapping_uuid' => $target['mapping_uuid'],
            'revision' => $target['revision'],
            'canonical_id' => $target['canonical_id'],
            'variant_key' => $target['variant_key'],
            'sequence' => $sequence,
            'expires_at' => $now->copy()->addSeconds(self::PLAYBACK_TTL_SECONDS),
            'last_activity_at' => $now,
            'terminal_until' => null,
        ]);
        $lease->save();

        return $lease;
    }

    public function stopPlayback(MediaServerIntegration $integration, string $leaseId, int $sequence): void
    {
        $lease = CachedContentActivityLease::query()
            ->where('integration_id', $integration->id)
            ->where('kind', 'playback')
            ->where('lease_id', $leaseId)
            ->where('sequence', '<', $sequence)
            ->first();
        if ($lease === null) {
            return;
        }

        $now = now();
        $lease->update([
            'sequence' => $sequence,
            'expires_at' => $now,
            'last_activity_at' => $now,
            'terminal_until' => $now->copy()->addSeconds(self::PLAYBACK_TTL_SECONDS),
        ]);

        ManagedCacheInterest::query()
            ->where('integration_id', $lease->integration_id)
            ->where('mapping_uuid', $lease->mapping_uuid)
            ->where('canonical_id', $lease->canonical_id)
            ->where('variant_key', $lease->variant_key)
            ->whereNotNull('watched_at')
            ->update(['release_after' => $now->copy()->addMinutes(15)]);
    }

    /** @param array{mapping_uuid: string, revision: string, canonical_id: string, variant_key: string} $target */
    public function hasActivePlayback(array $target): bool
    {
        return CachedContentActivityLease::query()
            ->where('kind', 'playback')
            ->where('mapping_uuid', $target['mapping_uuid'])
            ->where('revision', $target['revision'])
            ->where('canonical_id', $target['canonical_id'])
            ->where('variant_key', $target['variant_key'])
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function acquireReaderForFile(
        User $user,
        ?MediaServerIntegration $integration,
        string $leaseId,
        CachedContentFile $file,
    ): CachedContentActivityLease {
        return $this->acquireReader(
            $user,
            $this->resolveIntegration($user, $integration),
            $leaseId,
            $this->fileTarget($file),
            $file,
        );
    }

    /**
     * @param  array{mapping_uuid: string|null, revision: string|null, canonical_id: string|null, variant_key: string|null}  $target
     */
    public function acquireReader(
        User $user,
        MediaServerIntegration $integration,
        string $leaseId,
        array $target,
        ?CachedContentFile $file = null,
    ): CachedContentActivityLease {
        return $this->withActivityLock($target, $file, function () use ($user, $integration, $leaseId, $target, $file): CachedContentActivityLease {
            $now = now();

            $lease = CachedContentActivityLease::firstOrNew([
                'integration_id' => $integration->id,
                'kind' => 'reader',
                'lease_id' => $leaseId,
            ]);

            $lease->fill([
                'user_id' => $user->id,
                'mapping_uuid' => $target['mapping_uuid'],
                'revision' => $target['revision'],
                'canonical_id' => $target['canonical_id'],
                'variant_key' => $target['variant_key'],
                'cached_content_file_id' => $file?->id,
                'sequence' => 0,
                'expires_at' => $now->copy()->addSeconds(self::READER_TTL_SECONDS),
                'last_activity_at' => $now,
            ]);
            $lease->save();

            return $lease;
        });
    }

    public function releaseReader(CachedContentActivityLease $lease): void
    {
        $lease->update([
            'expires_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    /** Renew the active reader while bytes are still being streamed. */
    public function renewReader(CachedContentActivityLease $lease): void
    {
        $lease->update([
            'expires_at' => now()->addSeconds(self::READER_TTL_SECONDS),
            'last_activity_at' => now(),
        ]);
    }

    /** @param array{mapping_uuid: string, revision: string, canonical_id: string, variant_key: string} $target */
    public function hasActiveReader(array $target): bool
    {
        return CachedContentActivityLease::query()
            ->where('kind', 'reader')
            ->where('mapping_uuid', $target['mapping_uuid'])
            ->where('revision', $target['revision'])
            ->where('canonical_id', $target['canonical_id'])
            ->where('variant_key', $target['variant_key'])
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function hasActiveReaderForFile(CachedContentFile $file): bool
    {
        return $this->findActiveReaderLeaseForFile($file) !== null;
    }

    public function findActiveReaderLeaseForFile(CachedContentFile $file): ?CachedContentActivityLease
    {
        return CachedContentActivityLease::query()
            ->where('cached_content_file_id', $file->id)
            ->where('kind', 'reader')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function isSafeToDelete(CachedContentFile $file): bool
    {
        if (CachedContentActivityLease::query()
            ->where('cached_content_file_id', $file->id)
            ->where('expires_at', '>', now())
            ->exists()) {
            return false;
        }

        return ! ManagedCacheInterest::query()
            ->where('cached_content_file_id', $file->id)
            ->whereExists(function ($lease): void {
                $lease->selectRaw('1')
                    ->from('cached_content_activity_leases')
                    ->where('kind', 'playback')
                    ->where('expires_at', '>', now())
                    ->whereColumn('cached_content_activity_leases.integration_id', 'managed_cache_interests.integration_id')
                    ->whereColumn('cached_content_activity_leases.mapping_uuid', 'managed_cache_interests.mapping_uuid')
                    ->whereColumn('cached_content_activity_leases.canonical_id', 'managed_cache_interests.canonical_id')
                    ->whereColumn('cached_content_activity_leases.variant_key', 'managed_cache_interests.variant_key');
            })
            ->exists();
    }

    /**
     * Serialize a file deletion recheck with reader-lease acquisition. Both
     * paths use the same file-derived key, so a reader cannot appear between
     * the deletion safety check and storage removal.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withFileActivityLock(CachedContentFile $file, callable $callback): mixed
    {
        return $this->withActivityLock($this->fileTarget($file), $file, $callback);
    }

    private function resolveIntegration(User $user, ?MediaServerIntegration $integration): MediaServerIntegration
    {
        if ($integration !== null) {
            return $integration;
        }

        $fallback = MediaServerIntegration::query()
            ->where('user_id', $user->id)
            ->first();

        if ($fallback === null) {
            throw new \RuntimeException('No media server integration available for activity lease');
        }

        return $fallback;
    }

    /**
     * @return array{mapping_uuid: null, revision: null, canonical_id: string|null, variant_key: string|null}
     */
    private function fileTarget(CachedContentFile $file): array
    {
        return [
            'mapping_uuid' => null,
            'revision' => null,
            'canonical_id' => $file->cacheable ? (string) $file->cacheable->getKey() : null,
            'variant_key' => $file->quality,
        ];
    }

    /**
     * @param  array{mapping_uuid: string|null, revision: string|null, canonical_id: string|null, variant_key: string|null}  $target
     * @param  callable(): T  $callback
     * @return T
     *
     * @template T
     */
    private function withActivityLock(array $target, ?CachedContentFile $file, callable $callback): mixed
    {
        $key = $this->activityLockKey($target, $file);

        try {
            $lock = Cache::lock($key, self::LOCK_TIMEOUT_SECONDS);

            return $lock->block(self::LOCK_WAIT_SECONDS, $callback);
        } catch (LockTimeoutException) {
            Log::warning('Managed cache activity lock timeout', [
                'key' => $key,
                'mapping_uuid' => $target['mapping_uuid'],
                'canonical_id' => $target['canonical_id'],
            ]);

            throw new \RuntimeException('Managed cache activity lock unavailable');
        }
    }

    /**
     * @param  array{mapping_uuid: string|null, revision: string|null, canonical_id: string|null, variant_key: string|null}  $target
     */
    private function activityLockKey(array $target, ?CachedContentFile $file): string
    {
        if ($file !== null && $file->exists) {
            return "managed-cache:file:{$file->id}:activity";
        }

        $hash = hash('sha256', implode(':', array_filter([
            $target['mapping_uuid'],
            $target['revision'],
            $target['canonical_id'],
            $target['variant_key'],
        ], fn (?string $v): bool => $v !== null)));

        return "managed-cache:target:{$hash}:activity";
    }
}
