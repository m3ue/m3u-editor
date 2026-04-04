<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Services\ProfileService;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProbeChannelStreams implements ShouldQueue
{
    use ProviderRequestDelay, Queueable;

    public $tries = 1;

    public $timeout = 60 * 60 * 4;

    public $deleteWhenMissingModels = true;

    /**
     * Maximum seconds to wait for a free provider connection slot before probing anyway.
     */
    private const CONNECTION_WAIT_TIMEOUT_SECONDS = 120;

    /**
     * Minimum seconds between consecutive provider-info refreshes during connection checks.
     */
    private const CONNECTION_REFRESH_INTERVAL_SECONDS = 5;

    /**
     * @param  int|null  $playlistId  Probe all enabled live channels for this playlist
     * @param  array<int>|null  $channelIds  Probe specific channel IDs (overrides playlistId)
     * @param  int  $concurrency  Max parallel ffprobe processes
     */
    public function __construct(
        public ?int $playlistId = null,
        public ?array $channelIds = null,
        public int $concurrency = 3,
    ) {}

    public function handle(): void
    {
        $query = Channel::query();

        if ($this->channelIds) {
            $query->whereIn('id', $this->channelIds);
        } elseif ($this->playlistId) {
            $query->where('playlist_id', $this->playlistId)
                ->where('enabled', true)
                ->where('is_vod', false)
                ->where('probe_enabled', true);
        } else {
            Log::warning('ProbeChannelStreams: No playlist or channel IDs provided.');

            return;
        }

        $channels = $query->get();
        $total = $channels->count();

        if ($total === 0) {
            return;
        }

        // Resolve the primary PlaylistProfile for connection-aware probing.
        // This is only used when the playlist has profiles enabled.
        $primaryProfile = $this->resolvePrimaryProfile($channels);

        $probed = 0;
        $failed = 0;
        $isFirstChannel = true;

        foreach ($channels as $channel) {
            // Apply request delay between channels to avoid provider rate limits.
            // The delay is skipped before the very first channel.
            if (! $isFirstChannel) {
                $this->applyProviderRequestDelay();
            }
            $isFirstChannel = false;

            // Before probing, check whether the provider has a free connection slot.
            // If all slots are in use, wait until one becomes available (or time out).
            if ($primaryProfile !== null) {
                $this->waitForAvailableConnectionSlot($primaryProfile);
            }

            $stats = $channel->probeStreamStats();

            if (! empty($stats)) {
                $channel->updateQuietly([
                    'stream_stats' => $stats,
                    'stream_stats_probed_at' => now(),
                ]);
                $probed++;
            } else {
                $failed++;
            }
        }

        Log::info("ProbeChannelStreams: Completed. Probed: {$probed}, Failed: {$failed}, Total: {$total}");

        // Notify the playlist owner
        $playlist = $this->playlistId ? Playlist::find($this->playlistId) : null;
        $user = $playlist?->user ?? ($this->channelIds ? Channel::find($this->channelIds[0])?->user : null);

        if ($user) {
            Notification::make()
                ->success()
                ->title('Stream probing completed')
                ->body("Probed {$probed} of {$total} channels".($failed > 0 ? " ({$failed} failed)" : '').'.')
                ->broadcast($user)
                ->sendToDatabase($user);
        }
    }

    /**
     * Resolve the primary PlaylistProfile to use for connection-aware probing.
     *
     * Returns null when:
     * - No playlist can be determined (e.g. ad-hoc channel ID probing without a playlist context)
     * - The playlist does not have the profiles feature enabled
     * - The playlist has no primary profile configured
     */
    private function resolvePrimaryProfile(Collection $channels): ?PlaylistProfile
    {
        $playlist = null;

        if ($this->playlistId) {
            $playlist = Playlist::find($this->playlistId);
        } elseif ($this->channelIds && $channels->isNotEmpty()) {
            $playlist = $channels->first()->playlist;
        }

        if (! $playlist || ! $playlist->profiles_enabled) {
            return null;
        }

        return $playlist->primaryProfile();
    }

    /**
     * Wait until the given playlist profile has at least one free connection slot,
     * or until the timeout is reached (in which case we proceed anyway to avoid
     * blocking the job indefinitely).
     *
     * Provider info is refreshed at most every CONNECTION_REFRESH_INTERVAL_SECONDS
     * seconds to avoid hammering the provider API while waiting.
     */
    private function waitForAvailableConnectionSlot(PlaylistProfile $profile): void
    {
        // If the provider has never been queried we have no reliable slot data — skip the check.
        if (empty($profile->provider_info)) {
            return;
        }

        // Fast path: slot is already available, no need to refresh.
        $profile->refresh();
        $this->flushProviderInfoCache($profile);

        if ($profile->available_streams > 0) {
            return;
        }

        Log::info("ProbeChannelStreams: No free connection slot for profile {$profile->id}. Waiting…");

        $startTime = time();
        $lastRefresh = time();

        while (true) {
            $elapsed = time() - $startTime;

            if ($elapsed >= self::CONNECTION_WAIT_TIMEOUT_SECONDS) {
                Log::warning("ProbeChannelStreams: Connection slot wait timed out after {$elapsed}s for profile {$profile->id}. Probing anyway.");
                break;
            }

            // Refresh provider info only when the throttle interval has passed.
            if ((time() - $lastRefresh) >= self::CONNECTION_REFRESH_INTERVAL_SECONDS) {
                ProfileService::refreshProfile($profile);
                $this->flushProviderInfoCache($profile);
                $profile->refresh();
                $lastRefresh = time();

                if ($profile->available_streams > 0) {
                    Log::info("ProbeChannelStreams: Connection slot became available for profile {$profile->id} after {$elapsed}s.");
                    break;
                }

                Log::debug("ProbeChannelStreams: Still waiting for connection slot. Active: {$profile->current_connections}/{$profile->effective_max_streams}");
            }

            usleep(500_000); // poll every 500 ms
        }
    }

    /**
     * Clear the per-profile provider_info cache so that the next attribute access
     * re-reads the freshly updated value from the database.
     */
    private function flushProviderInfoCache(PlaylistProfile $profile): void
    {
        Cache::forget("playlist_profile:{$profile->id}:provider_info");
    }
}
