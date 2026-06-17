<?php

use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\Arr\ArrService;
use App\Services\Arr\Contracts\ArrIntegrationInterface;
use App\Services\Arr\RadarrService;
use App\Services\Arr\SonarrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
});

it('dispatches sonarr integrations to SonarrService', function () {
    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    expect(ArrService::make($integration))->toBeInstanceOf(SonarrService::class);
});

it('dispatches radarr integrations to RadarrService', function () {
    $integration = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    expect(ArrService::make($integration))->toBeInstanceOf(RadarrService::class);
});

it('returns the integration from getIntegration', function () {
    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $service = ArrService::make($integration);

    expect($service->getIntegration()->is($integration))->toBeTrue();
});

it('throws for unknown arr types', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'type' => 'unsupported',
    ]);

    ArrService::make($integration);
})->throws(InvalidArgumentException::class);

it('returns the correct interface', function () {
    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    expect(ArrService::make($integration))->toBeInstanceOf(ArrIntegrationInterface::class);
});

it('testConnection returns version on success', function () {
    Http::fake([
        '*/api/v3/system/status' => Http::response(['version' => '4.7.0'], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $result = ArrService::make($integration)->testConnection();

    expect($result['ok'])->toBeTrue();
    expect($result['version'])->toBe('4.7.0');
});

it('testConnection returns error on failure', function () {
    Http::fake([
        '*/api/v3/system/status' => Http::response('Unauthorized', 401),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $result = ArrService::make($integration)->testConnection();

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toContain('401');
});

it('testConnection handles network errors', function () {
    Http::fake([
        '*/api/v3/system/status' => function () {
            throw new ConnectionException('timeout');
        },
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $result = ArrService::make($integration)->testConnection();

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toContain('timeout');
});

it('sends X-Api-Key header on requests', function () {
    Http::fake([
        '*/api/v3/system/status' => Http::response(['version' => '4.7.0'], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'api_key' => 'my-secret-token',
    ]);

    ArrService::make($integration)->testConnection();

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Api-Key', 'my-secret-token');
    });
});

it('fetchQualityProfiles returns formatted profiles', function () {
    Http::fake([
        '*/api/v3/qualityprofile' => Http::response([
            ['id' => 1, 'name' => 'HD-1080p'],
            ['id' => 2, 'name' => '4K-UHD'],
        ], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $profiles = ArrService::make($integration)->fetchQualityProfiles();

    expect($profiles)->toHaveCount(2);
    expect($profiles[0])->toBe(['id' => 1, 'name' => 'HD-1080p']);
    expect($profiles[1])->toBe(['id' => 2, 'name' => '4K-UHD']);
});

it('fetchRootFolders returns formatted folders', function () {
    Http::fake([
        '*/api/v3/rootfolder' => Http::response([
            ['id' => 1, 'path' => '/media/tv', 'freeSpace' => 500000000000],
        ], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $folders = ArrService::make($integration)->fetchRootFolders();

    expect($folders)->toHaveCount(1);
    expect($folders[0]['path'])->toBe('/media/tv');
    expect($folders[0]['freeSpace'])->toBe(500000000000);
});

it('search returns formatted results', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 12345,
                'title' => 'Breaking Bad',
                'titleSlug' => 'breaking-bad',
                'year' => 2008,
                'overview' => 'A chemistry teacher...',
                'remotePoster' => 'https://example.com/poster.jpg',
                'seasons' => [['seasonNumber' => 1]],
            ],
        ], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $results = ArrService::make($integration)->search('breaking');

    expect($results)->toHaveCount(1);
    expect($results[0]['title'])->toBe('Breaking Bad');
    expect($results[0]['tvdbId'])->toBe(12345);
    expect($results[0]['poster'])->toBe('https://example.com/poster.jpg');
});

it('RadarrService search uses tmdbId', function () {
    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([
            [
                'tmdbId' => 27205,
                'title' => 'Inception',
                'year' => 2010,
                'overview' => 'A thief who steals...',
                'runtime' => 148,
                'genres' => ['Action', 'Sci-Fi'],
            ],
        ], 200),
    ]);

    $integration = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $results = ArrService::make($integration)->search('inception');

    expect($results)->toHaveCount(1);
    expect($results[0]['title'])->toBe('Inception');
    expect($results[0]['tmdbId'])->toBe(27205);
    expect($results[0]['runtime'])->toBe(148);
    expect($results[0]['genres'])->toBe(['Action', 'Sci-Fi']);
});

it('add posts correct payload for Sonarr', function () {
    Http::fake([
        '*/api/v3/series' => Http::response(['id' => 99, 'title' => 'Breaking Bad'], 201),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'quality_profile_id' => 2,
        'root_folder_path' => '/media/tv',
    ]);

    $result = ArrService::make($integration)->add([
        'tvdbId' => 12345,
        'title' => 'Breaking Bad',
        'titleSlug' => 'breaking-bad',
    ]);

    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === $request->url() // any host
            && $request->method() === 'POST'
            && $body['tvdbId'] === 12345
            && $body['qualityProfileId'] === 2
            && $body['rootFolderPath'] === '/media/tv'
            && $body['monitored'] === true
            && $body['addOptions']['searchForMissingEpisodes'] === true;
    });
});

it('add posts correct payload for Radarr', function () {
    Http::fake([
        '*/api/v3/movie' => Http::response(['id' => 99, 'title' => 'Inception'], 201),
    ]);

    $integration = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'quality_profile_id' => 3,
        'root_folder_path' => '/media/movies',
    ]);

    $result = ArrService::make($integration)->add([
        'tmdbId' => 27205,
        'title' => 'Inception',
    ]);

    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && $body['tmdbId'] === 27205
            && $body['qualityProfileId'] === 3
            && $body['rootFolderPath'] === '/media/movies'
            && $body['addOptions']['searchForMovie'] === true;
    });
});

it('checkExists returns true when found', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad'],
        ], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $result = ArrService::make($integration)->checkExists(12345);

    expect($result['exists'])->toBeTrue();
    expect($result['id'])->toBe(42);
});

it('checkExists returns false when not found', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $result = ArrService::make($integration)->checkExists(99999);

    expect($result['exists'])->toBeFalse();
});

it('RadarrService checkExists uses /movie endpoint', function () {
    Http::fake([
        '*/api/v3/movie*' => Http::response([
            ['id' => 77, 'tmdbId' => 27205, 'title' => 'Inception'],
        ], 200),
    ]);

    $integration = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $result = ArrService::make($integration)->checkExists(27205);

    expect($result['exists'])->toBeTrue();
    expect($result['id'])->toBe(77);
});

it('fetchQueue maps progress from sizeleft/size', function () {
    Http::fake([
        '*/api/v3/queue*' => Http::response([
            'records' => [
                [
                    'id' => 1,
                    'title' => 'Episode 1',
                    'status' => 'downloading',
                    'size' => 1000,
                    'sizeleft' => 250,
                    'timeleft' => '00:05:00',
                    'series' => ['title' => 'Breaking Bad'],
                ],
            ],
        ], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $queue = ArrService::make($integration)->fetchQueue();

    expect($queue)->toHaveCount(1);
    expect($queue[0]['title'])->toBe('Breaking Bad');
    expect($queue[0]['progress'])->toBe(75);
    expect($queue[0]['sizeLeft'])->toBe(250);
});

it('fetchReleases returns formatted releases with approval flag', function () {
    Http::fake([
        '*/api/v3/release*' => Http::response([
            [
                'guid' => 'abc-123',
                'title' => 'Breaking.Bad.S01E01.1080p',
                'indexerId' => 1,
                'size' => 1500000000,
                'quality' => ['quality' => ['name' => 'HD-1080p']],
                'protocol' => 'torrent',
                'rejections' => [],
            ],
            [
                'guid' => 'def-456',
                'title' => 'Breaking.Bad.S01E01.720p',
                'indexerId' => 2,
                'size' => 800000000,
                'quality' => ['quality' => ['name' => 'HD-720p']],
                'protocol' => 'torrent',
                'rejections' => ['Quality not allowed'],
            ],
        ], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $releases = ArrService::make($integration)->fetchReleases(42);

    expect($releases)->toHaveCount(2);
    expect($releases[0]['approved'])->toBeTrue();
    expect($releases[0]['quality'])->toBe('HD-1080p');
    expect($releases[1]['approved'])->toBeFalse();
});

it('downloadRelease posts to /release endpoint', function () {
    Http::fake([
        '*/api/v3/release' => Http::response('', 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $result = ArrService::make($integration)->downloadRelease([
        'guid' => 'abc-123',
        'indexerId' => 1,
        'seriesId' => 42,
    ]);

    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && $body['guid'] === 'abc-123'
            && $body['seriesId'] === 42;
    });
});

it('downloadRelease returns error on server failure', function () {
    Http::fake([
        '*/api/v3/release' => Http::response('Bad Request', 400),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $result = ArrService::make($integration)->downloadRelease([
        'guid' => 'abc-123',
        'indexerId' => 1,
        'seriesId' => 42,
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->not->toBeEmpty();
});

it('base url strips trailing slash for client requests', function () {
    Http::fake([
        '*/api/v3/system/status' => Http::response(['version' => '4.0.0'], 200),
    ]);

    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'url' => 'http://sonarr.example.com:8989/', // trailing slash
    ]);

    ArrService::make($integration)->testConnection();

    Http::assertSent(function ($request) {
        // Should hit /api/v3/system/status on the host (no double slash)
        return str_contains($request->url(), 'http://sonarr.example.com:8989/api/v3/system/status')
            && ! str_contains($request->url(), '//api');
    });
});
