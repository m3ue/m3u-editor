<?php

use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
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

it('can reset status and progress fields', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'status' => 'processing',
        'progress' => 50,
        'movie_progress' => 75,
        'series_progress' => 25,
        'total_movies' => 100,
        'total_series' => 50,
    ]);

    expect($integration->status)->toBe('processing');
    expect($integration->progress)->toBe(50);
    expect($integration->movie_progress)->toBe(75);
    expect($integration->series_progress)->toBe(25);
    expect($integration->total_movies)->toBe(100);
    expect($integration->total_series)->toBe(50);

    // Reset the status
    $integration->update([
        'status' => 'idle',
        'progress' => 0,
        'movie_progress' => 0,
        'series_progress' => 0,
        'total_movies' => 0,
        'total_series' => 0,
    ]);

    $integration->refresh();

    expect($integration->status)->toBe('idle');
    expect($integration->progress)->toBe(0);
    expect($integration->movie_progress)->toBe(0);
    expect($integration->series_progress)->toBe(0);
    expect($integration->total_movies)->toBe(0);
    expect($integration->total_series)->toBe(0);
});

// Library Selection Tests

it('can store available libraries', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'available_libraries' => [
            ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
            ['id' => 'lib2', 'name' => 'TV Shows', 'type' => 'tvshows', 'item_count' => 50],
            ['id' => 'lib3', 'name' => '4K Movies', 'type' => 'movies', 'item_count' => 25],
        ],
    ]);

    expect($integration->available_libraries)->toBeArray();
    expect($integration->available_libraries)->toHaveCount(3);
    expect($integration->available_libraries[0]['name'])->toBe('Movies');
    expect($integration->available_libraries[1]['type'])->toBe('tvshows');
});

it('can store selected library ids', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'available_libraries' => [
            ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
            ['id' => 'lib2', 'name' => 'TV Shows', 'type' => 'tvshows', 'item_count' => 50],
        ],
        'selected_library_ids' => ['lib1', 'lib2'],
    ]);

    expect($integration->selected_library_ids)->toBeArray();
    expect($integration->selected_library_ids)->toContain('lib1');
    expect($integration->selected_library_ids)->toContain('lib2');
});

it('can get selected library ids for movies type', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'available_libraries' => [
            ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
            ['id' => 'lib2', 'name' => 'TV Shows', 'type' => 'tvshows', 'item_count' => 50],
            ['id' => 'lib3', 'name' => '4K Movies', 'type' => 'movies', 'item_count' => 25],
        ],
        'selected_library_ids' => ['lib1', 'lib2', 'lib3'],
    ]);

    $movieLibraries = $integration->getSelectedLibraryIdsForType('movies');

    expect($movieLibraries)->toBeArray();
    expect($movieLibraries)->toContain('lib1');
    expect($movieLibraries)->toContain('lib3');
    expect($movieLibraries)->not->toContain('lib2');
});

it('can get selected library ids for tvshows type', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'available_libraries' => [
            ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
            ['id' => 'lib2', 'name' => 'TV Shows', 'type' => 'tvshows', 'item_count' => 50],
            ['id' => 'lib3', 'name' => 'Anime', 'type' => 'tvshows', 'item_count' => 100],
        ],
        'selected_library_ids' => ['lib1', 'lib2', 'lib3'],
    ]);

    $tvLibraries = $integration->getSelectedLibraryIdsForType('tvshows');

    expect($tvLibraries)->toBeArray();
    expect($tvLibraries)->toContain('lib2');
    expect($tvLibraries)->toContain('lib3');
    expect($tvLibraries)->not->toContain('lib1');
});

it('returns empty array when no libraries are selected', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'available_libraries' => [
            ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
        ],
        'selected_library_ids' => [],
    ]);

    expect($integration->getSelectedLibraryIdsForType('movies'))->toBeEmpty();
});

it('returns empty array when available libraries is null', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($integration->getSelectedLibraryIdsForType('movies'))->toBeEmpty();
});

it('can check if libraries of a type are selected', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'available_libraries' => [
            ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
            ['id' => 'lib2', 'name' => 'TV Shows', 'type' => 'tvshows', 'item_count' => 50],
        ],
        'selected_library_ids' => ['lib1'], // Only movies selected
    ]);

    expect($integration->hasSelectedLibrariesOfType('movies'))->toBeTrue();
    expect($integration->hasSelectedLibrariesOfType('tvshows'))->toBeFalse();
});

it('can get selected library names', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'available_libraries' => [
            ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
            ['id' => 'lib2', 'name' => 'TV Shows', 'type' => 'tvshows', 'item_count' => 50],
            ['id' => 'lib3', 'name' => '4K Movies', 'type' => 'movies', 'item_count' => 25],
        ],
        'selected_library_ids' => ['lib1', 'lib3'],
    ]);

    $names = $integration->getSelectedLibraryNames();

    expect($names)->toBeArray();
    expect($names)->toContain('Movies');
    expect($names)->toContain('4K Movies');
    expect($names)->not->toContain('TV Shows');
});

it('can validate selected libraries against current libraries', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'available_libraries' => [
            ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
            ['id' => 'lib2', 'name' => 'TV Shows', 'type' => 'tvshows', 'item_count' => 50],
            ['id' => 'lib3', 'name' => 'Old Library', 'type' => 'movies', 'item_count' => 25],
        ],
        'selected_library_ids' => ['lib1', 'lib2', 'lib3'],
    ]);

    // Simulate that lib3 has been deleted from the media server
    $currentLibraries = [
        ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 160],
        ['id' => 'lib2', 'name' => 'TV Shows', 'type' => 'tvshows', 'item_count' => 55],
    ];

    $missingIds = $integration->validateSelectedLibraries($currentLibraries);

    expect($missingIds)->toBeArray();
    expect($missingIds)->toContain('lib3');
    expect($missingIds)->not->toContain('lib1');
    expect($missingIds)->not->toContain('lib2');
});

it('returns empty array when all selected libraries still exist', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'selected_library_ids' => ['lib1', 'lib2'],
    ]);

    $currentLibraries = [
        ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
        ['id' => 'lib2', 'name' => 'TV Shows', 'type' => 'tvshows', 'item_count' => 50],
        ['id' => 'lib3', 'name' => 'New Library', 'type' => 'movies', 'item_count' => 10],
    ];

    $missingIds = $integration->validateSelectedLibraries($currentLibraries);

    expect($missingIds)->toBeEmpty();
});

it('only returns selected library ids that match available libraries', function () {
    // Test edge case where selected_library_ids contains IDs not in available_libraries
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'available_libraries' => [
            ['id' => 'lib1', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 150],
        ],
        // lib2 is selected but not in available_libraries
        'selected_library_ids' => ['lib1', 'lib2'],
    ]);

    $movieLibraries = $integration->getSelectedLibraryIdsForType('movies');

    // Should only return lib1 since lib2 is not in available_libraries
    expect($movieLibraries)->toContain('lib1');
    expect($movieLibraries)->not->toContain('lib2');
});

// HasManyThrough Relationship Tests

it('can access channels through playlist via HasManyThrough', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->create(['user_id' => $this->user->id]));

    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    $channel1 = \App\Models\Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Movie Channel 1',
    ]);

    $channel2 = \App\Models\Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Movie Channel 2',
    ]);

    // Channel on a different playlist should not be included
    $otherPlaylist = Playlist::withoutEvents(fn () => Playlist::factory()->create(['user_id' => $this->user->id]));
    \App\Models\Channel::factory()->create([
        'playlist_id' => $otherPlaylist->id,
        'user_id' => $this->user->id,
        'name' => 'Other Channel',
    ]);

    $channels = $integration->channels;

    expect($channels)->toHaveCount(2);
    expect($channels->pluck('name')->toArray())->toContain('Movie Channel 1');
    expect($channels->pluck('name')->toArray())->toContain('Movie Channel 2');
    expect($channels->pluck('name')->toArray())->not->toContain('Other Channel');
});

it('can access series through playlist via HasManyThrough', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->create(['user_id' => $this->user->id]));

    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    $series1 = \App\Models\Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Breaking Bad',
    ]);

    $series2 = \App\Models\Series::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'The Wire',
    ]);

    // Series on a different playlist should not be included
    $otherPlaylist = Playlist::withoutEvents(fn () => Playlist::factory()->create(['user_id' => $this->user->id]));
    \App\Models\Series::factory()->create([
        'playlist_id' => $otherPlaylist->id,
        'user_id' => $this->user->id,
        'name' => 'Other Series',
    ]);

    $series = $integration->series;

    expect($series)->toHaveCount(2);
    expect($series->pluck('name')->toArray())->toContain('Breaking Bad');
    expect($series->pluck('name')->toArray())->toContain('The Wire');
    expect($series->pluck('name')->toArray())->not->toContain('Other Series');
});

it('returns empty channels collection when no playlist is assigned', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($integration->channels)->toBeEmpty();
});

it('returns empty series collection when no playlist is assigned', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($integration->series)->toBeEmpty();
});
