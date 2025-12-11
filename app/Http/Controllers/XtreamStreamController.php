<?php

namespace App\Http\Controllers;

use App\Models\PlaylistAuth;
use App\Models\Playlist;
use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\PlaylistAlias;
use App\Services\PlaylistService;
use App\Services\PlaylistUrlService;
use App\Services\ProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class XtreamStreamController extends Controller
{
    /**
     * Authenticates a playlist using either PlaylistAuth credentials or the original method
     * (username = playlist owner's name, password = playlist UUID).
     */
    private function findAuthenticatedPlaylistAndStreamModel(string $username, string $password, string|int $streamId, string $streamType): array
    {
        $streamModel = null;
        $playlist = null;

        // Method 1: Try to authenticate using PlaylistAuth credentials
        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->first();

        if ($playlistAuth) {
            $playlist = $playlistAuth->getAssignedModel();
            if ($playlist) {
                // Load necessary relationships for the playlist
                $playlist->load(['user']);
            }
        }

        // Method 2: Fall back to original authentication (username = playlist owner, password = playlist UUID)
        if (!$playlist) {
            // Try to find playlist by UUID (password parameter)
            try {
                $playlist = Playlist::with(['user'])->where('uuid', $password)->firstOrFail();

                // Verify username matches playlist owner's name
                if ($playlist->user->name !== $username) {
                    $playlist = null;
                }
            } catch (ModelNotFoundException $e) {
                try {
                    $playlist = MergedPlaylist::with(['user'])->where('uuid', $password)->firstOrFail();

                    // Verify username matches playlist owner's name
                    if ($playlist->user->name !== $username) {
                        $playlist = null;
                    }
                } catch (ModelNotFoundException $e) {
                    try {
                        $playlist = CustomPlaylist::with(['user'])->where('uuid', $password)->firstOrFail();

                        // Verify username matches playlist owner's name
                        if ($playlist->user->name !== $username) {
                            $playlist = null;
                        }
                    } catch (ModelNotFoundException $e) {
                        try {
                            $playlist = PlaylistAlias::with(['user'])
                                ->where('uuid', $password)
                                ->orWhere(fn($query) => $query->where([
                                    ['username', $username],
                                    ['password', $password],
                                ]))->firstOrFail();

                            // If username and password do not match directly, then username must match playlist owner's name
                            if (!($playlist->username === $username && $playlist->password === $password)) {
                                // Verify username matches playlist owner's name
                                if ($playlist->user->name !== $username) {
                                    $playlist = null;
                                }
                            }
                        } catch (ModelNotFoundException $e) {
                            return [null, null];
                        }
                    }
                }
            }
        }

        // If no authentication method worked, return null
        if (!$playlist) {
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
    private function getValidatedStreamFromPlaylist(Model $playlist, string|int $streamId, string $streamType): ?Model
    {
        // Live and VOD streams are handled the same
        if ($streamType === 'live' || $streamType === 'vod' || $streamType === 'timeshift') {
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
     * Handle direct stream requests.
     *
     * Determine best path when `/live/`, `/movie/`, or `/series/` is not specified.
     */
    public function handleDirect(Request $request, string $username, string $password, string|int $streamId, ?string $format = null)
    {
        // If no live or VOD stream type specified, determine stream type by model
        $model = Channel::find($streamId);
        if ($model instanceof Channel) {
            if ($model->is_vod) {
                return $this->handleVod($request, $username, $password, $streamId, $format);
            } else {
                return $this->handleLive($request, $username, $password, $streamId, $format);
            }
        }
        $model = Episode::find($streamId);
        if ($model instanceof Episode) {
            return $this->handleSeries($request, $username, $password, $streamId, $format);
        }

        return response()->json(['error' => 'Stream not found'], 404);
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
    public function handleLive(Request $request, string $username, string $password, string|int $streamId, ?string $format = null)
    {
        $format = $format ?? 'ts'; // Default to 'ts' if no format provided
        list($playlist, $channel) = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'live');
        if ($channel instanceof Channel) {
            if ($playlist->enable_proxy) {
                return app()->call('App\\Http\\Controllers\\Api\\M3uProxyApiController@channel', [
                    'id'   => $streamId,
                    'uuid' => $playlist->uuid,
                ]);
            } else {
                return Redirect::to(PlaylistUrlService::getChannelUrl($channel, $playlist));
            }
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * VOD stream requests.
     */
    public function handleVod(Request $request, string $username, string $password, string $streamId, ?string $format = null)
    {
        $format = $format ?? 'ts'; // Default to 'ts' if no format provided
        list($playlist, $channel) = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'vod');
        if ($channel instanceof Channel) {
            if ($playlist->enable_proxy) {
                return app()->call('App\\Http\\Controllers\\Api\\M3uProxyApiController@channel', [
                    'id'   => $streamId,
                    'uuid' => $playlist->uuid,
                ]);
            } else {
                return Redirect::to(PlaylistUrlService::getChannelUrl($channel, $playlist));
            }
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * Series episode stream requests.
     */
    public function handleSeries(Request $request, string $username, string $password, string|int $streamId, ?string $format = null)
    {
        $format = $format ?? 'mp4'; // Default to 'mp4' if no format provided
        list($playlist, $episode) = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'episode');
        if ($episode instanceof Episode) {
            if ($playlist->enable_proxy) {
                return app()->call('App\\Http\\Controllers\\Api\\M3uProxyApiController@episode', [
                    'id'   => $streamId,
                    'uuid' => $playlist->uuid,
                ]);
            } else {
                return Redirect::to(PlaylistUrlService::getEpisodeUrl($episode, $playlist));
            }
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * Timeshift stream requests.
     * 
     * @tags Xtream API Streams
     * @summary Provides timeshift streaming access for live channels.
     * @description Handles Xtream API timeshift requests. Authenticates the request based on 
     * Xtream credentials provided in the path. If successful and the requested channel is valid 
     * and part of an authorized playlist, this endpoint provides timeshift access to replay
     * content from a specific date and time.
     * 
     * The route for this endpoint is typically `/timeshift/{username}/{password}/{duration}/{date}/{streamId}.{format}`.
     * 
     * @param \Illuminate\Http\Request $request The HTTP request
     * @param string $username User's Xtream API username (path parameter)
     * @param string $password User's Xtream API password (path parameter)
     * @param int $duration Duration of timeshift in minutes (path parameter)
     * @param string $date Date and time in format YYYY-MM-DD:HH-MM-SS (path parameter)
     * @param int $streamId The ID of the live stream (channel ID) (path parameter)
     * @param string $format The requested stream format (e.g., 'ts', 'm3u8') (path parameter)
     *
     * @response 302 scenario="Successful redirect to timeshift stream URL" description="Redirects to the internal timeshift stream URL."
     * @response 403 scenario="Forbidden/Unauthorized" {"error": "Unauthorized or stream not found"}
     * 
     * @unauthenticated
     */
    public function handleTimeshift(Request $request, string $username, string $password, int $duration, string $date, string|int $streamId, ?string $format = null)
    {
        $format = $format ?? 'ts'; // Default to 'ts' if no format provided

        // Timeshift is only available for live channels
        list($playlist, $channel) = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'timeshift');

        if (!($channel instanceof Channel)) {
            return response()->json(['error' => 'Unauthorized or stream not found'], 403);
        }

        // Parse the date parameter and add timeshift parameters to the request
        // Date format from Xtream API: YYYY-MM-DD:HH-MM-SS
        $request->merge([
            'timeshift_duration' => $duration,
            'timeshift_date' => $date,
        ]);

        if ($playlist->enable_proxy) {
            return app()->call('App\\Http\\Controllers\\Api\\M3uProxyApiController@channel', [
                'id'   => $streamId,
                'uuid' => $playlist->uuid,
            ]);
        } else {
            $streamUrl = PlaylistUrlService::getChannelUrl($channel, $playlist);
            $streamUrl = PlaylistService::generateTimeshiftUrl($request, $streamUrl, $playlist);
            return Redirect::to($streamUrl);
        }
    }
}
