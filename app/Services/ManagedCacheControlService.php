<?php

namespace App\Services;

use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\EmbyLibraryMapping;
use App\Models\Episode;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Models\User;

class ManagedCacheControlService
{
    /**
     * Persist additive consumer metadata for an already selected cache file.
     * This deliberately does not alter the file ownership: a shared/manual
     * cache may satisfy a request but is never adopted by this feature.
     */
    public function recordInterest(
        CachedContentFile $file,
        MediaServerIntegration $integration,
        string $mappingUuid,
        string $canonicalId,
        string $variantKey,
        string $consumerId,
    ): ManagedCacheInterest {
        return ManagedCacheInterest::firstOrCreate([
            'cached_content_file_id' => $file->id,
            'integration_id' => $integration->id,
            'mapping_uuid' => $mappingUuid,
            'consumer_id' => $consumerId,
        ], [
            'canonical_id' => $canonicalId,
            'variant_key' => $variantKey,
        ]);
    }

    /**
     * Record a watched/undo decision for one consumer interest. Only a
     * complete cohort with watched=true makes the interest releasable;
     * watched=false cancels any pending release.
     */
    public function releaseWatched(
        User $user,
        MediaServerIntegration $integration,
        string $mappingUuid,
        string $canonicalId,
        string $variantKey,
        string $consumerId,
        bool $watched,
        int $deleteAfterHours,
        bool $cohortComplete,
    ): void {
        $interest = ManagedCacheInterest::query()
            ->where('integration_id', $integration->id)
            ->whereHas('integration', fn ($query) => $query->where('user_id', $user->id))
            ->where('mapping_uuid', $mappingUuid)
            ->where('canonical_id', $canonicalId)
            ->where('variant_key', $variantKey)
            ->where('consumer_id', $consumerId)
            ->first();

        if ($interest === null) {
            return;
        }

        $file = $interest->cachedContentFile;
        if ($file === null) {
            return;
        }

        app(CachedContentActivityService::class)->withFileActivityLock($file, function () use ($interest, $watched, $deleteAfterHours, $cohortComplete): void {
            $interest = $interest->fresh();
            if ($interest === null) {
                return;
            }

            if (! $watched || ! $cohortComplete) {
                $interest->update([
                    'watched_at' => null,
                    'release_after' => null,
                ]);

                return;
            }

            $earliest = now()->copy()->addMinutes(15);
            $releaseAfter = now()->copy()->addHours(max(0, min(168, $deleteAfterHours)));

            $interest->update([
                'watched_at' => now(),
                'release_after' => $releaseAfter->greaterThan($earliest) ? $releaseAfter : $earliest,
            ]);
        });
    }

    public function resolveActiveMapping(
        User $user,
        int $integrationId,
        string $mappingUuid,
        string $revision,
    ): ?EmbyLibraryMapping {
        return EmbyLibraryMapping::query()
            ->whereBelongsTo($user)
            ->where('media_server_integration_id', $integrationId)
            ->where('uuid', $mappingUuid)
            ->where('enabled', true)
            ->where('is_managed', true)
            ->where('last_applied_revision', $revision)
            ->whereHas('integration', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('type', 'emby')
                ->where('enabled', true))
            ->first();
    }

    /**
     * @return array{canonical_id: string, variant_key: string, item: array<string, mixed>, variant: array<string, mixed>}|null
     */
    public function resolveCatalogTarget(
        EmbyLibraryMapping $mapping,
        string $canonicalId,
        string $variantKey,
    ): ?array {
        return $this->resolveCatalogTargets($mapping, [[
            'canonical_id' => $canonicalId,
            'variant_key' => $variantKey,
        ]])[0];
    }

    /**
     * Build and scan this mapping's catalog once per request. Only requested
     * identities are indexed, and results retain the caller's target order.
     *
     * @param  array<int, array{canonical_id: string, variant_key: string}>  $targets
     * @return array<int, array{canonical_id: string, variant_key: string, item: array<string, mixed>, variant: array<string, mixed>}|null>
     */
    public function resolveCatalogTargets(EmbyLibraryMapping $mapping, array $targets): array
    {
        $wanted = [];
        foreach ($targets as $target) {
            $wanted[$target['canonical_id']][$target['variant_key']] = true;
        }

        $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
        $index = [];
        foreach ($this->catalogItems($catalog['items'] ?? []) as $item) {
            $canonicalId = $item['canonical_id'] ?? null;
            if (! isset($wanted[$canonicalId])) {
                continue;
            }

            foreach ($item['variants'] ?? [] as $variant) {
                $variantKey = $variant['key'] ?? null;
                if (isset($wanted[$canonicalId][$variantKey]) && ! isset($index[$canonicalId][$variantKey])) {
                    $index[$canonicalId][$variantKey] = [
                        'canonical_id' => $canonicalId,
                        'variant_key' => $variantKey,
                        'item' => $item,
                        'variant' => $variant,
                    ];
                }
            }
            unset($wanted[$canonicalId]);
        }

        return array_map(fn (array $target): ?array => $index[$target['canonical_id']][$target['variant_key']] ?? null, $targets);
    }

    /**
     * Resolve the preferred server-built catalog source without accepting an
     * arbitrary cacheable ID from the companion request.
     *
     * @param  array{item: array<string, mixed>, variant: array<string, mixed>}  $target
     */
    public function resolveCacheableTarget(EmbyLibraryMapping $mapping, array $target): Channel|Episode|null
    {
        $sourceId = $target['variant']['preferred']['source_id'] ?? null;
        if (! is_numeric($sourceId)) {
            return null;
        }

        $model = ($target['item']['media_type'] ?? null) === 'episode' ? Episode::class : Channel::class;

        return $model::query()
            ->where('user_id', $mapping->user_id)
            ->find((int) $sourceId);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return iterable<array<string, mixed>>
     */
    private function catalogItems(array $items): iterable
    {
        foreach ($items as $item) {
            yield $item;

            foreach ($item['episodes'] ?? [] as $episode) {
                yield $episode;
            }
        }
    }
}
