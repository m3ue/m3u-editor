<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Models\User;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Series;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class XtreamStreamController extends Controller
{
    /**
     * Authenticates a playlist using username = playlist owner's name, password = playlist UUID.
     */
    private function findAuthenticatedPlaylistAndStreamModel(string $username, string $password, int $streamId, string $streamType): array
    {
        $streamModel = null;
        $playlist = null;

        // Try to find playlist by UUID (password parameter)
        try {
            $playlist = Playlist::with(['user'])->where('uuid', $password)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            try {
                $playlist = MergedPlaylist::with(['user'])->where('uuid', $password)->firstOrFail();
            } catch (ModelNotFoundException $e) {
                try {
                    $playlist = CustomPlaylist::with(['user'])->where('uuid', $password)->firstOrFail();
                } catch (ModelNotFoundException $e) {
                    return [null, null];
                }
            }
        }

        // Verify username matches playlist owner's name
        if ($playlist->user->name !== $username) {
            return [null, null];
        }

        // Get the stream model
        $streamModel = $this->getValidatedStreamFromPlaylist($playlist, $streamId, $streamType);

        return [$playlist, $streamModel];
    }

    /**
     * Validates if a stream (Channel or Episode) exists, is enabled, and belongs to the given authenticated playlist.
     * Returns the stream Model (Channel or Episode) if valid, otherwise null.
     */
    private function getValidatedStreamFromPlaylist(Model $playlist, int $streamId, string $streamType): ?Model
    {
        // Live and VOD streams are handled the same
        if ($streamType === 'live' || $streamType === 'vod') {
            // Assuming all playlist types have a 'channels' relationship defined.
            return $playlist->channels()
                ->where('channels.id', $streamId) // Qualify column name if pivot table involved
                ->where('enabled', true)
                ->first();
        } elseif ($streamType === 'episode') {
            $episode = Episode::with('season.series')->find($streamId);
            if (!$episode) {
                return null; // Episode or its hierarchy not found
            }
            $series = $episode->season()->first()->series ?? null;
            if (!$series) {
                return null; // Series not found
            }
            if (!$series->enabled) {
                return null; // Series is disabled
            }

            // Validate series membership in the playlist.
            // This assumes all playlist types (Playlist, MergedPlaylist, CustomPlaylist)
            // have a 'series' relationship defined that correctly links to App\Models\Series.
            $isMember = $playlist->series()
                ->where('series.id', $series->id) // Qualify column name
                ->exists();

            return $isMember ? $episode : null;
        }
        return null;
    }

    /**
     * Live stream requests.
     * 
     * @tags Xtream API Streams
     * @summary Provides live stream access.
     * @description Authenticates the request based on Xtream credentials provided in the path.
     * If successful and the requested channel is valid and part of an authorized playlist,
     * this endpoint redirects to the actual internal stream URL.
     * The route for this endpoint is typically `/live/{username}/{password}/{streamId}.{format}`.
     *
     * @param \Illuminate\Http\Request $request The HTTP request
     * @param string $uuid The UUID of the Xtream API (path parameter)
     * @param string $username User's Xtream API username (path parameter)
     * @param string $password User's Xtream API password (path parameter)
     * @param string $streamId The ID of the live stream (channel ID) (path parameter)
     * @param string $format The requested stream format (e.g., 'ts', 'm3u8') (path parameter)
     *
     * @response 302 scenario="Successful redirect to stream URL" description="Redirects to the internal live stream URL."
     * @response 403 scenario="Forbidden/Unauthorized" {"error": "Unauthorized or stream not found"}
     * 
     * @unauthenticated
     */
    /**
     * Live stream requests.
     */
    public function handleLive(Request $request, string $username, string $password, int $streamId, string $format = 'ts')
    {
        list($playlist, $channel) = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'live');

        if ($channel instanceof Channel) {
            if ($playlist->enable_proxy) {
                $url = rtrim(route('stream', [
                    'encodedId' => rtrim(base64_encode($streamId), '=')
                ]), '.');
            } else {
                $url = $channel->url_custom ?? $channel->url;
            }
            return Redirect::to($url);
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * VOD stream requests.
     */
    public function handleVod(Request $request, string $username, string $password, string $streamId, string $format = 'ts')
    {
        list($playlist, $channel) = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'vod');

        if ($channel instanceof Channel) {
            if ($playlist->enable_proxy) {
                $url = rtrim(route('stream', [
                    'encodedId' => rtrim(base64_encode($streamId), '=')
                ]), '.');
            } else {
                $url = $channel->url_custom ?? $channel->url;
            }
            return Redirect::to($url);
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * Series episode stream requests.
     */
    public function handleSeries(Request $request, string $username, string $password, int $streamId, string $format = 'mp4')
    {
        list($playlist, $episode) = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'episode');

        if ($episode instanceof Episode) {
            if ($playlist->enable_proxy) {
                $url = rtrim(route('stream.episode', [
                    'encodedId' => rtrim(base64_encode($streamId), '=')
                ]), '.');
            } else {
                $url = $episode->url;
            }
            return Redirect::to($url);
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }
}
