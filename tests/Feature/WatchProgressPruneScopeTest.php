<?php

use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use App\Services\WatchProgressLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeViewerFor(Playlist $playlist, string $username): PlaylistViewer
{
    $auth = PlaylistAuth::create([
        'name' => $username,
        'username' => $username,
        'password' => 'testpass',
        'enabled' => true,
        'user_id' => $playlist->user_id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    return PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => $username,
        'is_admin' => false,
        'playlist_auth_id' => $auth->id,
        'viewerable_type' => $playlist->getMorphClass(),
        'viewerable_id' => $playlist->id,
    ]);
}

it('scopes pruneOrphaned to a single playlist and leaves other playlists untouched', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();

    $viewerA = makeViewerFor($playlistA, 'viewer-a');
    $viewerB = makeViewerFor($playlistB, 'viewer-b');

    $progressA = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewerA->id,
        'content_type' => 'vod',
        'stream_id' => 111,
        'tmdb_id' => null,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);
    $progressB = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewerB->id,
        'content_type' => 'vod',
        'stream_id' => 222,
        'tmdb_id' => null,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    $linker = app(WatchProgressLinker::class);

    $preview = $linker->preview($playlistA);
    expect($preview['checked'])->toBe(1);
    expect($preview['items'][0]['id'])->toBe($progressA->id);

    $stats = $linker->pruneOrphaned($playlistA);
    expect($stats)->toBe(['checked' => 1, 'backfilled' => 0, 'relinked' => 0, 'deleted' => 1]);

    expect(ViewerWatchProgress::find($progressA->id))->toBeNull(); // pruned
    expect(ViewerWatchProgress::find($progressB->id))->not->toBeNull(); // untouched, different playlist
});

it('scopes to a PlaylistAlias, since a PlaylistViewer can belong to one too', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $alias = PlaylistAlias::create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'name' => 'Alias 1',
        'uuid' => (string) Str::uuid(),
    ]);

    $aliasAuth = PlaylistAuth::create([
        'name' => 'alias-auth',
        'username' => 'alias-auth',
        'password' => 'testpass',
        'enabled' => true,
        'user_id' => $user->id,
    ]);

    $aliasViewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'alias-viewer',
        'is_admin' => false,
        'playlist_auth_id' => $aliasAuth->id,
        'viewerable_type' => $alias->getMorphClass(),
        'viewerable_id' => $alias->id,
    ]);

    $progress = ViewerWatchProgress::create([
        'playlist_viewer_id' => $aliasViewer->id,
        'content_type' => 'vod',
        'stream_id' => 333,
        'tmdb_id' => null,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    $linker = app(WatchProgressLinker::class);
    $preview = $linker->preview($alias);

    expect($preview['checked'])->toBe(1);
    expect($preview['items'][0]['id'])->toBe($progress->id);
});

it('never touches aiostreams progress rows, scoped or unscoped', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $viewer = makeViewerFor($playlist, 'aio-viewer');

    $aioProgress = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt1234567',
        'aio_integration_id' => 1,
        'title' => 'Some Movie',
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    $linker = app(WatchProgressLinker::class);

    expect($linker->preview()['checked'])->toBe(0);
    expect($linker->preview($playlist)['checked'])->toBe(0);
    expect($linker->pruneOrphaned())->toBe(['checked' => 0, 'backfilled' => 0, 'relinked' => 0, 'deleted' => 0]);

    expect(ViewerWatchProgress::find($aioProgress->id))->not->toBeNull();
    expect($aioProgress->fresh()->stream_id)->toBeNull();
});
