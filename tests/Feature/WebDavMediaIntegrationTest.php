<?php

use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\MediaServerService;
use App\Services\WebDavMediaService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
});

it('can create a webdav media server integration', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'My NAS WebDAV',
        'type' => 'webdav',
        'host' => 'nas.local',
        'port' => 5005,
        'ssl' => false,
        'webdav_username' => 'admin',
        'webdav_password' => 'secret123',
        'enabled' => true,
        'genre_handling' => 'primary',
        'import_movies' => true,
        'import_series' => true,
        'auto_sync' => true,
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['name' => 'Movies', 'path' => '/movies', 'type' => 'movies'],
            ['name' => 'TV Shows', 'path' => '/tvshows', 'type' => 'tvshows'],
        ],
    ]);

    expect($integration)->toBeInstanceOf(MediaServerIntegration::class);
    expect($integration->name)->toBe('My NAS WebDAV');
    expect($integration->type)->toBe('webdav');
    expect($integration->host)->toBe('nas.local');
    expect($integration->port)->toBe(5005);
    expect($integration->webdav_username)->toBe('admin');
});

it('can check if integration is webdav type', function () {
    $webdav = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $jellyfin = MediaServerIntegration::create([
        'name' => 'Jellyfin Server',
        'type' => 'jellyfin',
        'host' => 'jellyfin.local',
        'port' => 8096,
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($webdav->isWebDav())->toBeTrue();
    expect($webdav->isLocal())->toBeFalse();
    expect($webdav->isJellyfin())->toBeFalse();

    expect($jellyfin->isWebDav())->toBeFalse();
    expect($jellyfin->isJellyfin())->toBeTrue();
});

it('uses local path config for both local and webdav types', function () {
    $webdav = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $local = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    $jellyfin = MediaServerIntegration::create([
        'name' => 'Jellyfin Server',
        'type' => 'jellyfin',
        'host' => 'jellyfin.local',
        'port' => 8096,
        'api_key' => 'test-key',
        'user_id' => $this->user->id,
    ]);

    expect($webdav->usesLocalPathConfig())->toBeTrue();
    expect($local->usesLocalPathConfig())->toBeTrue();
    expect($jellyfin->usesLocalPathConfig())->toBeFalse();
});

it('webdav requires network connectivity', function () {
    $webdav = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $local = MediaServerIntegration::create([
        'name' => 'Local Media',
        'type' => 'local',
        'user_id' => $this->user->id,
    ]);

    expect($webdav->requiresNetwork())->toBeTrue();
    expect($local->requiresNetwork())->toBeFalse();
});

it('can create webdav service from integration', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $service = MediaServerService::make($integration);

    expect($service)->toBeInstanceOf(WebDavMediaService::class);
});

it('hides webdav credentials in serialization', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'webdav_username' => 'admin',
        'webdav_password' => 'secret123',
        'user_id' => $this->user->id,
    ]);

    $array = $integration->toArray();

    expect($array)->not->toHaveKey('webdav_username');
    expect($array)->not->toHaveKey('webdav_password');
    expect($array)->not->toHaveKey('api_key');
});

it('can get local media paths for movies', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['name' => 'Movies', 'path' => '/movies', 'type' => 'movies'],
            ['name' => 'TV Shows', 'path' => '/tvshows', 'type' => 'tvshows'],
            ['name' => 'More Movies', 'path' => '/movies2', 'type' => 'movies'],
        ],
    ]);

    $moviePaths = $integration->getLocalMediaPathsForType('movies');

    expect($moviePaths)->toHaveCount(2);
    expect(array_values($moviePaths)[0]['path'])->toBe('/movies');
    expect(array_values($moviePaths)[1]['path'])->toBe('/movies2');
});

it('generates correct stream URLs for webdav media', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $service = WebDavMediaService::make($integration);
    $itemId = base64_encode('/movies/Test Movie.mkv');

    $streamUrl = $service->getStreamUrl($itemId);

    expect($streamUrl)->toContain('/webdav-media/');
    expect($streamUrl)->toContain((string) $integration->id);
    expect($streamUrl)->toContain($itemId);
});

it('parses webdav propfind responses', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $service = WebDavMediaService::make($integration);

    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:multistatus xmlns:d="DAV:">'
        .'<d:response>'
        .'<d:href>/media/movies/</d:href>'
        .'<d:propstat>'
        .'<d:prop>'
        .'<d:resourcetype><d:collection/></d:resourcetype>'
        .'<d:displayname>movies</d:displayname>'
        .'</d:prop>'
        .'</d:propstat>'
        .'</d:response>'
        .'<d:response>'
        .'<d:href>/media/movies/Test.Movie.2024.mkv</d:href>'
        .'<d:propstat>'
        .'<d:prop>'
        .'<d:getcontentlength>12345</d:getcontentlength>'
        .'<d:displayname>Test.Movie.2024.mkv</d:displayname>'
        .'</d:prop>'
        .'</d:propstat>'
        .'</d:response>'
        .'</d:multistatus>';

    $method = new ReflectionMethod(WebDavMediaService::class, 'parseWebDavResponse');
    $method->setAccessible(true);

    $items = $method->invoke($service, $xml, '/media');

    expect($items)->toHaveCount(2);
    expect($items[0]['name'])->toBe('movies');
    expect($items[0]['isDirectory'])->toBeTrue();
    expect($items[1]['name'])->toBe('Test.Movie.2024.mkv');
    expect($items[1]['size'])->toBe(12345);
});

it('keeps full nested paths from webdav hrefs', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
    ]);

    $service = WebDavMediaService::make($integration);

    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:multistatus xmlns:d="DAV:">'
        .'<d:response>'
        .'<d:href>/media/movies/In%20Your%20Dreams/In%20Your%20Dreams/</d:href>'
        .'<d:propstat>'
        .'<d:prop>'
        .'<d:resourcetype><d:collection/></d:resourcetype>'
        .'<d:displayname>In Your Dreams</d:displayname>'
        .'</d:prop>'
        .'</d:propstat>'
        .'</d:response>'
        .'</d:multistatus>';

    $method = new ReflectionMethod(WebDavMediaService::class, 'parseWebDavResponse');
    $method->setAccessible(true);

    $items = $method->invoke($service, $xml, '/media');

    expect($items)->toHaveCount(1);
    expect($items[0]['path'])->toBe('/media/movies/In Your Dreams/In Your Dreams');
    expect($items[0]['name'])->toBe('In Your Dreams');
});

it('uses library name as default genre for webdav movies and series', function () {
    $integration = MediaServerIntegration::create([
        'name' => 'WebDAV Server',
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 80,
        'user_id' => $this->user->id,
        'local_media_paths' => [
            ['name' => 'Action', 'path' => '/movies', 'type' => 'movies'],
            ['name' => 'Drama', 'path' => '/tvshows', 'type' => 'tvshows'],
        ],
    ]);

    $service = new class($integration) extends WebDavMediaService
    {
        protected function listWebDavDirectory(string $path): array
        {
            if ($path === '/movies') {
                return [
                    ['name' => 'Test.Movie.2024.mkv', 'path' => '/movies/Test.Movie.2024.mkv', 'isDirectory' => false, 'size' => 1234],
                ];
            }

            if ($path === '/tvshows') {
                return [
                    ['name' => 'Breaking Bad', 'path' => '/tvshows/Breaking Bad', 'isDirectory' => true, 'size' => null],
                ];
            }

            return [];
        }
    };

    $movies = $service->fetchMovies();
    expect($movies)->toHaveCount(1);
    expect($movies->first()['Genres'])->toBe(['Action']);

    $series = $service->fetchSeries();
    expect($series)->toHaveCount(1);
    expect($series->first()['Genres'])->toBe(['Drama']);
});

it('implements ShouldBeUnique to prevent concurrent sync jobs', function () {
    $job = new SyncMediaServer(integrationId: 1);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('sync-media-server-1');
    expect($job->uniqueFor)->toBe(1800);
});

it('generates different unique IDs for different integrations', function () {
    $job1 = new SyncMediaServer(integrationId: 1);
    $job2 = new SyncMediaServer(integrationId: 2);

    expect($job1->uniqueId())->not->toBe($job2->uniqueId());
});

it('prevents duplicate series via unique database constraint', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    $series = Series::create([
        'name' => 'Test Series',
        'playlist_id' => $playlist->id,
        'source_series_id' => 12345,
        'user_id' => $this->user->id,
        'import_batch_no' => 'batch-1',
    ]);

    expect($series->exists)->toBeTrue();

    // Attempting to create a second series with the same playlist_id + source_series_id should fail
    expect(fn () => Series::create([
        'name' => 'Test Series Duplicate',
        'playlist_id' => $playlist->id,
        'source_series_id' => 12345,
        'user_id' => $this->user->id,
        'import_batch_no' => 'batch-2',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('preserves TMDB-enriched genre on series re-sync', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    $integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Local TV',
            'type' => 'local',
            'user_id' => $this->user->id,
        ]);
    });

    // Create a category for the TMDB-enriched genre
    $tmdbCategory = \App\Models\Category::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Drama',
        'name_internal' => 'Drama',
    ]);

    // Create an existing series that has been TMDB-enriched
    $sourceSeriesId = crc32("media-server-{$integration->id}-series-123");
    $series = Series::create([
        'playlist_id' => $playlist->id,
        'source_series_id' => $sourceSeriesId,
        'user_id' => $this->user->id,
        'name' => 'Breaking Bad',
        'genre' => 'Drama, Crime',
        'category_id' => $tmdbCategory->id,
        'source_category_id' => $tmdbCategory->id,
        'last_metadata_fetch' => now()->subDay(),
        'import_batch_no' => 'batch-1',
    ]);

    // Mock the MediaServer service
    $service = Mockery::mock(\App\Interfaces\MediaServer::class);
    $service->shouldReceive('fetchSeriesDetails')->with('series-123')->andReturn([]);
    $service->shouldReceive('extractGenres')->andReturn(['tv']);
    $service->shouldReceive('getImageUrl')->andReturn('');
    $service->shouldReceive('ticksToSeconds')->andReturn(null);
    $service->shouldReceive('fetchSeasons')->with('series-123')->andReturn(collect([]));

    // Prepare seriesData as SyncMediaServer would receive it
    $seriesData = [
        'Id' => 'series-123',
        'Name' => 'Breaking Bad',
        'People' => [],
    ];

    $job = new SyncMediaServer($integration->id);

    // Call syncOneSeries via reflection
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('syncOneSeries');

    $method->invoke($job, $integration, $playlist, $service, $seriesData);

    $series->refresh();

    // Genre should be preserved as 'Drama, Crime' (TMDB-enriched), NOT overwritten to 'tv'
    expect($series->genre)->toBe('Drama, Crime')
        ->and($series->category_id)->toBe($tmdbCategory->id);
});

it('preserves TMDB-enriched group on movie re-sync', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    $integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Local Movies',
            'type' => 'local',
            'user_id' => $this->user->id,
        ]);
    });

    // Create the library group
    $libraryGroup = \App\Models\Group::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Movies',
        'name_internal' => 'Movies',
        'type' => 'vod',
    ]);

    // Create the TMDB-enriched group
    $tmdbGroup = \App\Models\Group::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Action',
        'name_internal' => 'Action',
        'type' => 'vod',
    ]);

    // Create an existing channel that has been TMDB-enriched
    $sourceId = "media-server-{$integration->id}-movie-456";
    $channel = \App\Models\Channel::create([
        'playlist_id' => $playlist->id,
        'source_id' => $sourceId,
        'user_id' => $this->user->id,
        'name' => 'The Matrix',
        'title' => 'The Matrix',
        'url' => 'http://example.com/stream',
        'group' => 'Action',
        'group_internal' => 'Action',
        'group_id' => $tmdbGroup->id,
        'is_vod' => true,
        'enabled' => true,
        'last_metadata_fetch' => now()->subDay(),
        'info' => [
            'genre' => 'Action, Sci-Fi',
            'plot' => 'A hacker discovers reality is a simulation.',
        ],
    ]);

    // Mock the MediaServer service
    $service = Mockery::mock(\App\Interfaces\MediaServer::class);
    $service->shouldReceive('extractGenres')->andReturn(['Movies']);
    $service->shouldReceive('getContainerExtension')->andReturn('mkv');
    $service->shouldReceive('getStreamUrl')->andReturn('http://example.com/new-stream');
    $service->shouldReceive('getImageUrl')->andReturn('');
    $service->shouldReceive('ticksToSeconds')->andReturn(null);

    // Prepare movie data as SyncMediaServer would receive it
    $movieData = [
        'Id' => 'movie-456',
        'Name' => 'The Matrix',
        'People' => [],
        'Genres' => ['Movies'],
        'ProductionLocations' => [],
    ];

    $job = new SyncMediaServer($integration->id);

    // Call syncMovie via reflection
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('syncMovie');

    $method->invoke($job, $integration, $playlist, $service, $movieData);

    $channel->refresh();

    // Group should be preserved as 'Action' (TMDB-enriched), NOT overwritten to 'Movies'
    expect($channel->group)->toBe('Action')
        ->and($channel->group_internal)->toBe('Action')
        ->and($channel->group_id)->toBe($tmdbGroup->id);

    // Info genre should also be preserved (TMDB-protected key)
    expect($channel->info['genre'])->toBe('Action, Sci-Fi');
});

it('overwrites genre on new series (not TMDB-enriched)', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    $integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Local TV',
            'type' => 'local',
            'user_id' => $this->user->id,
        ]);
    });

    // Mock the MediaServer service
    $service = Mockery::mock(\App\Interfaces\MediaServer::class);
    $service->shouldReceive('fetchSeriesDetails')->with('new-series-789')->andReturn([]);
    $service->shouldReceive('extractGenres')->andReturn(['tv']);
    $service->shouldReceive('getImageUrl')->andReturn('');
    $service->shouldReceive('ticksToSeconds')->andReturn(null);
    $service->shouldReceive('fetchSeasons')->with('new-series-789')->andReturn(collect([]));

    $seriesData = [
        'Id' => 'new-series-789',
        'Name' => 'New Show',
        'People' => [],
    ];

    $job = new SyncMediaServer($integration->id);

    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('syncOneSeries');

    $method->invoke($job, $integration, $playlist, $service, $seriesData);

    // A new series should have been created with genre 'tv'
    $sourceSeriesId = crc32("media-server-{$integration->id}-new-series-789");
    $series = Series::where('playlist_id', $playlist->id)
        ->where('source_series_id', $sourceSeriesId)
        ->first();

    expect($series)->not->toBeNull()
        ->and($series->genre)->toBe('tv')
        ->and($series->last_metadata_fetch)->toBeNull();
});

it('allows different source_series_id values on the same playlist', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    $series1 = Series::create([
        'name' => 'Series One',
        'playlist_id' => $playlist->id,
        'source_series_id' => 11111,
        'user_id' => $this->user->id,
        'import_batch_no' => 'batch-1',
    ]);

    $series2 = Series::create([
        'name' => 'Series Two',
        'playlist_id' => $playlist->id,
        'source_series_id' => 22222,
        'user_id' => $this->user->id,
        'import_batch_no' => 'batch-1',
    ]);

    expect($series1->exists)->toBeTrue();
    expect($series2->exists)->toBeTrue();
    expect(Series::where('playlist_id', $playlist->id)->count())->toBe(2);
});
