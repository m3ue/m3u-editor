<?php

use App\Filament\Resources\PlaylistViewers\Pages\ListPlaylistViewers;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('relinks orphaned watch progress from the Playlist Viewers header action', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test',
        'username' => 'testuser',
        'password' => 'testpass',
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($playlistAuth);

    $viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'admin',
        'is_admin' => false,
        'playlist_auth_id' => $playlistAuth->id,
        'viewerable_type' => $playlist->getMorphClass(),
        'viewerable_id' => $playlist->id,
    ]);

    $newChannel = Channel::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'tmdb_id' => 550,
    ]);

    $progress = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'vod',
        'stream_id' => 999999, // stale id
        'tmdb_id' => 550,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    test()->actingAs($user);

    Livewire::test(ListPlaylistViewers::class)
        ->callAction('relinkWatchProgress')
        ->assertNotified();

    expect($progress->fresh()->stream_id)->toBe($newChannel->id);
});

it('previews without mutating anything', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test',
        'username' => 'testuser',
        'password' => 'testpass',
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($playlistAuth);

    $viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'admin',
        'is_admin' => false,
        'playlist_auth_id' => $playlistAuth->id,
        'viewerable_type' => $playlist->getMorphClass(),
        'viewerable_id' => $playlist->id,
    ]);

    $newChannel = Channel::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'tmdb_id' => 550,
    ]);

    $progress = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'vod',
        'stream_id' => 999999, // stale id
        'tmdb_id' => 550,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    test()->actingAs($user);

    Livewire::test(ListPlaylistViewers::class)
        ->callAction('previewWatchProgressRelink')
        ->assertNotified();

    // Still stale - preview must never persist changes.
    expect($progress->fresh()->stream_id)->toBe(999999);
});

it('scopes the header action to the selected playlist via the scope field', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();

    foreach ([$playlistA, $playlistB] as $i => $playlist) {
        $auth = PlaylistAuth::create([
            'name' => "viewer-{$i}",
            'username' => "viewer-{$i}",
            'password' => 'testpass',
            'enabled' => true,
            'user_id' => $user->id,
        ]);
        $playlist->playlistAuths()->attach($auth);
        ${'viewer'.$i} = PlaylistViewer::create([
            'ulid' => (string) Str::ulid(),
            'name' => "viewer-{$i}",
            'is_admin' => false,
            'playlist_auth_id' => $auth->id,
            'viewerable_type' => $playlist->getMorphClass(),
            'viewerable_id' => $playlist->id,
        ]);
    }

    $progressA = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer0->id,
        'content_type' => 'vod',
        'stream_id' => 111,
        'tmdb_id' => null,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);
    $progressB = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer1->id,
        'content_type' => 'vod',
        'stream_id' => 222,
        'tmdb_id' => null,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    test()->actingAs($user);

    Livewire::test(ListPlaylistViewers::class)
        ->mountAction('relinkWatchProgress')
        ->setActionData(['playlist_scope' => Playlist::class.'|'.$playlistA->id])
        ->callMountedAction()
        ->assertNotified();

    expect(ViewerWatchProgress::find($progressA->id))->toBeNull(); // pruned - in scope
    expect(ViewerWatchProgress::find($progressB->id))->not->toBeNull(); // untouched - out of scope
});
