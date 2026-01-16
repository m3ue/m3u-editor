<?php

use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can create a media server integration', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'My Jellyfin Server',
        'type' => 'jellyfin',
        'host' => 'jellyfin.example.com',
        'port' => 8096,
        'api_key' => 'secret-key-123',
        'enabled' => true,
        'ssl' => true,
        'genre_handling' => 'primary',
        'import_movies' => true,
        'import_series' => true,
        'auto_sync' => true,
        'sync_interval' => '0 */6 * * *',
        'user_id' => $this->user->id,
    ]);

    expect($integration)->toBeInstanceOf(MediaServerIntegration::class);
    expect($integration->name)->toBe('My Jellyfin Server');
    expect($integration->type)->toBe('jellyfin');
    expect($integration->ssl)->toBeTrue();
    expect($integration->status)->toBe('idle'); // Default status
});

it('has correct default values', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'emby',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($integration->port)->toBe(8096);
    expect($integration->enabled)->toBeTrue();
    expect($integration->ssl)->toBeFalse();
    expect($integration->genre_handling)->toBe('primary');
    expect($integration->import_movies)->toBeTrue();
    expect($integration->import_series)->toBeTrue();
    expect($integration->auto_sync)->toBeTrue();
    expect($integration->status)->toBe('idle');
    expect($integration->progress)->toBe(0);
    expect($integration->movie_progress)->toBe(0);
    expect($integration->series_progress)->toBe(0);
});

it('belongs to a user', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($integration->user)->toBeInstanceOf(User::class);
    expect($integration->user->id)->toBe($this->user->id);
});

it('can belong to a playlist', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    expect($integration->playlist)->toBeInstanceOf(Playlist::class);
    expect($integration->playlist->id)->toBe($playlist->id);
});

it('generates correct base URL with HTTP', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($integration->base_url)->toBe('http://192.168.1.100:8096');
});

it('generates correct base URL with HTTPS', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => 'jellyfin.example.com',
        'port' => 443,
        'ssl' => true,
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($integration->base_url)->toBe('https://jellyfin.example.com:443');
});

it('can check if server is Emby', function () {
    $emby = MediaServerIntegration::create([
        'name' => 'Emby Server',
        'type' => 'emby',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    $jellyfin = MediaServerIntegration::create([
        'name' => 'Jellyfin Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.101',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($emby->isEmby())->toBeTrue();
    expect($emby->isJellyfin())->toBeFalse();
    expect($jellyfin->isEmby())->toBeFalse();
    expect($jellyfin->isJellyfin())->toBeTrue();
});

it('can scope to enabled integrations only', function () {
    MediaServerIntegration::create([
        'name' => 'Enabled Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'enabled' => true,
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    MediaServerIntegration::create([
        'name' => 'Disabled Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.101',
        'enabled' => false,
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    $enabled = MediaServerIntegration::enabled()->get();

    expect($enabled->count())->toBe(1);
    expect($enabled->first()->name)->toBe('Enabled Server');
});

it('casts sync_stats to array', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'sync_stats' => [
            'movies_synced' => 100,
            'series_synced' => 50,
            'episodes_synced' => 500,
            'errors' => [],
        ],
    ]);

    expect($integration->sync_stats)->toBeArray();
    expect($integration->sync_stats['movies_synced'])->toBe(100);
    expect($integration->sync_stats['series_synced'])->toBe(50);
});

it('casts last_synced_at to datetime', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'last_synced_at' => now(),
    ]);

    expect($integration->last_synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('hides api_key from serialization', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'super-secret-key',
        'user_id' => $this->user->id,
    ]);

    $array = $integration->toArray();

    expect($array)->not->toHaveKey('api_key');
});

it('can update progress fields', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    $integration->update([
        'status' => 'processing',
        'progress' => 50,
        'movie_progress' => 75,
        'series_progress' => 25,
        'total_movies' => 100,
        'total_series' => 50,
    ]);

    $integration->refresh();

    expect($integration->status)->toBe('processing');
    expect($integration->progress)->toBe(50);
    expect($integration->movie_progress)->toBe(75);
    expect($integration->series_progress)->toBe(25);
    expect($integration->total_movies)->toBe(100);
    expect($integration->total_series)->toBe(50);
});

it('cascades delete when user is deleted', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect(MediaServerIntegration::count())->toBe(1);

    $this->user->delete();

    expect(MediaServerIntegration::count())->toBe(0);
});

it('sets playlist_id to null when playlist is deleted', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    expect($integration->playlist_id)->toBe($playlist->id);

    $playlist->delete();

    $integration->refresh();

    expect($integration->playlist_id)->toBeNull();
});
