<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the media-server replacement item for a provider VOD movie or
 * series episode at stream start. Returns null (keep the provider stream)
 * unless a match exists, is enabled, and its source is reachable.
 *
 * This is the playback hot path: with prefer_media_server_sources off there
 * are zero extra queries (the toggle check reads the already-loaded
 * playlist). No knowledge of PlaylistAlias/PlaylistProfile lives here —
 * callers use the media item's own playlist as context, so provider
 * alias/profile transforms never touch the media URL.
 */
class MediaSourcePreferenceService
{
    /**
     * Seconds to trust a media server's reachability result. Failures are
     * remembered for less time so a server that comes back isn't ignored
     * for a full minute.
     */
    private const REACHABILITY_CACHE_TTL = 60;

    private const REACHABILITY_FAILURE_CACHE_TTL = 30;

    /**
     * Entry-point helper for the streaming controllers: resolve-and-swap in
     * one call. Returns the media item to stream, or the original item when
     * the provider stream should be kept (callers compare identity to detect
     * a swap).
     */
    public function resolveForStreaming(Channel|Episode $item): Channel|Episode
    {
        return $item instanceof Channel
            ? $this->resolveChannel($item) ?? $item
            : $this->resolveEpisode($item) ?? $item;
    }

    /**
     * The media-server channel to stream for this provider VOD movie, or
     * null to keep the provider stream.
     */
    public function resolveChannel(Channel $channel): ?Channel
    {
        if (! $channel->playlist?->prefer_media_server_sources || ! $channel->is_vod) {
            return null;
        }

        $match = $channel->mediaSourceMatch()->with(['mediaChannel.playlist', 'integration'])->first();
        if (! $match) {
            return null;
        }

        return $this->resolve($match->mediaChannel, $match->integration);
    }

    /**
     * The media-server episode to stream for this provider series episode,
     * or null to keep the provider stream.
     */
    public function resolveEpisode(Episode $episode): ?Episode
    {
        if (! $episode->playlist?->prefer_media_server_sources) {
            return null;
        }

        $match = $episode->mediaSourceMatch()->with(['mediaEpisode.playlist', 'integration'])->first();
        if (! $match) {
            return null;
        }

        return $this->resolve($match->mediaEpisode, $match->integration);
    }

    /**
     * Shared eligibility gate for a resolved media item + its integration.
     */
    private function resolve(Channel|Episode|null $mediaItem, ?MediaServerIntegration $integration): Channel|Episode|null
    {
        if ($mediaItem === null || ! $mediaItem->enabled) {
            return null;
        }

        if ($integration === null || ! $integration->enabled) {
            return null;
        }

        if (! in_array($integration->type, ['emby', 'jellyfin', 'local'], true)) {
            return null;
        }

        if (! $this->isAvailable($mediaItem, $integration)) {
            Log::info('MediaSourcePreferenceService: media source unavailable, keeping provider stream', [
                'media_item_type' => $mediaItem::class,
                'media_item_id' => $mediaItem->id,
                'integration_id' => $integration->id,
                'integration_type' => $integration->type,
            ]);

            return null;
        }

        return $mediaItem;
    }

    /**
     * Can the integration deliver this item right now?
     */
    private function isAvailable(Channel|Episode $mediaItem, MediaServerIntegration $integration): bool
    {
        if ($integration->type === 'local') {
            return $this->isLocalFileReadable($mediaItem);
        }

        return $this->isMediaServerReachable($integration);
    }

    /**
     * Local items store base64_encode($filePath) as info['media_server_id']
     * (see LocalMediaService) — decode and require a readable file.
     */
    private function isLocalFileReadable(Channel|Episode $mediaItem): bool
    {
        $encodedPath = $mediaItem->info['media_server_id'] ?? null;

        if (! is_string($encodedPath) || $encodedPath === '') {
            return false;
        }

        $path = base64_decode($encodedPath, true);

        return $path !== false && $path !== '' && is_readable($path);
    }

    /**
     * Emby/Jellyfin reachability, cached briefly so a burst of playback
     * start requests doesn't hammer the media server. Probed via a dedicated
     * short-timeout, no-retry call — testConnection() can block ~90s on a
     * dead server, which would stall stream start.
     */
    private function isMediaServerReachable(MediaServerIntegration $integration): bool
    {
        $key = "media-server-reachable:{$integration->id}";

        $cached = Cache::get($key);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $service = MediaServerService::make($integration);
        $reachable = $service instanceof EmbyJellyfinService
            ? $service->isReachable()
            : false;

        Cache::put($key, $reachable, $reachable ? self::REACHABILITY_CACHE_TTL : self::REACHABILITY_FAILURE_CACHE_TTL);

        return $reachable;
    }
}
