<?php

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Jobs\ProcessM3uImport;
use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([SyncMediaServer::class]);
    $this->user = User::factory()->create();
});

it('redirects Plex playlist to SyncMediaServer instead of M3U import', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Plex,
        'auto_sync' => false,
        'status' => Status::Pending,
    ]);

    MediaServerIntegration::create([
        'name' => 'My Plex',
        'type' => 'plex',
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'host' => 'localhost',
        'port' => 32400,
        'api_key' => 'test-token',
    ]);

    dispatch(new ProcessM3uImport($playlist));

    Queue::assertPushed(SyncMediaServer::class);
});

it('redirects LocalMedia playlist to SyncMediaServer instead of M3U import', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::LocalMedia,
        'auto_sync' => false,
        'status' => Status::Pending,
    ]);

    MediaServerIntegration::create([
        'name' => 'My Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'local_media_paths' => [
            ['path' => '/media/movies', 'type' => 'movies', 'name' => 'Movies'],
        ],
    ]);

    dispatch(new ProcessM3uImport($playlist));

    Queue::assertPushed(SyncMediaServer::class);
});

it('redirects WebDAV playlist (LocalMedia source type) to SyncMediaServer instead of M3U import', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::LocalMedia,
        'auto_sync' => false,
        'status' => Status::Pending,
    ]);

    MediaServerIntegration::create([
        'name' => 'My WebDAV',
        'type' => 'webdav',
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'host' => 'nas.local',
        'port' => 5005,
        'webdav_username' => 'user',
        'webdav_password' => 'pass',
    ]);

    dispatch(new ProcessM3uImport($playlist));

    Queue::assertPushed(SyncMediaServer::class);
});

it('still redirects Emby playlists to SyncMediaServer', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Emby,
        'auto_sync' => false,
        'status' => Status::Pending,
    ]);

    MediaServerIntegration::create([
        'name' => 'My Emby',
        'type' => 'emby',
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'host' => 'emby.local',
        'port' => 8096,
        'api_key' => 'test-key',
    ]);

    dispatch(new ProcessM3uImport($playlist));

    Queue::assertPushed(SyncMediaServer::class);
});

it('still redirects Jellyfin playlists to SyncMediaServer', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => PlaylistSourceType::Jellyfin,
        'auto_sync' => false,
        'status' => Status::Pending,
    ]);

    MediaServerIntegration::create([
        'name' => 'My Jellyfin',
        'type' => 'jellyfin',
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'host' => 'jellyfin.local',
        'port' => 8096,
        'api_key' => 'test-key',
    ]);

    dispatch(new ProcessM3uImport($playlist));

    Queue::assertPushed(SyncMediaServer::class);
});
