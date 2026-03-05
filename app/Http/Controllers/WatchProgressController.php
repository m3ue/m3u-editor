<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\ViewerWatchProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WatchProgressController extends Controller
{
    /**
     * Fetch existing watch progress for a stream.
     * Returns null if no progress or viewer can't be resolved.
     */
    public function fetch(Request $request): JsonResponse
    {
        $viewer = $this->resolveViewer($request);
        if (! $viewer) {
            return response()->json(null);
        }

        $contentType = $request->input('content_type');
        $streamId = (int) $request->input('stream_id');

        if (! $contentType || ! $streamId) {
            return response()->json(null);
        }

        $progress = ViewerWatchProgress::where('playlist_viewer_id', $viewer->id)
            ->where('content_type', $contentType)
            ->where('stream_id', $streamId)
            ->first(['position_seconds', 'duration_seconds', 'completed', 'watch_count', 'last_watched_at']);

        return response()->json($progress);
    }

    /**
     * Create or update watch progress for a stream.
     *
     * For live: records a tune-in (increments watch_count).
     * For vod/episode: periodically updates position and duration.
     */
    public function update(Request $request): JsonResponse
    {
        $viewer = $this->resolveViewer($request);
        if (! $viewer) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $contentType = $request->input('content_type');
        $streamId = (int) $request->input('stream_id');

        if (! $contentType || ! $streamId) {
            return response()->json(['error' => 'content_type and stream_id are required'], 400);
        }

        if ($contentType === 'live') {
            $progress = ViewerWatchProgress::firstOrCreate(
                [
                    'playlist_viewer_id' => $viewer->id,
                    'content_type' => 'live',
                    'stream_id' => $streamId,
                ],
                ['watch_count' => 0, 'last_watched_at' => now()]
            );
            $progress->increment('watch_count');
            $progress->update(['last_watched_at' => now()]);

            return response()->json($progress->fresh(['watch_count', 'last_watched_at']));
        }

        $positionSeconds = (int) $request->input('position_seconds', 0);
        $durationSeconds = $request->input('duration_seconds') !== null
            ? (int) $request->input('duration_seconds')
            : null;
        $seriesId = $request->input('series_id') ? (int) $request->input('series_id') : null;
        $seasonNumber = $request->input('season_number') ? (int) $request->input('season_number') : null;

        $completed = (bool) $request->input('completed', false);
        if (! $completed && $durationSeconds && $durationSeconds > 0) {
            $completed = $positionSeconds >= ($durationSeconds * 0.9);
        }

        $progress = ViewerWatchProgress::updateOrCreate(
            [
                'playlist_viewer_id' => $viewer->id,
                'content_type' => $contentType,
                'stream_id' => $streamId,
            ],
            [
                'series_id' => $seriesId,
                'season_number' => $seasonNumber,
                'position_seconds' => $positionSeconds,
                'duration_seconds' => $durationSeconds,
                'completed' => $completed,
                'last_watched_at' => now(),
            ]
        );

        // Increment watch count once per new play session (fresh record only)
        if ($progress->wasRecentlyCreated) {
            $progress->increment('watch_count');
        }

        return response()->json($progress->fresh(['position_seconds', 'duration_seconds', 'completed', 'watch_count']));
    }

    /**
     * Resolve the current PlaylistViewer from the request, auto-creating if needed.
     *
     * Supports:
     * - Admin users (standard Laravel web auth → admin PlaylistViewer, created on first watch)
     * - Guest panel users (session PlaylistAuth → linked PlaylistViewer, created on first watch)
     */
    private function resolveViewer(Request $request): ?PlaylistViewer
    {
        $contentType = $request->input('content_type');
        $streamId = (int) $request->input('stream_id');
        $playlistId = (int) $request->input('playlist_id');

        $playlist = $playlistId
            ? Playlist::find($playlistId)
            : $this->resolvePlaylistFromContent($contentType, $streamId);

        if (! $playlist) {
            return null;
        }

        // Admin panel: standard Laravel auth — find or create the admin viewer
        if (auth()->check()) {
            $user = auth()->user();

            return PlaylistViewer::firstOrCreate(
                [
                    'viewerable_type' => Playlist::class,
                    'viewerable_id' => $playlist->id,
                    'is_admin' => true,
                ],
                [
                    'ulid' => (string) Str::ulid(),
                    'name' => $user->name,
                ]
            );
        }

        // Guest panel: session-based auth keyed by playlist UUID
        $prefix = base64_encode($playlist->uuid).'_';
        $username = session("{$prefix}guest_auth_username");
        $password = session("{$prefix}guest_auth_password");

        if ($username && $password) {
            $playlistAuth = PlaylistAuth::where('username', $username)
                ->where('password', $password)
                ->first();

            if ($playlistAuth) {
                return PlaylistViewer::firstOrCreate(
                    [
                        'playlist_auth_id' => $playlistAuth->id,
                        'viewerable_type' => Playlist::class,
                        'viewerable_id' => $playlist->id,
                    ],
                    [
                        'ulid' => (string) Str::ulid(),
                        'name' => $playlistAuth->name,
                        'is_admin' => false,
                    ]
                );
            }
        }

        return null;
    }

    private function resolvePlaylistFromContent(string $contentType, int $streamId): ?Playlist
    {
        if ($contentType === 'episode') {
            $playlistId = Episode::where('id', $streamId)->value('playlist_id');
        } else {
            $playlistId = Channel::where('id', $streamId)->value('playlist_id');
        }

        return $playlistId ? Playlist::find($playlistId) : null;
    }
}
