<?php

use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([SyncMediaServer::class]);
    $this->user = User::factory()->create();
});

// Local Media Creation Tests

it('can create a local media integration without host or api_key', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'My Local Movies',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => '/media/movies', 'type' => 'movies', 'name' => 'Movies'],
        ],
    ]);

    expect($integration)->toBeInstanceOf(MediaServerIntegration::class);
    expect($integration->name)->toBe('My Local Movies');
    expect($integration->type)->toBe('local');
    expect($integration->host)->toBeNull();
    expect($integration->api_key)->toBeNull();
});

it('can create a local media integration with nullable host and api_key', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Library',
        'type' => 'local',
        'host' => null,
        'api_key' => null,
        'user_id' => $this->user->id,
    ]);

    expect($integration->host)->toBeNull();
    expect($integration->api_key)->toBeNull();
});

it('still allows host and api_key for network integrations', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Jellyfin Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'secret-key-123',
        'user_id' => $this->user->id,
    ]);

    expect($integration->host)->toBe('192.168.1.100');
    expect($integration->api_key)->toBe('secret-key-123');
});

// isLocal / requiresNetwork Tests

it('identifies local media type correctly', function () {
    $local = MediaServerIntegration::create([
        'name' => 'Local',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    $jellyfin = MediaServerIntegration::create([
        'name' => 'Jellyfin',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'api_key' => 'key',
        'user_id' => $this->user->id,
    ]);

    expect($local->isLocal())->toBeTrue();
    expect($local->isJellyfin())->toBeFalse();
    expect($local->isEmby())->toBeFalse();

    expect($jellyfin->isLocal())->toBeFalse();
    expect($jellyfin->isJellyfin())->toBeTrue();
});

it('reports local media does not require network', function () {
    $local = MediaServerIntegration::create([
        'name' => 'Local',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    $emby = MediaServerIntegration::create([
        'name' => 'Emby',
        'type' => 'emby',
        'host' => '10.0.0.5',
        'api_key' => 'key',
        'user_id' => $this->user->id,
    ]);

    expect($local->requiresNetwork())->toBeFalse();
    expect($emby->requiresNetwork())->toBeTrue();
});

// Local Media Paths Tests

it('can store and retrieve local media paths', function () {
    $paths = [
        ['path' => '/media/movies', 'type' => 'movies', 'name' => 'Movies'],
        ['path' => '/media/tv', 'type' => 'tvshows', 'name' => 'TV Shows'],
        ['path' => '/media/4k-movies', 'type' => 'movies', 'name' => '4K Movies'],
    ];

    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => $paths,
    ]);

    expect($integration->local_media_paths)->toBeArray();
    expect($integration->local_media_paths)->toHaveCount(3);
    expect($integration->local_media_paths[0]['path'])->toBe('/media/movies');
    expect($integration->local_media_paths[1]['type'])->toBe('tvshows');
});

it('can filter local media paths by type', function () {
    $paths = [
        ['path' => '/media/movies', 'type' => 'movies', 'name' => 'Movies'],
        ['path' => '/media/tv', 'type' => 'tvshows', 'name' => 'TV Shows'],
        ['path' => '/media/4k-movies', 'type' => 'movies', 'name' => '4K Movies'],
    ];

    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => $paths,
    ]);

    $moviePaths = $integration->getLocalMediaPathsForType('movies');
    $tvPaths = $integration->getLocalMediaPathsForType('tvshows');

    expect($moviePaths)->toHaveCount(2);
    expect($tvPaths)->toHaveCount(1);
    expect(array_values($tvPaths)[0]['path'])->toBe('/media/tv');
});

it('returns all paths when type is null', function () {
    $paths = [
        ['path' => '/media/movies', 'type' => 'movies', 'name' => 'Movies'],
        ['path' => '/media/tv', 'type' => 'tvshows', 'name' => 'TV Shows'],
    ];

    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => $paths,
    ]);

    expect($integration->getLocalMediaPathsForType(null))->toHaveCount(2);
    expect($integration->getLocalMediaPathsForType())->toHaveCount(2);
});

it('returns empty array when no local media paths are set', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    expect($integration->getLocalMediaPathsForType('movies'))->toBeEmpty();
    expect($integration->getLocalMediaPathsForType())->toBeEmpty();
});

// Video Extensions Tests

it('returns default video extensions when none are set', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    $extensions = $integration->getVideoExtensions();

    expect($extensions)->toBeArray();
    expect($extensions)->toContain('mp4');
    expect($extensions)->toContain('mkv');
    expect($extensions)->toContain('avi');
    expect($extensions)->toContain('webm');
});

it('returns custom video extensions when set', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
        'video_extensions' => ['mp4', 'mkv'],
    ]);

    $extensions = $integration->getVideoExtensions();

    expect($extensions)->toHaveCount(2);
    expect($extensions)->toContain('mp4');
    expect($extensions)->toContain('mkv');
    expect($extensions)->not->toContain('avi');
});

// Default Attributes Tests

it('has correct default values for local media fields', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    expect($integration->metadata_source)->toBe('tmdb');
    expect($integration->scan_recursive)->toBeTrue();
    expect($integration->enabled)->toBeTrue();
    expect($integration->status)->toBe('idle');
});

// Casting Tests

it('casts local_media_paths to array', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => '/media/movies', 'type' => 'movies', 'name' => 'Movies'],
        ],
    ]);

    $integration->refresh();

    expect($integration->local_media_paths)->toBeArray();
    expect($integration->local_media_paths[0]['path'])->toBe('/media/movies');
});

it('casts video_extensions to array', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
        'video_extensions' => ['mp4', 'mkv', 'avi'],
    ]);

    $integration->refresh();

    expect($integration->video_extensions)->toBeArray();
    expect($integration->video_extensions)->toContain('mp4');
});

it('casts scan_recursive to boolean', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
        'scan_recursive' => false,
    ]);

    expect($integration->scan_recursive)->toBeFalse();
});

// LocalMediaService - resolveSeasonNumber & fetchEpisodes Tests

it('resolves season number from md5 season id via filesystem scan', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local TV',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => '/tmp/test-media-tv', 'type' => 'tvshows', 'name' => 'TV'],
        ],
    ]);

    $seriesDir = '/tmp/test-media-tv/Breaking Bad';
    $season1Dir = $seriesDir.'/Season 1';
    $season2Dir = $seriesDir.'/Season 2';

    // Create directory structure with episode files
    \Illuminate\Support\Facades\File::ensureDirectoryExists($season1Dir);
    \Illuminate\Support\Facades\File::ensureDirectoryExists($season2Dir);
    \Illuminate\Support\Facades\File::put($season1Dir.'/S01E01.mp4', '');
    \Illuminate\Support\Facades\File::put($season2Dir.'/S02E01.mp4', '');

    $service = new \App\Services\LocalMediaService($integration);

    // Use reflection to call the protected resolveSeasonNumber method
    $reflection = new ReflectionMethod($service, 'resolveSeasonNumber');

    $season1Id = md5($season1Dir);
    $season2Id = md5($season2Dir);

    expect($reflection->invoke($service, $seriesDir, $season1Id))->toBe(1);
    expect($reflection->invoke($service, $seriesDir, $season2Id))->toBe(2);
    expect($reflection->invoke($service, $seriesDir, 'nonexistent-id'))->toBeNull();

    // Cleanup
    \Illuminate\Support\Facades\File::deleteDirectory('/tmp/test-media-tv');
});

it('filters episodes by season when md5 season id is provided', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local TV',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => '/tmp/test-media-filter', 'type' => 'tvshows', 'name' => 'TV'],
        ],
    ]);

    $seriesDir = '/tmp/test-media-filter/The Wire';
    $season1Dir = $seriesDir.'/Season 1';
    $season2Dir = $seriesDir.'/Season 2';

    \Illuminate\Support\Facades\File::ensureDirectoryExists($season1Dir);
    \Illuminate\Support\Facades\File::ensureDirectoryExists($season2Dir);
    \Illuminate\Support\Facades\File::put($season1Dir.'/The Wire S01E01 - The Target.mp4', '');
    \Illuminate\Support\Facades\File::put($season1Dir.'/The Wire S01E02 - The Detail.mp4', '');
    \Illuminate\Support\Facades\File::put($season2Dir.'/The Wire S02E01 - Ebb Tide.mp4', '');

    $service = new \App\Services\LocalMediaService($integration);

    $seriesId = md5($seriesDir);
    $season1Id = md5($season1Dir);
    $season2Id = md5($season2Dir);

    // Fetch all episodes (no season filter)
    $allEpisodes = $service->fetchEpisodes($seriesId);
    expect($allEpisodes)->toHaveCount(3);

    // Fetch only season 1 episodes
    $season1Episodes = $service->fetchEpisodes($seriesId, $season1Id);
    expect($season1Episodes)->toHaveCount(2);
    expect($season1Episodes->pluck('ParentIndexNumber')->unique()->values()->toArray())->toBe([1]);

    // Fetch only season 2 episodes
    $season2Episodes = $service->fetchEpisodes($seriesId, $season2Id);
    expect($season2Episodes)->toHaveCount(1);
    expect($season2Episodes->first()['ParentIndexNumber'])->toBe(2);

    // Cleanup
    \Illuminate\Support\Facades\File::deleteDirectory('/tmp/test-media-filter');
});

it('returns all episodes when season id does not match any season', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local TV',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => '/tmp/test-media-nomatch', 'type' => 'tvshows', 'name' => 'TV'],
        ],
    ]);

    $seriesDir = '/tmp/test-media-nomatch/Lost';
    $season1Dir = $seriesDir.'/Season 1';

    \Illuminate\Support\Facades\File::ensureDirectoryExists($season1Dir);
    \Illuminate\Support\Facades\File::put($season1Dir.'/Lost S01E01 - Pilot.mp4', '');
    \Illuminate\Support\Facades\File::put($season1Dir.'/Lost S01E02 - Tabula Rasa.mp4', '');

    $service = new \App\Services\LocalMediaService($integration);
    $seriesId = md5($seriesDir);

    // When resolveSeasonNumber returns null (bad id), no filtering occurs
    $episodes = $service->fetchEpisodes($seriesId, 'bogus-season-id');
    expect($episodes)->toHaveCount(2);

    // Cleanup
    \Illuminate\Support\Facades\File::deleteDirectory('/tmp/test-media-nomatch');
});

// Flat Structure Detection Tests

it('detects flat structure when video files exist without subdirectories', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local TV',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => '/tmp/test-media-flat', 'type' => 'tvshows', 'name' => 'TV'],
        ],
    ]);

    $basePath = '/tmp/test-media-flat';
    \Illuminate\Support\Facades\File::ensureDirectoryExists($basePath);
    \Illuminate\Support\Facades\File::put($basePath.'/Breaking.Bad.S01E01.mp4', '');
    \Illuminate\Support\Facades\File::put($basePath.'/The.Wire.S01E01.mkv', '');
    \Illuminate\Support\Facades\File::put($basePath.'/Lost.S01E01.avi', '');
    \Illuminate\Support\Facades\File::put($basePath.'/notes.txt', '');

    $service = new \App\Services\LocalMediaService($integration);
    $result = $service->detectFlatStructure($basePath);

    expect($result['has_flat_files'])->toBeTrue();
    expect($result['file_count'])->toBe(3);
    expect($result['sample_files'])->toHaveCount(3);
    expect($result['sample_files'])->each->toBeString();

    // Cleanup
    \Illuminate\Support\Facades\File::deleteDirectory($basePath);
});

it('does not detect flat structure when series subdirectories exist', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local TV',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => '/tmp/test-media-proper', 'type' => 'tvshows', 'name' => 'TV'],
        ],
    ]);

    $basePath = '/tmp/test-media-proper';
    \Illuminate\Support\Facades\File::ensureDirectoryExists($basePath.'/Breaking Bad/Season 1');
    \Illuminate\Support\Facades\File::put($basePath.'/Breaking Bad/Season 1/S01E01.mp4', '');

    $service = new \App\Services\LocalMediaService($integration);
    $result = $service->detectFlatStructure($basePath);

    expect($result['has_flat_files'])->toBeFalse();
    expect($result['file_count'])->toBe(0);
    expect($result['sample_files'])->toBeEmpty();

    // Cleanup
    \Illuminate\Support\Facades\File::deleteDirectory($basePath);
});

it('returns warnings for series paths with flat structure', function () {
    $basePath = '/tmp/test-media-warn';
    \Illuminate\Support\Facades\File::ensureDirectoryExists($basePath);
    \Illuminate\Support\Facades\File::put($basePath.'/Breaking.Bad.S01E01.mp4', '');
    \Illuminate\Support\Facades\File::put($basePath.'/The.Wire.S01E01.mkv', '');

    $integration = MediaServerIntegration::create([
        'name' => 'Local TV',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => $basePath, 'type' => 'tvshows', 'name' => 'TV'],
        ],
    ]);

    $service = new \App\Services\LocalMediaService($integration);
    $warnings = $service->getSeriesPathWarnings();

    expect($warnings)->toBeArray();
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain($basePath);
    expect($warnings[0])->toContain('2 video file(s)');
    expect($warnings[0])->toContain('Series Name/Season X/episode.mkv');

    // Cleanup
    \Illuminate\Support\Facades\File::deleteDirectory($basePath);
});

it('includes flat structure warning in testConnection response for series paths', function () {
    $basePath = '/tmp/test-media-conn';
    \Illuminate\Support\Facades\File::ensureDirectoryExists($basePath);
    \Illuminate\Support\Facades\File::put($basePath.'/Show.S01E01.mp4', '');

    $integration = MediaServerIntegration::create([
        'name' => 'Local TV',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => $basePath, 'type' => 'tvshows', 'name' => 'TV'],
        ],
    ]);

    $service = new \App\Services\LocalMediaService($integration);
    $result = $service->testConnection();

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toContain('no series folders');
    expect($result['flat_structure_warnings'])->toBeArray();
    expect($result['flat_structure_warnings'])->toHaveCount(1);

    // Cleanup
    \Illuminate\Support\Facades\File::deleteDirectory($basePath);
});

it('does not include flat structure warning when proper folder structure exists', function () {
    $basePath = '/tmp/test-media-ok';
    \Illuminate\Support\Facades\File::ensureDirectoryExists($basePath.'/Breaking Bad/Season 1');
    \Illuminate\Support\Facades\File::put($basePath.'/Breaking Bad/Season 1/S01E01.mp4', '');

    $integration = MediaServerIntegration::create([
        'name' => 'Local TV',
        'type' => 'local',
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['path' => $basePath, 'type' => 'tvshows', 'name' => 'TV'],
        ],
    ]);

    $service = new \App\Services\LocalMediaService($integration);
    $result = $service->testConnection();

    expect($result['success'])->toBeTrue();
    expect($result['message'])->not->toContain('no series folders');
    expect($result['flat_structure_warnings'])->toBeEmpty();

    // Cleanup
    \Illuminate\Support\Facades\File::deleteDirectory($basePath);
});

// Auto Metadata Fetch Tests

it('has auto_fetch_metadata enabled by default for local integrations', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    expect($integration->auto_fetch_metadata)->toBeTrue();
});

it('can disable auto_fetch_metadata for local integrations', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
        'auto_fetch_metadata' => false,
    ]);

    expect($integration->auto_fetch_metadata)->toBeFalse();
});

it('dispatches FetchTmdbIds job when auto_fetch_metadata is enabled and items synced', function () {
    Queue::fake([\App\Jobs\FetchTmdbIds::class]);

    // Mock TmdbService to return configured
    $this->mock(\App\Services\TmdbService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
    });

    // Use withoutEvents to prevent model observers from dispatching jobs
    $integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Local Media',
            'type' => 'local',
            'user_id' => $this->user->id,
            'auto_fetch_metadata' => true,
        ]);
    });

    $playlist = \App\Models\Playlist::withoutEvents(function () {
        return \App\Models\Playlist::factory()->create([
            'user_id' => $this->user->id,
        ]);
    });

    // Create a SyncMediaServer job instance and use reflection to test dispatchMetadataLookup
    $job = new SyncMediaServer($integration->id);

    // Set the stats property using reflection
    $reflection = new ReflectionClass($job);
    $statsProperty = $reflection->getProperty('stats');
    $statsProperty->setValue($job, [
        'movies_synced' => 5,
        'series_synced' => 3,
        'errors' => [],
    ]);

    // Call dispatchMetadataLookup via reflection
    $method = $reflection->getMethod('dispatchMetadataLookup');
    $method->invoke($job, $integration, $playlist);

    Queue::assertPushed(\App\Jobs\FetchTmdbIds::class, function ($job) use ($playlist) {
        return $job->vodPlaylistId === $playlist->id
            && $job->seriesPlaylistId === $playlist->id;
    });
});

it('does not dispatch FetchTmdbIds job when auto_fetch_metadata is disabled', function () {
    Queue::fake([\App\Jobs\FetchTmdbIds::class]);

    // Use withoutEvents to prevent model observers from dispatching jobs
    $integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Local Media',
            'type' => 'local',
            'user_id' => $this->user->id,
            'auto_fetch_metadata' => false,
        ]);
    });

    $playlist = \App\Models\Playlist::withoutEvents(function () {
        return \App\Models\Playlist::factory()->create([
            'user_id' => $this->user->id,
        ]);
    });

    $job = new SyncMediaServer($integration->id);

    $reflection = new ReflectionClass($job);
    $statsProperty = $reflection->getProperty('stats');
    $statsProperty->setValue($job, [
        'movies_synced' => 5,
        'series_synced' => 3,
        'errors' => [],
    ]);

    $method = $reflection->getMethod('dispatchMetadataLookup');
    $method->invoke($job, $integration, $playlist);

    Queue::assertNotPushed(\App\Jobs\FetchTmdbIds::class);
});

it('does not dispatch FetchTmdbIds job when no items were synced', function () {
    Queue::fake([\App\Jobs\FetchTmdbIds::class]);

    $this->mock(\App\Services\TmdbService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
    });

    // Use withoutEvents to prevent model observers from dispatching jobs
    $integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Local Media',
            'type' => 'local',
            'user_id' => $this->user->id,
            'auto_fetch_metadata' => true,
        ]);
    });

    $playlist = \App\Models\Playlist::withoutEvents(function () {
        return \App\Models\Playlist::factory()->create([
            'user_id' => $this->user->id,
        ]);
    });

    $job = new SyncMediaServer($integration->id);

    $reflection = new ReflectionClass($job);
    $statsProperty = $reflection->getProperty('stats');
    $statsProperty->setValue($job, [
        'movies_synced' => 0,
        'series_synced' => 0,
        'errors' => [],
    ]);

    $method = $reflection->getMethod('dispatchMetadataLookup');
    $method->invoke($job, $integration, $playlist);

    Queue::assertNotPushed(\App\Jobs\FetchTmdbIds::class);
});

it('does not dispatch FetchTmdbIds job for non-local integrations', function () {
    Queue::fake([\App\Jobs\FetchTmdbIds::class]);

    $this->mock(\App\Services\TmdbService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
    });

    // Use withoutEvents to prevent model observers from dispatching jobs
    $integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Jellyfin Server',
            'type' => 'jellyfin',
            'host' => '192.168.1.100',
            'api_key' => 'test-key',
            'user_id' => $this->user->id,
            'auto_fetch_metadata' => true,
        ]);
    });

    $playlist = \App\Models\Playlist::withoutEvents(function () {
        return \App\Models\Playlist::factory()->create([
            'user_id' => $this->user->id,
        ]);
    });

    $job = new SyncMediaServer($integration->id);

    $reflection = new ReflectionClass($job);
    $statsProperty = $reflection->getProperty('stats');
    $statsProperty->setValue($job, [
        'movies_synced' => 10,
        'series_synced' => 5,
        'errors' => [],
    ]);

    $method = $reflection->getMethod('dispatchMetadataLookup');
    $method->invoke($job, $integration, $playlist);

    Queue::assertNotPushed(\App\Jobs\FetchTmdbIds::class);
});
