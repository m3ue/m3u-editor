<?php

/**
 * Xtream player_api.php action=update_progress previously never captured
 * tmdb_id for vod/episode rows, unlike the /api/watch-progress web endpoint
 * (WatchProgressController::update()). A fresh progress row written only
 * through the Xtream path therefore couldn't be relinked by WatchProgressLinker
 * once its stream_id went stale (e.g. after a media-server library flush),
 * even though the Channel/Episode it pointed at already had a TMDB id.
 */

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use App\Services\WatchProgressLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'Test',
        'username' => 'testuser',
        'password' => 'testpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->playlistAuth);

    $this->viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'admin',
        'is_admin' => false,
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);
});

function xtreamUpdateProgressPost(array $body): TestResponse
{
    return test()->postJson(route('xtream.api.player'), $body);
}

it('captures tmdb_id when recording vod progress through the Xtream update_progress action', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'tmdb_id' => 603,
    ]);

    xtreamUpdateProgressPost([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'update_progress',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => (string) $channel->id,
        'position_seconds' => '600',
        'duration_seconds' => '3600',
        'completed' => 'false',
    ])->assertOk();

    $progress = ViewerWatchProgress::where('playlist_viewer_id', $this->viewer->id)->first();
    expect($progress->tmdb_id)->toBe(603);
});

it('captures tmdb_id when recording episode progress through the Xtream update_progress action', function () {
    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'tmdb_id' => 63056,
    ]);

    xtreamUpdateProgressPost([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'update_progress',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'episode',
        'stream_id' => (string) $episode->id,
        'series_id' => (string) $series->id,
        'season_number' => '1',
        'position_seconds' => '120',
        'duration_seconds' => '1800',
        'completed' => 'false',
    ])->assertOk();

    $progress = ViewerWatchProgress::where('playlist_viewer_id', $this->viewer->id)->first();
    expect($progress->tmdb_id)->toBe(63056);
});

it('allows a stale-stream_id row recorded through update_progress to relink after the Channel id changes', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'tmdb_id' => 603,
    ]);

    xtreamUpdateProgressPost([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'update_progress',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => (string) $channel->id,
        'position_seconds' => '600',
        'duration_seconds' => '3600',
        'completed' => 'false',
    ])->assertOk();

    // Simulate the old channel disappearing and a replacement with a new id
    // appearing for the same TMDB movie, like a media-server library flush/resync.
    $channel->delete();
    $replacement = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'tmdb_id' => 603,
    ]);

    $linker = app(WatchProgressLinker::class);
    $progress = ViewerWatchProgress::where('playlist_viewer_id', $this->viewer->id)->first();
    $linker->ensureLinked($progress, $this->playlist);

    expect($progress->fresh()->stream_id)->toBe($replacement->id);
});
