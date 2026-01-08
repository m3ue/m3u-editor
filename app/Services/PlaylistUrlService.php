<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;

/**
 * Service to handle playlist URL retrieval and alias management.
 */
class PlaylistUrlService
{
    /**
     * Get the effective URL for a channel, considering PlaylistAlias context
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null  $context
     */
    public static function getChannelUrl(Channel $channel, $context = null): string
    {
        // Always prefer custom URL if set (should not be transformed)
        if ($channel->url_custom) {
            return $channel->url_custom;
        }

        // Check if custom channel
        if ($channel->is_custom) {
            // If the URLs are empty, then set the channel to the first failover (if any assigned)
            if (empty($channel->url) && $channel->failoverChannels()->exists()) {
                $channel = $channel->failoverChannels()->first();
            }
        }

        // If context is a PlaylistAlias, transform the URL (custom channels will retain their custom URL)
        if ($context instanceof PlaylistAlias) {
            return $context->transformChannelUrl($channel);
        }

        return $channel->url ?? '';
    }

    /**
     * Get the effective URL for an episode, considering PlaylistAlias context
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null  $context
     */
    public static function getEpisodeUrl(Episode $episode, $context = null): string
    {
        // If context is a PlaylistAlias, transform the URL
        if ($context instanceof PlaylistAlias) {
            return $context->transformEpisodeUrl($episode);
        }

        return $episode->url ?? '';
    }

    /**
     * Resolve the best available PlaylistAlias for streaming
     * This method can be used to implement failover functionality
     */
    public static function getAvailableAlias(Playlist $playlist): PlaylistAlias|Playlist|null
    {
        // First, check if there are any available connections on the main playlist
        $status = $playlist->xtream_status;
        $activeStreams = $status['user_info']['active_cons'] ?? 0;
        $maxStreams = $status['user_info']['max_connections'] ?? 0;
        if ($activeStreams < $maxStreams) {
            return $playlist;
        }

        // Get all enabled aliases ordered by priority
        $aliases = $playlist->enabledAliases()
            ->with('user')
            ->orderBy('priority', 'asc')
            ->get();

        foreach ($aliases as $alias) {
            // Check if this alias has available streams
            $effectivePlaylist = $alias->getEffectivePlaylist();

            // Call the provider (status is cached for 5s in the Playlist model)
            $status = $effectivePlaylist ? $effectivePlaylist->xtream_status : null;
            $activeStreams = $status['user_info']['active_cons'] ?? 0;
            $maxStreams = $status['user_info']['max_connections'] ?? 0;

            // If provider has available capacity return first available alias
            if ($activeStreams < $maxStreams) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * Get the streaming URL for a channel with automatic alias selection
     *
     * @return array ['url' => string, 'context' => Playlist|PlaylistAlias]
     */
    public static function getOptimalChannelStream(Channel $channel): array
    {
        $playlist = $channel->getEffectivePlaylist();

        // First try to find an available alias
        if ($playlist instanceof Playlist) {
            $availableAlias = self::getAvailableAlias($playlist);
            if ($availableAlias) {
                return [
                    'url' => self::getChannelUrl($channel, $availableAlias),
                    'context' => $availableAlias,
                ];
            }
        }

        // Fall back to primary playlist
        return [
            'url' => self::getChannelUrl($channel, $playlist),
            'context' => $playlist,
        ];
    }

    /**
     * Get the streaming URL for an episode with automatic alias selection
     *
     * @return array ['url' => string, 'context' => Playlist|PlaylistAlias]
     */
    public static function getOptimalEpisodeStream(Episode $episode): array
    {
        $playlist = $episode->getEffectivePlaylist();

        // First try to find an available alias
        if ($playlist instanceof Playlist) {
            $availableAlias = self::getAvailableAlias($playlist);
            if ($availableAlias) {
                return [
                    'url' => self::getEpisodeUrl($episode, $availableAlias),
                    'context' => $availableAlias,
                ];
            }
        }

        // Fall back to primary playlist
        return [
            'url' => self::getEpisodeUrl($episode, $playlist),
            'context' => $playlist,
        ];
    }
}
