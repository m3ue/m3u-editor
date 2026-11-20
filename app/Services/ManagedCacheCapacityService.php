<?php

namespace App\Services;

use App\Enums\CacheDispatchResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Decides whether a managed-publishing prefetch may consume a provider slot.
 *
 * Callers must pass a current, authoritative provider-wide usage snapshot;
 * missing or unbounded capacity deliberately remains deferred rather than
 * treating any playlist-local count as safe external capacity.
 */
class ManagedCacheCapacityService
{
    private const RESERVATION_TTL_SECONDS = 120;

    public function availability(?int $limit, ?int $live, ?int $background): CacheDispatchResult
    {
        if ($limit === null || $limit < 1 || $live === null || $background === null) {
            return CacheDispatchResult::Deferred;
        }

        if ($background > 0 || $live >= $limit - 1) {
            return CacheDispatchResult::Deferred;
        }

        return CacheDispatchResult::Queued;
    }

    /**
     * Atomically reserve the one allowed managed-prefetch slot for an opaque
     * provider account group. The TTL makes worker crashes fail closed until a
     * later attempt can safely retry.
     */
    public function reserve(ManagedCacheCapacitySnapshot $snapshot): ?string
    {
        if ($this->availability($snapshot->limit, $snapshot->live, $snapshot->background) !== CacheDispatchResult::Queued) {
            return null;
        }

        $group = hash('sha256', $snapshot->group);
        $lock = Cache::lock("managed-cache-capacity:{$group}:lock", 10);
        if (! $lock->get()) {
            return null;
        }

        try {
            $activeKey = "managed-cache-capacity:{$group}:active";
            if (Cache::has($activeKey)) {
                return null;
            }

            $reservation = (string) Str::uuid();
            Cache::put($activeKey, $reservation, now()->addSeconds(self::RESERVATION_TTL_SECONDS));

            return $snapshot->group.':'.$reservation;
        } finally {
            $lock->release();
        }
    }

    public function attachToFile(int $fileId, string $reservation): void
    {
        Cache::put("managed-cache-capacity:file:{$fileId}", $reservation, now()->addSeconds(self::RESERVATION_TTL_SECONDS));
    }

    public function reservationForFile(int $fileId): ?string
    {
        $reservation = Cache::get("managed-cache-capacity:file:{$fileId}");

        return is_string($reservation) ? $reservation : null;
    }

    public function canUseReservation(?string $reservation, ManagedCacheCapacitySnapshot $snapshot): bool
    {
        if ($reservation === null || ! str_contains($reservation, ':')) {
            return false;
        }

        [$group, $token] = explode(':', $reservation, 2);
        if (! hash_equals($group, $snapshot->group)
            || $this->availability($snapshot->limit, $snapshot->live, $snapshot->background) !== CacheDispatchResult::Queued) {
            return false;
        }

        $active = Cache::get('managed-cache-capacity:'.hash('sha256', $group).':active');

        return is_string($active) && hash_equals($active, $token);
    }

    public function renew(?string $reservation): bool
    {
        if ($reservation === null || ! str_contains($reservation, ':')) {
            return false;
        }

        [$group, $token] = explode(':', $reservation, 2);
        $group = hash('sha256', $group);
        $lock = Cache::lock("managed-cache-capacity:{$group}:lock", 10);
        if (! $lock->get()) {
            return false;
        }

        try {
            $activeKey = "managed-cache-capacity:{$group}:active";
            if (! hash_equals((string) Cache::get($activeKey), $token)) {
                return false;
            }

            Cache::put($activeKey, $token, now()->addSeconds(self::RESERVATION_TTL_SECONDS));

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Releases only the matching reservation so a stale worker cannot clear a
     * later prefetch's slot.
     */
    public function release(?string $reservation): void
    {
        if ($reservation === null || ! str_contains($reservation, ':')) {
            return;
        }

        [$group, $token] = explode(':', $reservation, 2);
        $group = hash('sha256', $group);
        $lock = Cache::lock("managed-cache-capacity:{$group}:lock", 10);
        if (! $lock->get()) {
            return;
        }

        try {
            $activeKey = "managed-cache-capacity:{$group}:active";
            if (hash_equals((string) Cache::get($activeKey), $token)) {
                Cache::forget($activeKey);
            }
        } finally {
            $lock->release();
        }
    }
}
