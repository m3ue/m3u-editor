<?php

use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Regression: the Flutter client sends 'completed' as the string 'false' because
 * it converts all body values to strings before posting. PHP's (bool) cast treats
 * any non-empty string as true, so 'false' → true caused every VOD/episode update
 * to be immediately marked completed.
 */
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
        'is_admin' => true,
        'viewerable_type' => Playlist::class,
        'viewerable_id' => $this->playlist->id,
    ]);
});

function xtreamPost(array $body): TestResponse
{
    return test()->postJson(route('xtream.api.player'), $body);
}

it('does not mark vod as completed when client sends the string false', function () {
    xtreamPost([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'update_progress',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => '999',
        'position_seconds' => '60',
        'duration_seconds' => '3600',
        'completed' => 'false',
    ])->assertOk();

    expect(ViewerWatchProgress::where('playlist_viewer_id', $this->viewer->id)->first())
        ->completed->toBeFalse();
});

it('does not mark episode as completed when client sends the string false', function () {
    xtreamPost([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'update_progress',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'episode',
        'stream_id' => '999',
        'series_id' => '1',
        'season_number' => '1',
        'position_seconds' => '120',
        'duration_seconds' => '1800',
        'completed' => 'false',
    ])->assertOk();

    expect(ViewerWatchProgress::where('playlist_viewer_id', $this->viewer->id)->first())
        ->completed->toBeFalse();
});

it('auto marks episode completed when position reaches 90 percent of duration', function () {
    xtreamPost([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'update_progress',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'episode',
        'stream_id' => '999',
        'series_id' => '1',
        'season_number' => '1',
        'position_seconds' => '1700',
        'duration_seconds' => '1800',
        'completed' => 'false',
    ])->assertOk();

    expect(ViewerWatchProgress::where('playlist_viewer_id', $this->viewer->id)->first())
        ->completed->toBeTrue();
});

it('respects explicit completed true from client', function () {
    xtreamPost([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'update_progress',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => '999',
        'position_seconds' => '10',
        'duration_seconds' => '3600',
        'completed' => 'true',
    ])->assertOk();

    expect(ViewerWatchProgress::where('playlist_viewer_id', $this->viewer->id)->first())
        ->completed->toBeTrue();
});

it('does not mark vod as completed via merged playlist when client sends string false', function () {
    $merged = MergedPlaylist::factory()->for($this->user)->create();

    $mergedAuth = PlaylistAuth::create([
        'name' => 'Merged',
        'username' => 'mergeduser',
        'password' => 'mergedpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $merged->playlistAuths()->attach($mergedAuth);

    $mergedViewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'admin',
        'is_admin' => true,
        'viewerable_type' => MergedPlaylist::class,
        'viewerable_id' => $merged->id,
    ]);

    xtreamPost([
        'username' => 'mergeduser',
        'password' => 'mergedpass',
        'action' => 'update_progress',
        'viewer_id' => $mergedViewer->ulid,
        'content_type' => 'vod',
        'stream_id' => '999',
        'position_seconds' => '60',
        'duration_seconds' => '3600',
        'completed' => 'false',
    ])->assertOk();

    expect(ViewerWatchProgress::where('playlist_viewer_id', $mergedViewer->id)->first())
        ->completed->toBeFalse();
});
