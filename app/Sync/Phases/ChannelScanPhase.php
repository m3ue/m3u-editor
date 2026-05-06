<?php

namespace App\Sync\Phases;

use App\Jobs\MergeChannels;
use App\Jobs\ProbeChannelStreams;
use App\Jobs\ProcessChannelScrubber;
use App\Models\Playlist;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Bus;

/**
 * Phase 2 of the post-sync pipeline: merge duplicate/failover channels, run
 * recurring channel scrubbers, then probe the resulting channel streams.
 *
 * These three steps are bundled into one phase because they must run as a
 * single Bus::chain in strict order: merge mutates the channel list,
 * scrubbers enable/disable channels against that list, and probe only runs
 * against enabled channels. Each sub-step is optional; the chain only
 * dispatches if at least one is enabled.
 *
 * Merge uses stream_id (not titles) so it can run in parallel with name
 * processing — the orchestrator may schedule this phase concurrently with
 * {@see FindReplaceAndSortAlphaPhase} once that work lands.
 */
class ChannelScanPhase extends AbstractPhase
{
    public static function slug(): string
    {
        return 'channel_scan';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        return ($playlist->auto_merge_channels_enabled ?? false)
            || $playlist->channelScrubbers()->where('recurring', true)->exists()
            || ($playlist->auto_probe_streams ?? false);
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $chain = [];

        if ($playlist->auto_merge_channels_enabled ?? false) {
            $chain[] = $this->buildMergeJob($playlist);
        }

        $scrubberJobs = $playlist->channelScrubbers()
            ->where('recurring', true)
            ->get()
            ->map(fn ($scrubber) => new ProcessChannelScrubber($scrubber->id))
            ->all();

        if (count($scrubberJobs) > 0) {
            $chain = array_merge($chain, $scrubberJobs);
        }

        if ($playlist->auto_probe_streams ?? false) {
            $chain[] = new ProbeChannelStreams(playlistId: $playlist->id);
        }

        if (count($chain) > 0) {
            Bus::chain($chain)->dispatch();
        }

        return ['channel_scan_chain_size' => count($chain)];
    }

    private function buildMergeJob(Playlist $playlist): MergeChannels
    {
        $config = $playlist->auto_merge_config ?? [];
        $useResolution = $config['check_resolution'] ?? false;
        $forceCompleteRemerge = $config['force_complete_remerge'] ?? false;
        $preferCatchupAsPrimary = $config['prefer_catchup_as_primary'] ?? false;
        $newChannelsOnly = $config['new_channels_only'] ?? true;
        $preferredPlaylistId = $config['preferred_playlist_id'] ?? null;
        $failoverPlaylists = $config['failover_playlists'] ?? [];
        $deactivateFailover = (bool) ($playlist->auto_merge_deactivate_failover ?? false);

        $playlists = collect([['playlist_failover_id' => $playlist->id]]);

        foreach ($failoverPlaylists as $failover) {
            $failoverId = is_array($failover) ? ($failover['playlist_failover_id'] ?? null) : $failover;
            if ($failoverId && $failoverId != $playlist->id) {
                $playlists->push(['playlist_failover_id' => $failoverId]);
            }
        }

        $effectivePlaylistId = $preferredPlaylistId ? (int) $preferredPlaylistId : $playlist->id;

        return new MergeChannels(
            user: $playlist->user,
            playlists: $playlists,
            playlistId: $effectivePlaylistId,
            checkResolution: $useResolution,
            deactivateFailoverChannels: $deactivateFailover,
            forceCompleteRemerge: $forceCompleteRemerge,
            preferCatchupAsPrimary: $preferCatchupAsPrimary,
            weightedConfig: $this->buildWeightedConfig($config),
            newChannelsOnly: $newChannelsOnly,
            regexPatterns: ! empty($config['regex_patterns'] ?? []) ? $config['regex_patterns'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function buildWeightedConfig(array $config): ?array
    {
        $hasWeightedOptions = ! empty($config['priority_attributes'])
            || ! empty($config['group_priorities'])
            || ! empty($config['priority_keywords'])
            || isset($config['prefer_codec'])
            || ($config['exclude_disabled_groups'] ?? false);

        if (! $hasWeightedOptions) {
            return null;
        }

        return [
            'priority_attributes' => $config['priority_attributes'] ?? null,
            'group_priorities' => $config['group_priorities'] ?? [],
            'priority_keywords' => $config['priority_keywords'] ?? [],
            'prefer_codec' => $config['prefer_codec'] ?? null,
            'exclude_disabled_groups' => $config['exclude_disabled_groups'] ?? false,
        ];
    }
}
