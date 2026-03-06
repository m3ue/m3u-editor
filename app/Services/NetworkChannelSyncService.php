<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Group;
use App\Models\Network;
use App\Models\Playlist;
use Illuminate\Support\Facades\Log;

/**
 * Service to sync Networks as live channels in playlists.
 */
class NetworkChannelSyncService
{
    /**
     * Sync all networks to their associated playlist.
     */
    public function syncNetworksToPlaylist(Playlist $playlist): int
    {
        if (! $playlist->include_networks_in_m3u) {
            // Remove any existing network channels if disabled
            $this->removeNetworkChannels($playlist);

            return 0;
        }

        $networks = $playlist->getNetworks();

        if ($networks->isEmpty()) {
            return 0;
        }

        $synced = 0;
        $existingNetworkIds = [];

        foreach ($networks as $network) {
            $this->syncNetworkAsChannel($playlist, $network);
            $existingNetworkIds[] = $network->id;
            $synced++;
        }

        // Remove channels for networks that no longer exist or are disabled
        Channel::where('playlist_id', $playlist->id)
            ->whereNotNull('network_id')
            ->whereNotIn('network_id', $existingNetworkIds)
            ->delete();

        Log::info("Synced {$synced} networks to playlist", [
            'playlist_id' => $playlist->id,
            'playlist_name' => $playlist->name,
        ]);

        return $synced;
    }

    /**
     * Sync a single network as a channel.
     */
    public function syncNetworkAsChannel(Playlist $playlist, Network $network, ?Group $group = null): Channel
    {
        if (! $group) {
            $group = $this->getOrCreateNetworksGroup($playlist, $network);
        }

        $channel = Channel::updateOrCreate(
            [
                'playlist_id' => $playlist->id,
                'network_id' => $network->id,
            ],
            [
                'user_id' => $playlist->user_id,
                'name' => $network->name,
                'title' => $network->name,
                'url' => $network->stream_url,
                'logo' => $network->logo,
                'group_id' => $group->id,
                'group_internal' => $group->name,
                'channel' => $network->channel_number,
                'enabled' => $network->enabled,
                'is_vod' => false,
                'stream_id' => 'network-'.$network->id,
                'source_id' => 'network-'.$network->id,
            ]
        );

        return $channel;
    }

    /**
     * Remove all network channels from a playlist.
     */
    public function removeNetworkChannels(Playlist $playlist): int
    {
        return Channel::where('playlist_id', $playlist->id)
            ->whereNotNull('network_id')
            ->delete();
    }

    /**
     * Get or create the group for a network within a playlist.
     * Uses the network's configured group_name, falling back to "Networks".
     */
    protected function getOrCreateNetworksGroup(Playlist $playlist, ?Network $network = null): Group
    {
        $groupName = $network?->effective_group_name ?? 'Networks';

        return Group::firstOrCreate(
            [
                'playlist_id' => $playlist->id,
                'name' => $groupName,
            ],
            [
                'user_id' => $playlist->user_id,
                'name_internal' => $groupName,
                'enabled' => true,
            ]
        );
    }

    /**
     * Refresh network channel after schedule regeneration.
     */
    public function refreshNetworkChannel(Network $network): void
    {
        // Find all channels for this network
        $channels = Channel::where('network_id', $network->id)->get();

        foreach ($channels as $channel) {
            // Resolve the correct group for this network in the channel's playlist
            $group = $this->getOrCreateNetworksGroup(
                Playlist::find($channel->playlist_id),
                $network
            );

            // Update the channel with latest network data including group
            $channel->update([
                'name' => $network->name,
                'title' => $network->name,
                'url' => $network->stream_url,
                'logo' => $network->logo,
                'channel' => $network->channel_number,
                'enabled' => $network->enabled,
                'group_id' => $group->id,
                'group_internal' => $group->name,
            ]);
        }
    }
}
