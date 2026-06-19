<?php

use App\Livewire\ArrSearch;
use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\Arr\RadarrService;
use App\Services\Arr\SonarrService;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    // Prevent any test from accidentally hitting the real network.
    // Specific tests can call Http::fake([...]) to override.
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->sonarr = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/media/tv',
    ]);
});

it('renders without any integrations configured', function () {
    $this->sonarr->delete();

    Livewire::test(ArrSearch::class)
        ->assertOk();
});

it('renders with integrations available', function () {
    Http::fake(); // no requests expected on mount

    Livewire::test(ArrSearch::class)
        ->assertOk()
        ->assertSet('results', []);
});

it('searches all enabled integrations simultaneously', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008],
        ], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 27205, 'title' => 'Breaking Dawn', 'year' => 2011],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking');

    // Both integrations contribute results
    expect($component->get('results'))->toHaveCount(2);

    $types = collect($component->get('results'))->pluck('integrationType')->sort()->values()->all();
    expect($types)->toBe(['radarr', 'sonarr']);
});

it('searches the Sonarr API for TV series', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 12345,
                'title' => 'Breaking Bad',
                'year' => 2008,
                'overview' => 'A chemistry teacher...',
                'remotePoster' => 'https://example.com/poster.jpg',
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking');

    expect($component->get('results.0.title'))->toBe('Breaking Bad');
    expect($component->get('results.0.tvdbId'))->toBe(12345);
    expect($component->get('results.0.existsInLibrary'))->toBeFalse();
    expect($component->get('results.0.integrationType'))->toBe('sonarr');
    expect($component->get('results.0.integrationId'))->toBe($this->sonarr->id);
});

it('marks sonarr results as existsInLibrary when the API returns an id', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008],
        ], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->assertSet('results.0.existsInLibrary', true);
});

it('surfaces sonarr episode statistics when series is in library', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'id' => 42,
                'tvdbId' => 12345,
                'title' => 'Breaking Bad',
                'year' => 2008,
                'statistics' => [
                    'episodeFileCount' => 5,
                    'totalEpisodeCount' => 62,
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking');

    expect($component->get('results.0.episodeFileCount'))->toBe(5);
    expect($component->get('results.0.totalEpisodeCount'))->toBe(62);
});

it('returns zero episode counts for sonarr results not in library', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking');

    expect($component->get('results.0.episodeFileCount'))->toBe(0);
    expect($component->get('results.0.totalEpisodeCount'))->toBe(0);
});

it('searches the Radarr API for movies', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 27205, 'title' => 'Inception', 'year' => 2010],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'inception');

    expect($component->get('results.0.title'))->toBe('Inception');
    expect($component->get('results.0.tmdbId'))->toBe(27205);
    expect($component->get('results.0.existsInLibrary'))->toBeFalse();
    expect($component->get('results.0.integrationType'))->toBe('radarr');
    expect($component->get('results.0.integrationId'))->toBe($radarr->id);
});

it('marks radarr results as existsInLibrary when the API returns an id', function () {
    ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['id' => 7, 'tmdbId' => 27205, 'title' => 'Inception', 'year' => 2010],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'inception');

    // Find the Radarr result
    $radarrResult = collect($component->get('results'))->firstWhere('integrationType', 'radarr');
    expect($radarrResult['existsInLibrary'])->toBeTrue();
});

it('surfaces radarr hasFile when the movie file exists on disk', function () {
    ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['id' => 7, 'tmdbId' => 27205, 'title' => 'Inception', 'year' => 2010, 'hasFile' => true],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'inception');

    $radarrResult = collect($component->get('results'))->firstWhere('integrationType', 'radarr');
    expect($radarrResult['hasFile'])->toBeTrue();
});

it('returns hasFile false for radarr movies not yet downloaded', function () {
    ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['id' => 7, 'tmdbId' => 27205, 'title' => 'Inception', 'year' => 2010, 'hasFile' => false],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'inception');

    $radarrResult = collect($component->get('results'))->firstWhere('integrationType', 'radarr');
    expect($radarrResult['hasFile'])->toBeFalse();
});

it('surfaces radarr fileQuality and fileSize when movieFile is present', function () {
    ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            [
                'id' => 7,
                'tmdbId' => 27205,
                'title' => 'Inception',
                'year' => 2010,
                'hasFile' => true,
                'movieFile' => [
                    'quality' => ['quality' => ['name' => 'Bluray-1080p']],
                    'size' => 8589934592,
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'inception');

    $radarrResult = collect($component->get('results'))->firstWhere('integrationType', 'radarr');
    expect($radarrResult['fileQuality'])->toBe('Bluray-1080p');
    expect($radarrResult['fileSize'])->toBe(8589934592);
});

it('returns null fileQuality and fileSize for radarr movies without a file', function () {
    ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['id' => 7, 'tmdbId' => 27205, 'title' => 'Inception', 'year' => 2010, 'hasFile' => false],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'inception');

    $radarrResult = collect($component->get('results'))->firstWhere('integrationType', 'radarr');
    expect($radarrResult['fileQuality'])->toBeNull();
    expect($radarrResult['fileSize'])->toBeNull();
});

it('surfaces sonarr sizeOnDisk when series is in library', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'id' => 42,
                'tvdbId' => 12345,
                'title' => 'Breaking Bad',
                'year' => 2008,
                'statistics' => [
                    'episodeFileCount' => 5,
                    'totalEpisodeCount' => 62,
                    'sizeOnDisk' => 42949672960,
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking');

    expect($component->get('results.0.sizeOnDisk'))->toBe(42949672960);
});

it('does not search when term is too short', function () {
    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'a')
        ->assertSet('results', []);
});

it('skips failed integrations without throwing', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => function () {
            throw new ConnectionException('connection refused');
        },
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->assertSet('results', []);
});

it('submits a request to Sonarr using the result integration', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad'],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 99, 'title' => 'Breaking Bad'], 201),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('request', 0);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/v3/series')) {
            return false;
        }

        $body = $request->data();

        return $request->method() === 'POST'
            && $body['tvdbId'] === 12345
            && $body['qualityProfileId'] === 1
            && $body['rootFolderPath'] === '/media/tv'
            && $body['addOptions']['searchForMissingEpisodes'] === true;
    });
});

it('submits a request to Radarr using the result integration', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 2,
        'root_folder_path' => '/media/movies',
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 27205, 'title' => 'Inception'],
        ], 200),
        '*/api/v3/movie' => Http::response(['id' => 99, 'title' => 'Inception'], 201),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'inception')
        ->call('request', 0);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/v3/movie')) {
            return false;
        }

        $body = $request->data();

        return $body['tmdbId'] === 27205
            && $body['qualityProfileId'] === 2
            && $body['rootFolderPath'] === '/media/movies';
    });
});

it('admin mode returns empty queue on mount', function () {
    Livewire::test(ArrSearch::class)
        ->assertSet('queue', []);
});

it('guest mode loads queue on mount', function () {
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

    Livewire::test(ArrSearch::class, [
        'guestMode' => true,
        'guestIntegrationIds' => [$this->sonarr->id],
    ])
        ->assertSet('queue.0.title', 'Breaking Bad')
        ->assertSet('queue.0.progress', 75);
});

it('guest mode restricts search to provided integration IDs', function () {
    $otherUser = User::factory()->create();
    $otherSonarr = ArrIntegration::factory()->sonarr()->create(['user_id' => $otherUser->id]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 1, 'title' => 'Guest Show'],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class, [
        'guestMode' => true,
        'guestIntegrationIds' => [$this->sonarr->id],
    ])->set('searchTerm', 'guest');

    // Only results from the guest integration, not from $otherSonarr
    expect($component->get('results'))->toHaveCount(1);
    expect($component->get('results.0.integrationId'))->toBe($this->sonarr->id);
});

it('returns poll interval of 5 seconds in guest mode', function () {
    $component = Livewire::test(ArrSearch::class, [
        'guestMode' => true,
        'guestIntegrationIds' => [$this->sonarr->id],
    ]);

    expect($component->get('queuePollInterval'))->toBe(5);
});

it('returns poll interval of 0 in admin mode', function () {
    $component = Livewire::test(ArrSearch::class);

    expect($component->get('queuePollInterval'))->toBe(0);
});

it('passes guestMode through to the component', function () {
    Livewire::test(ArrSearch::class, [
        'guestMode' => true,
        'guestIntegrationIds' => [$this->sonarr->id],
    ])->assertSet('guestMode', true);
});

// --- Series detail slide-over ---

it('openDetail sets showDetail, detailResult, and detailIntegrationId', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 12345,
                'title' => 'Breaking Bad',
                'year' => 2008,
                'seasons' => [
                    ['seasonNumber' => 0, 'monitored' => false],
                    ['seasonNumber' => 1, 'monitored' => true],
                    ['seasonNumber' => 2, 'monitored' => true],
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0);

    $component->assertSet('showDetail', true)
        ->assertSet('detailResult.title', 'Breaking Bad')
        ->assertSet('detailIndex', 0);

    expect($component->get('detailIntegrationId'))->toBe($this->sonarr->id);
});

it('openDetail pre-checks all seasons except specials', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 12345,
                'title' => 'Breaking Bad',
                'seasons' => [
                    ['seasonNumber' => 0],
                    ['seasonNumber' => 1],
                    ['seasonNumber' => 2],
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0);

    expect($component->get('selectedSeasons.0'))->toBeFalse(); // specials off
    expect($component->get('selectedSeasons.1'))->toBeTrue();
    expect($component->get('selectedSeasons.2'))->toBeTrue();
});

it('closeDetail resets all detail state', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 1, 'title' => 'Show', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('closeDetail')
        ->assertSet('showDetail', false)
        ->assertSet('detailResult', null)
        ->assertSet('detailIndex', null)
        ->assertSet('detailIntegrationId', null)
        ->assertSet('selectedSeasons', []);
});

it('toggleAllSeasons checks all seasons', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 1,
                'title' => 'Show',
                'seasons' => [
                    ['seasonNumber' => 0],
                    ['seasonNumber' => 1],
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('toggleAllSeasons', true);

    expect($component->get('selectedSeasons.0'))->toBeTrue();
    expect($component->get('selectedSeasons.1'))->toBeTrue();
});

it('toggleAllSeasons unchecks all seasons', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 1,
                'title' => 'Show',
                'seasons' => [
                    ['seasonNumber' => 1],
                    ['seasonNumber' => 2],
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('toggleAllSeasons', false);

    expect($component->get('selectedSeasons.1'))->toBeFalse();
    expect($component->get('selectedSeasons.2'))->toBeFalse();
});

it('requestDetail sends full seasons payload with monitored flags', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 12345,
                'title' => 'Breaking Bad',
                'titleSlug' => 'breaking-bad',
                'seasons' => [
                    ['seasonNumber' => 0],
                    ['seasonNumber' => 1],
                    ['seasonNumber' => 2],
                ],
            ],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 99, 'title' => 'Breaking Bad'], 201),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('toggleAllSeasons', false)  // deselect all
        ->set('selectedSeasons.1', true)   // select only season 1
        ->call('requestDetail');

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/v3/series')) {
            return false;
        }

        $body = $request->data();
        $seasons = collect($body['seasons']);

        // All seasons sent, only season 1 is monitored
        return count($body['seasons']) === 3
            && $seasons->firstWhere('seasonNumber', 0)['monitored'] === false
            && $seasons->firstWhere('seasonNumber', 1)['monitored'] === true
            && $seasons->firstWhere('seasonNumber', 2)['monitored'] === false;
    });
});

it('requestDetail shows warning when no seasons are selected', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 1,
                'title' => 'Show',
                'seasons' => [['seasonNumber' => 1]],
            ],
        ], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('toggleAllSeasons', false)
        ->call('requestDetail')
        ->assertNotified();

    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v3/series'));
});

it('requestDetail closes the panel after successful submission', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 1,
                'title' => 'Show',
                'titleSlug' => 'show',
                'seasons' => [['seasonNumber' => 1]],
            ],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 1], 201),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('requestDetail')
        ->assertSet('showDetail', false)
        ->assertSet('detailResult', null);
});

function tvMazeFake(int $tvMazeId = 169, array $episodes = [], array $cast = []): void
{
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => $tvMazeId], 200),
        "api.tvmaze.com/shows/{$tvMazeId}/episodes*" => Http::response($episodes, 200),
        "api.tvmaze.com/shows/{$tvMazeId}/cast*" => Http::response($cast, 200),
    ]);
}

it('loadDetailEpisodes fetches episodes from TV Maze by tvdbId', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'seasons' => [['seasonNumber' => 1], ['seasonNumber' => 2]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 169], 200),
        'api.tvmaze.com/shows/169/episodes*' => Http::response([
            ['season' => 1, 'number' => 1, 'name' => 'Pilot', 'airdate' => '2008-01-20', 'summary' => null],
            ['season' => 1, 'number' => 2, 'name' => "Cat's in the Bag", 'airdate' => '2008-01-27', 'summary' => null],
            ['season' => 2, 'number' => 1, 'name' => 'Seven Thirty-Seven', 'airdate' => '2009-03-08', 'summary' => null],
        ], 200),
        'api.tvmaze.com/shows/169/cast*' => Http::response([], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes');

    expect($component->get('detailEpisodes.1.0.title'))->toBe('Pilot');
    expect($component->get('detailEpisodes.1.0.airDate'))->toBe('2008-01-20');
    expect($component->get('detailEpisodes.1.1.title'))->toBe("Cat's in the Bag");
    expect($component->get('detailEpisodes.2.0.title'))->toBe('Seven Thirty-Seven');
});

it('loadDetailEpisodes strips HTML from episode overviews', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 99, 'title' => 'Show', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 1], 200),
        'api.tvmaze.com/shows/1/episodes*' => Http::response([
            ['season' => 1, 'number' => 1, 'name' => 'Pilot', 'airdate' => null, 'summary' => '<p>A <b>great</b> episode.</p>'],
        ], 200),
        'api.tvmaze.com/shows/1/cast*' => Http::response([], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes');

    expect($component->get('detailEpisodes.1.0.overview'))->toBe('A great episode.');
});

it('loadDetailEpisodes fetches cast alongside episodes', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 169], 200),
        'api.tvmaze.com/shows/169/episodes*' => Http::response([], 200),
        'api.tvmaze.com/shows/169/cast*' => Http::response([
            [
                'person' => ['name' => 'Bryan Cranston', 'image' => ['medium' => 'https://example.com/bc.jpg']],
                'character' => ['name' => 'Walter White'],
                'self' => false,
                'voice' => false,
            ],
            [
                'person' => ['name' => 'Aaron Paul', 'image' => null],
                'character' => ['name' => 'Jesse Pinkman'],
                'self' => false,
                'voice' => false,
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes');

    expect($component->get('detailCast.0.actor'))->toBe('Bryan Cranston');
    expect($component->get('detailCast.0.character'))->toBe('Walter White');
    expect($component->get('detailCast.0.photo'))->toBe('https://example.com/bc.jpg');
    expect($component->get('detailCast.1.actor'))->toBe('Aaron Paul');
    expect($component->get('detailCast.1.photo'))->toBeNull();
});

it('loadDetailEpisodes excludes voice actors and self appearances from cast', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 1, 'title' => 'Show', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 1], 200),
        'api.tvmaze.com/shows/1/episodes*' => Http::response([], 200),
        'api.tvmaze.com/shows/1/cast*' => Http::response([
            ['person' => ['name' => 'Real Actor', 'image' => null], 'character' => ['name' => 'Hero'], 'self' => false, 'voice' => false],
            ['person' => ['name' => 'Voice Guy', 'image' => null], 'character' => ['name' => 'Narrator'], 'self' => false, 'voice' => true],
            ['person' => ['name' => 'Himself', 'image' => null], 'character' => ['name' => 'Himself'], 'self' => true, 'voice' => false],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes');

    expect($component->get('detailCast'))->toHaveCount(1);
    expect($component->get('detailCast.0.actor'))->toBe('Real Actor');
});

it('loadDetailEpisodes returns empty arrays when TV Maze lookup fails', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/*' => Http::response(null, 404),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes');

    expect($component->get('detailEpisodes'))->toBe([]);
    expect($component->get('detailCast'))->toBe([]);
});

it('loadDetailEpisodes is a no-op for Radarr results', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 27205, 'title' => 'Inception'],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'inception')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes');

    expect($component->get('detailEpisodes'))->toBe([]);
    expect($component->get('detailCast'))->toBe([]);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'tvmaze'));
});

it('closeDetail resets detailEpisodes and detailCast', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 1, 'title' => 'Show', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 1], 200),
        'api.tvmaze.com/shows/1/episodes*' => Http::response([
            ['season' => 1, 'number' => 1, 'name' => 'Pilot', 'airdate' => null, 'summary' => null],
        ], 200),
        'api.tvmaze.com/shows/1/cast*' => Http::response([
            ['person' => ['name' => 'Actor', 'image' => null], 'character' => ['name' => 'Hero'], 'self' => false, 'voice' => false],
        ], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes')
        ->call('closeDetail')
        ->assertSet('detailEpisodes', [])
        ->assertSet('detailCast', []);
});

it('loadDetailEpisodes fetches Sonarr episode status for in-library series', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'seasons' => [['seasonNumber' => 1], ['seasonNumber' => 2]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 169], 200),
        'api.tvmaze.com/shows/169/episodes*' => Http::response([], 200),
        'api.tvmaze.com/shows/169/cast*' => Http::response([], 200),
        '*/api/v3/episode*' => Http::response([
            ['seasonNumber' => 1, 'episodeNumber' => 1, 'hasFile' => true],
            ['seasonNumber' => 1, 'episodeNumber' => 2, 'hasFile' => false],
            ['seasonNumber' => 2, 'episodeNumber' => 1, 'hasFile' => true],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes');

    expect($component->get('detailSonarrEpisodeStatus.1.1'))->toBeTrue();
    expect($component->get('detailSonarrEpisodeStatus.1.2'))->toBeFalse();
    expect($component->get('detailSonarrEpisodeStatus.2.1'))->toBeTrue();
});

it('loadDetailEpisodes does not fetch Sonarr episode status when series is not in library', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            // No 'id' key — not in library
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 169], 200),
        'api.tvmaze.com/shows/169/episodes*' => Http::response([], 200),
        'api.tvmaze.com/shows/169/cast*' => Http::response([], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes');

    expect($component->get('detailSonarrEpisodeStatus'))->toBe([]);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v3/episode'));
});

it('closeDetail resets detailSonarrEpisodeStatus', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 1, 'title' => 'Show', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 1], 200),
        'api.tvmaze.com/shows/1/episodes*' => Http::response([], 200),
        'api.tvmaze.com/shows/1/cast*' => Http::response([], 200),
        '*/api/v3/episode*' => Http::response([
            ['seasonNumber' => 1, 'episodeNumber' => 1, 'hasFile' => true],
        ], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes')
        ->call('closeDetail')
        ->assertSet('detailSonarrEpisodeStatus', []);
});

it('loadDetailEpisodes populates detailSonarrEpisodeFileInfo when episodeFile is embedded', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 169], 200),
        'api.tvmaze.com/shows/169/episodes*' => Http::response([], 200),
        'api.tvmaze.com/shows/169/cast*' => Http::response([], 200),
        '*/api/v3/episode*' => Http::response([
            [
                'seasonNumber' => 1,
                'episodeNumber' => 1,
                'hasFile' => true,
                'episodeFile' => [
                    'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
                    'size' => 1073741824,
                ],
            ],
            ['seasonNumber' => 1, 'episodeNumber' => 2, 'hasFile' => false],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes');

    expect($component->get('detailSonarrEpisodeFileInfo.1.1.quality'))->toBe('WEBDL-1080p');
    expect($component->get('detailSonarrEpisodeFileInfo.1.1.size'))->toBe(1073741824);
    expect($component->get('detailSonarrEpisodeFileInfo.1'))->not->toHaveKey(2);
});

it('closeDetail resets detailSonarrEpisodeFileInfo', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 1, 'title' => 'Show', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 1], 200),
        'api.tvmaze.com/shows/1/episodes*' => Http::response([], 200),
        'api.tvmaze.com/shows/1/cast*' => Http::response([], 200),
        '*/api/v3/episode*' => Http::response([
            [
                'seasonNumber' => 1,
                'episodeNumber' => 1,
                'hasFile' => true,
                'episodeFile' => ['quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'size' => 1073741824],
            ],
        ], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'show')
        ->call('openDetail', 0)
        ->call('loadDetailEpisodes')
        ->call('closeDetail')
        ->assertSet('detailSonarrEpisodeFileInfo', []);
});

// --- Individual episode requests ---

it('requestEpisode triggers EpisodeSearch for a series already in library', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::sequence()
            ->push([['tvdbId' => 12345, 'id' => 42, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad', 'seasons' => [['seasonNumber' => 1]]]], 200)
            // requestEpisode internal lookup
            ->push([['tvdbId' => 12345, 'id' => 42, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad', 'seasons' => [['seasonNumber' => 1]]]], 200),
        '*/api/v3/episode*' => Http::response([
            ['id' => 101, 'episodeNumber' => 1, 'seasonNumber' => 1, 'title' => 'Pilot'],
            ['id' => 102, 'episodeNumber' => 2, 'seasonNumber' => 1, 'title' => "Cat's in the Bag"],
        ], 200),
        '*/api/v3/episode/monitor' => Http::response(null, 200),
        '*/api/v3/command' => Http::response(['id' => 1], 201),
        'api.tvmaze.com/*' => Http::response(['id' => 1], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('requestEpisode', 1, 2)
        ->assertNotified();

    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/api/v3/episode/monitor')
        && in_array(102, $r->data()['episodeIds'] ?? []));

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v3/command')
        && ($r->data()['name'] ?? '') === 'EpisodeSearch'
        && in_array(102, $r->data()['episodeIds'] ?? []));
});

it('requestEpisode adds the series first when not in library', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::sequence()
            // initial search
            ->push([['tvdbId' => 12345, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad', 'seasons' => [['seasonNumber' => 1]]]], 200)
            // internal lookup in requestEpisode — no 'id', not in library
            ->push([['tvdbId' => 12345, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad', 'seasons' => [['seasonNumber' => 1]]]], 200),
        '*/api/v3/series' => Http::response(['id' => 99, 'title' => 'Breaking Bad'], 201),
        '*/api/v3/episode*' => Http::response([
            ['id' => 201, 'episodeNumber' => 1, 'seasonNumber' => 1, 'title' => 'Pilot'],
        ], 200),
        '*/api/v3/episode/monitor' => Http::response(null, 200),
        '*/api/v3/command' => Http::response(['id' => 1], 201),
        'api.tvmaze.com/*' => Http::response(['id' => 1], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('requestEpisode', 1, 1)
        ->assertNotified();

    // Series should be added with seasons unmonitored
    Http::assertSent(function ($r) {
        if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/api/v3/series')) {
            return false;
        }
        $seasons = $r->data()['seasons'] ?? [];

        return collect($seasons)->every(fn ($s) => $s['monitored'] === false)
            && ($r->data()['addOptions']['searchForMissingEpisodes'] ?? true) === false;
    });
});

it('requestEpisode is a no-op when no detail is open', function () {
    Livewire::test(ArrSearch::class)
        ->call('requestEpisode', 1, 1);

    Http::assertNothingSent();
});

it('requestDetail is a no-op for Radarr results', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([], 200),
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 100, 'title' => 'Inception'],
        ], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'inception')
        ->call('openDetail', 0)
        ->call('requestDetail')
        ->assertSet('showDetail', true); // panel stays open, nothing submitted

    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v3/movie'));
});

// ── Discover ──────────────────────────────────────────────────────────────────

it('sets tmdbConfigured to true when TMDB is configured', function () {
    config(['services.tmdb.api_key' => null]);

    app()->bind(TmdbService::class, function () {
        $mock = Mockery::mock(TmdbService::class)->makePartial();
        $mock->shouldReceive('isConfigured')->andReturn(true);

        return $mock;
    });

    Http::fake();

    $component = Livewire::test(ArrSearch::class);

    expect($component->get('tmdbConfigured'))->toBeTrue();
});

it('sets tmdbConfigured to false when TMDB is not configured', function () {
    app()->bind(TmdbService::class, function () {
        $mock = Mockery::mock(TmdbService::class)->makePartial();
        $mock->shouldReceive('isConfigured')->andReturn(false);

        return $mock;
    });

    Http::fake();

    $component = Livewire::test(ArrSearch::class);

    expect($component->get('tmdbConfigured'))->toBeFalse();
});

it('loadDiscover populates trending and popular sections', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    $trendingItems = [
        ['id' => 10, 'title' => 'Trending Movie', 'media_type' => 'movie', 'poster_path' => '/poster.jpg', 'overview' => 'Great movie', 'release_date' => '2024-01-01', 'vote_average' => 8.5],
    ];
    $popularMovies = [
        ['id' => 20, 'title' => 'Popular Movie', 'poster_path' => null, 'overview' => null, 'release_date' => '2024-03-01', 'vote_average' => 7.2],
    ];

    app()->bind(TmdbService::class, function () use ($trendingItems, $popularMovies) {
        $mock = Mockery::mock(TmdbService::class)->makePartial();
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('getTrending')->andReturn(array_map(fn ($i) => [
            'tmdb_id' => $i['id'],
            'title' => $i['title'],
            'media_type' => $i['media_type'],
            'year' => '2024',
            'overview' => $i['overview'],
            'poster_url' => $i['poster_path'] ? 'https://image.tmdb.org/t/p/w500'.$i['poster_path'] : null,
            'backdrop_url' => null,
            'vote_average' => $i['vote_average'],
            'genre_ids' => [],
        ], $trendingItems));
        $mock->shouldReceive('getPopularMovies')->andReturn(array_map(fn ($i) => [
            'tmdb_id' => $i['id'],
            'title' => $i['title'],
            'media_type' => 'movie',
            'year' => '2024',
            'overview' => null,
            'poster_url' => null,
            'backdrop_url' => null,
            'vote_average' => $i['vote_average'],
            'genre_ids' => [],
        ], $popularMovies));
        $mock->shouldReceive('getPopularTv')->andReturn([]);
        $mock->shouldReceive('getUpcomingMovies')->andReturn([]);
        $mock->shouldReceive('getMovieGenres')->andReturn([]);
        $mock->shouldReceive('getTvGenres')->andReturn([]);

        return $mock;
    });

    Http::fake([
        '*/api/v3/series*' => Http::response([], 200),
        '*/api/v3/movie*' => Http::response([], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->call('loadDiscover');

    expect($component->get('discoverLoaded'))->toBeTrue();
    expect($component->get('trendingItems'))->toHaveCount(1);
    expect($component->get('trendingItems.0.tmdb_id'))->toBe(10);
    expect($component->get('popularMovies'))->toHaveCount(1);
    expect($component->get('popularMovies.0.tmdb_id'))->toBe(20);
});

it('loadDiscover marks items already in library', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    app()->bind(TmdbService::class, function () {
        $mock = Mockery::mock(TmdbService::class)->makePartial();
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('getTrending')->andReturn([
            ['tmdb_id' => 999, 'title' => 'In Library Movie', 'media_type' => 'movie', 'year' => '2023', 'overview' => null, 'poster_url' => null, 'backdrop_url' => null, 'vote_average' => null, 'genre_ids' => []],
            ['tmdb_id' => 888, 'title' => 'Not In Library', 'media_type' => 'movie', 'year' => '2023', 'overview' => null, 'poster_url' => null, 'backdrop_url' => null, 'vote_average' => null, 'genre_ids' => []],
        ]);
        $mock->shouldReceive('getPopularMovies')->andReturn([]);
        $mock->shouldReceive('getPopularTv')->andReturn([]);
        $mock->shouldReceive('getUpcomingMovies')->andReturn([]);
        $mock->shouldReceive('getMovieGenres')->andReturn([]);
        $mock->shouldReceive('getTvGenres')->andReturn([]);

        return $mock;
    });

    // Radarr library contains tmdbId 999 (downloaded), not 888
    Http::fake([
        '*/api/v3/series*' => Http::response([], 200),
        '*/api/v3/movie*' => Http::response([
            ['id' => 1, 'tmdbId' => 999, 'title' => 'In Library Movie', 'hasFile' => true],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->call('loadDiscover');

    expect($component->get('trendingItems.0.existsInLibrary'))->toBeTrue();
    expect($component->get('trendingItems.0.isDownloaded'))->toBeTrue();
    expect($component->get('trendingItems.1.existsInLibrary'))->toBeFalse();
    expect($component->get('trendingItems.1.isDownloaded'))->toBeFalse();
});

it('loadDiscover is a no-op when TMDB is not configured', function () {
    app()->bind(TmdbService::class, function () {
        $mock = Mockery::mock(TmdbService::class)->makePartial();
        $mock->shouldReceive('isConfigured')->andReturn(false);
        $mock->shouldNotReceive('getTrending');

        return $mock;
    });

    Http::fake();

    $component = Livewire::test(ArrSearch::class)
        ->call('loadDiscover');

    expect($component->get('discoverLoaded'))->toBeTrue();
    expect($component->get('trendingItems'))->toBeEmpty();
});

it('browseGenre loads discover results filtered by genre', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    $genreResults = [
        ['tmdb_id' => 101, 'title' => 'Action Movie', 'media_type' => 'movie', 'year' => '2024', 'overview' => null, 'poster_url' => null, 'backdrop_url' => null, 'vote_average' => 7.5, 'genre_ids' => [28]],
    ];

    app()->bind(TmdbService::class, function () use ($genreResults) {
        $mock = Mockery::mock(TmdbService::class)->makePartial();
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('getWatchProviders')->andReturn([]);
        $mock->shouldReceive('discoverMovies')
            ->with(Mockery::on(fn ($p) => ($p['with_genres'] ?? null) === 28))
            ->andReturn($genreResults);

        return $mock;
    });

    Http::fake([
        '*/api/v3/series*' => Http::response([], 200),
        '*/api/v3/movie*' => Http::response([], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('tmdbConfigured', true)
        ->call('browseGenre', 28, 'movie');

    expect($component->get('browseGenreId'))->toBe(28);
    expect($component->get('browseGenreType'))->toBe('movie');
    expect($component->get('browseResults'))->toHaveCount(1);
    expect($component->get('browseResults.0.title'))->toBe('Action Movie');
    expect($component->get('browseLoading'))->toBeFalse();
});

it('clearBrowse resets genre browse state', function () {
    Http::fake();

    Livewire::test(ArrSearch::class)
        ->set('browseGenreId', 28)
        ->set('browseGenreType', 'movie')
        ->set('browseResults', [['tmdb_id' => 1, 'title' => 'Test']])
        ->call('clearBrowse')
        ->assertSet('browseGenreId', null)
        ->assertSet('browseGenreType', null)
        ->assertSet('browseResults', []);
});

it('requestFromDiscover resolves a Radarr movie and opens detail panel', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/media/movies',
    ]);

    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 27205, 'title' => 'Inception', 'year' => 2010, 'titleSlug' => 'inception'],
        ], 200),
        // library fetch (no matches)
        '*/api/v3/movie*' => Http::response([], 200),
        '*/api/v3/series*' => Http::response([], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('tmdbConfigured', true)
        ->call('requestFromDiscover', 27205, 'movie');

    expect($component->get('showDetail'))->toBeTrue();
    expect($component->get('detailResult.title'))->toBe('Inception');
    expect($component->get('detailResult.integrationType'))->toBe('radarr');
});

it('requestFromDiscover resolves a Sonarr TV show via TVDB and opens detail panel', function () {
    Http::fake([
        // TMDB external IDs
        'api.themoviedb.org/3/tv/*/external_ids*' => Http::response(['tvdb_id' => 12345, 'imdb_id' => null], 200),
        // Sonarr lookup
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        // library fetches
        '*/api/v3/series*' => Http::response([], 200),
    ]);

    app()->bind(TmdbService::class, function () {
        $mock = Mockery::mock(TmdbService::class)->makePartial();
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('getTvExternalIds')->with(1396)->andReturn(['tvdb_id' => 12345]);

        return $mock;
    });

    $component = Livewire::test(ArrSearch::class)
        ->set('tmdbConfigured', true)
        ->call('requestFromDiscover', 1396, 'tv');

    expect($component->get('showDetail'))->toBeTrue();
    expect($component->get('detailResult.title'))->toBe('Breaking Bad');
    expect($component->get('detailResult.integrationType'))->toBe('sonarr');
});

it('requestFromDiscover shows warning when title cannot be resolved', function () {
    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([], 200),
        '*/api/v3/movie*' => Http::response([], 200),
        '*/api/v3/series*' => Http::response([], 200),
    ]);

    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Livewire::test(ArrSearch::class)
        ->set('tmdbConfigured', true)
        ->call('requestFromDiscover', 99999, 'movie')
        ->assertSet('showDetail', false)
        ->assertNotified();
});

it('fetchLibraryTmdbIds returns radarr movie tmdb ids with download status', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/movie*' => Http::response([
            ['id' => 1, 'tmdbId' => 500, 'title' => 'Movie A', 'hasFile' => true],
            ['id' => 2, 'tmdbId' => 501, 'title' => 'Movie B', 'hasFile' => false],
            ['id' => 3, 'title' => 'Movie No TMDB'],
        ], 200),
    ]);

    $service = new RadarrService($radarr);
    $map = $service->fetchLibraryTmdbIds();

    expect($map)->toBe([500 => true, 501 => false]);
});

it('fetchLibraryTmdbIds returns sonarr series tmdb ids with download status', function () {
    Http::fake([
        '*/api/v3/series*' => Http::response([
            ['id' => 1, 'tmdbId' => 200, 'title' => 'Show A', 'statistics' => ['episodeFileCount' => 5, 'totalEpisodeCount' => 62]],
            ['id' => 2, 'tmdbId' => 201, 'title' => 'Show B', 'statistics' => ['episodeFileCount' => 0, 'totalEpisodeCount' => 10]],
            ['id' => 3, 'title' => 'Show No TMDB'],
        ], 200),
    ]);

    $service = new SonarrService($this->sonarr);
    $map = $service->fetchLibraryTmdbIds();

    expect($map)->toBe([200 => true, 201 => false]);
});

// ── libraryId in search results ───────────────────────────────────────────────

it('sonarr search includes libraryId when item exists in library', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 77, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008],
        ], 200),
    ]);

    $service = new SonarrService($this->sonarr);
    $results = $service->search('breaking');

    expect($results[0]['existsInLibrary'])->toBeTrue();
    expect($results[0]['libraryId'])->toBe(77);
});

it('sonarr search has null libraryId when item is not in library', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008],
        ], 200),
    ]);

    $service = new SonarrService($this->sonarr);
    $results = $service->search('breaking');

    expect($results[0]['existsInLibrary'])->toBeFalse();
    expect($results[0]['libraryId'])->toBeNull();
});

it('radarr search includes libraryId when item exists in library', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([
            ['id' => 55, 'tmdbId' => 27205, 'title' => 'Inception', 'year' => 2010],
        ], 200),
    ]);

    $service = new RadarrService($radarr);
    $results = $service->search('inception');

    expect($results[0]['existsInLibrary'])->toBeTrue();
    expect($results[0]['libraryId'])->toBe(55);
});

// ── triggerAutomaticSearch ────────────────────────────────────────────────────

it('sonarr triggerAutomaticSearch posts SeriesSearch command', function () {
    Http::fake([
        '*/api/v3/command*' => Http::response([], 201),
    ]);

    $service = new SonarrService($this->sonarr);
    $result = $service->triggerAutomaticSearch(42);

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($req) => str_contains($req->url(), '/command')
        && $req->data()['name'] === 'SeriesSearch'
        && $req->data()['seriesId'] === 42
    );
});

it('radarr triggerAutomaticSearch posts MoviesSearch command', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/command*' => Http::response([], 201),
    ]);

    $service = new RadarrService($radarr);
    $result = $service->triggerAutomaticSearch(99);

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($req) => str_contains($req->url(), '/command')
        && $req->data()['name'] === 'MoviesSearch'
        && $req->data()['movieIds'] === [99]
    );
});

it('triggerAutomaticSearch returns error when command fails', function () {
    Http::fake([
        '*/api/v3/command*' => Http::response(['title' => 'Server error'], 500),
    ]);

    $service = new SonarrService($this->sonarr);
    $result = $service->triggerAutomaticSearch(42);

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->not->toBeEmpty();
});

// ── Livewire: triggerAutomaticSearch action ───────────────────────────────────

it('livewire triggerAutomaticSearch triggers search for library item', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008, 'seasons' => []],
        ], 200),
        '*/api/v3/command*' => Http::response([], 201),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('triggerAutomaticSearch')
        ->assertNotified();

    Http::assertSent(fn ($req) => str_contains($req->url(), '/command')
        && $req->data()['name'] === 'SeriesSearch'
    );
});

it('livewire triggerAutomaticSearch is blocked in guest mode', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008, 'seasons' => []],
        ], 200),
    ]);

    Livewire::test(ArrSearch::class, ['guestMode' => true, 'guestIntegrationIds' => [$this->sonarr->id]])
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('triggerAutomaticSearch');

    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/command'));
});

// ── Livewire: loadDetailReleases action ──────────────────────────────────────

it('livewire loadDetailReleases fetches releases for library item', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008, 'seasons' => []],
        ], 200),
        '*/api/v3/release*' => Http::response([
            [
                'guid' => 'abc-123',
                'title' => 'Breaking.Bad.S01.1080p.BluRay',
                'indexerId' => 1,
                'size' => 15_000_000_000,
                'quality' => ['quality' => ['name' => 'Bluray-1080p']],
                'protocol' => 'torrent',
                'rejections' => [],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadDetailReleases');

    expect($component->get('detailReleases'))->toHaveCount(1);
    expect($component->get('detailReleases.0.title'))->toBe('Breaking.Bad.S01.1080p.BluRay');
    expect($component->get('detailReleases.0.approved'))->toBeTrue();
    expect($component->get('detailReleases.0.guid'))->toBe('abc-123');
});

it('livewire loadDetailReleases is blocked in guest mode', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008, 'seasons' => []],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class, ['guestMode' => true, 'guestIntegrationIds' => [$this->sonarr->id]])
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadDetailReleases');

    expect($component->get('detailReleases'))->toBe([]);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/release'));
});

// ── Livewire: downloadDetailRelease action ───────────────────────────────────

it('livewire downloadDetailRelease sends release to download client', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008, 'seasons' => []],
        ], 200),
        '*/api/v3/release*' => Http::response([], 201),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('downloadDetailRelease', 'abc-guid', 7)
        ->assertNotified();

    Http::assertSent(fn ($req) => str_contains($req->url(), '/release')
        && $req->data()['guid'] === 'abc-guid'
        && $req->data()['indexerId'] === 7
        && $req->data()['seriesId'] === 42
    );
});

it('livewire closeDetail clears detailReleases', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'year' => 2008, 'seasons' => []],
        ], 200),
        '*/api/v3/release*' => Http::response([
            ['guid' => 'abc', 'title' => 'Release A', 'indexerId' => 1, 'size' => 0, 'quality' => ['quality' => ['name' => 'HD']], 'protocol' => 'torrent', 'rejections' => []],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadDetailReleases');

    expect($component->get('detailReleases'))->toHaveCount(1);

    $component->call('closeDetail');

    expect($component->get('detailReleases'))->toBe([]);
    expect($component->get('showDetail'))->toBeFalse();
});

// ── Live queue refresh ───────────────────────────────────────────────────────

it('loadQueue dispatches refreshArrQueue event in admin mode', function () {
    Livewire::test(ArrSearch::class)
        ->call('loadQueue')
        ->assertDispatched('refreshArrQueue');
});

it('loadQueue does not dispatch refreshArrQueue in guest mode', function () {
    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);

    Livewire::test(ArrSearch::class, [
        'guestMode' => true,
        'guestIntegrationIds' => [$this->sonarr->id],
    ])
        ->call('loadQueue')
        ->assertNotDispatched('refreshArrQueue');
});

// ── requestEpisode retry ─────────────────────────────────────────────────────

it('requestEpisode retries episode fetch when series was just added and episodes not yet indexed', function () {
    SonarrService::$episodeRetryDelayUs = 0;

    // NOTE: Http::fake map-invokes ALL stubs for every request, so a Http::sequence() on *episode*
    // would be consumed by monitor requests too. Use a closure that returns null for monitor URLs.
    $episodeFetchCalls = 0;

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Dark', 'titleSlug' => 'dark', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 77, 'title' => 'Dark'], 201),
        '*/api/v3/episode/monitor' => Http::response(null, 200),
        '*/api/v3/command' => Http::response(['id' => 1], 201),
        // Return null for monitor URLs so the specific stub above wins; simulate empty then populated.
        '*/api/v3/episode*' => function ($request) use (&$episodeFetchCalls) {
            if (str_contains($request->url(), '/monitor')) {
                return null;
            }
            $episodeFetchCalls++;

            return $episodeFetchCalls === 1
                ? Http::response([], 200)
                : Http::response([['id' => 301, 'episodeNumber' => 1, 'seasonNumber' => 1, 'title' => 'Secrets']], 200);
        },
        'api.tvmaze.com/*' => Http::response(['id' => 1], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'dark')
        ->call('openDetail', 0)
        ->call('requestEpisode', 1, 1)
        ->assertNotified();

    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/api/v3/episode/monitor')
        && in_array(301, $r->data()['episodeIds'] ?? []));

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v3/command')
        && ($r->data()['name'] ?? '') === 'EpisodeSearch');
})->after(fn () => SonarrService::$episodeRetryDelayUs = 500_000);

it('requestEpisode shows error when episode still not found after all retries', function () {
    SonarrService::$episodeRetryDelayUs = 0;

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Dark', 'titleSlug' => 'dark', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 77, 'title' => 'Dark'], 201),
        // All attempts return empty — episode never indexes in time
        '*/api/v3/episode*' => Http::response([], 200),
        'api.tvmaze.com/*' => Http::response(['id' => 1], 200),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'dark')
        ->call('openDetail', 0)
        ->call('requestEpisode', 1, 1)
        ->assertNotified();

    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/v3/command'));
})->after(fn () => SonarrService::$episodeRetryDelayUs = 500_000);

// ── Livewire: loadEpisodeReleases action ─────────────────────────────────────

it('loadEpisodeReleases fetches episode releases for an in-library series', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        '*/api/v3/episode*' => Http::response([
            ['id' => 101, 'episodeNumber' => 1, 'seasonNumber' => 1, 'title' => 'Pilot'],
        ], 200),
        '*/api/v3/release*' => Http::response([
            [
                'guid' => 'ep-guid-1',
                'title' => 'Breaking.Bad.S01E01.1080p',
                'indexerId' => 3,
                'size' => 2_000_000_000,
                'quality' => ['quality' => ['name' => 'Bluray-1080p']],
                'protocol' => 'torrent',
                'rejections' => [],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadEpisodeReleases', 1, 1);

    expect($component->get('detailReleases'))->toHaveCount(1);
    expect($component->get('detailReleases.0.guid'))->toBe('ep-guid-1');
    expect($component->get('detailEpisodeId'))->toBe(101);
    expect($component->get('detailReleasesLabel'))->toBe('S01E01');

    // Should have passed episodeId to /release
    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v3/release')
        && $r->method() === 'GET'
        && ($r->data()['episodeId'] ?? null) == 101);
});

it('loadEpisodeReleases adds the series first when not in library', function () {
    SonarrService::$episodeRetryDelayUs = 0;

    Http::fake([
        // Initial search — series not in library
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Dark', 'titleSlug' => 'dark', 'seasons' => [['seasonNumber' => 2]]],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 55, 'title' => 'Dark'], 201),
        '*/api/v3/episode*' => Http::response([
            ['id' => 201, 'episodeNumber' => 3, 'seasonNumber' => 2, 'title' => 'The Cave'],
        ], 200),
        '*/api/v3/release*' => Http::response([
            [
                'guid' => 'dark-ep-guid',
                'title' => 'Dark.S02E03',
                'indexerId' => 2,
                'size' => 1_500_000_000,
                'quality' => ['quality' => ['name' => '1080p']],
                'protocol' => 'usenet',
                'rejections' => [],
            ],
        ], 200),
        'api.tvmaze.com/*' => Http::response(['id' => 1], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'dark')
        ->call('openDetail', 0)
        ->call('loadEpisodeReleases', 2, 3);

    expect($component->get('detailReleases'))->toHaveCount(1);
    expect($component->get('detailReleasesLabel'))->toBe('S02E03');
    expect($component->get('detailResult.existsInLibrary'))->toBeTrue();
    expect($component->get('detailResult.libraryId'))->toBe(55);

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v3/series')
        && ($r->data()['addOptions']['searchForMissingEpisodes'] ?? true) === false);
})->after(fn () => SonarrService::$episodeRetryDelayUs = 500_000);

it('loadEpisodeReleases is blocked in guest mode', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad', 'seasons' => []],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class, ['guestMode' => true, 'guestIntegrationIds' => [$this->sonarr->id]])
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadEpisodeReleases', 1, 1);

    expect($component->get('detailReleases'))->toBe([]);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/episode'));
});

it('downloadDetailRelease includes episodeId when in episode search context', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        '*/api/v3/episode*' => Http::response([
            ['id' => 101, 'episodeNumber' => 1, 'seasonNumber' => 1, 'title' => 'Pilot'],
        ], 200),
        '*/api/v3/release*' => Http::response([], 201),
    ]);

    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->set('detailEpisodeId', 101)
        ->call('downloadDetailRelease', 'ep-guid', 5)
        ->assertNotified();

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/release')
        && $r->data()['guid'] === 'ep-guid'
        && $r->data()['seriesId'] === 42
        && ($r->data()['episodeId'] ?? null) === 101);
});

it('closeDetail clears episode search context', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['id' => 42, 'tvdbId' => 12345, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad', 'seasons' => [['seasonNumber' => 1]]],
        ], 200),
        '*/api/v3/episode*' => Http::response([
            ['id' => 101, 'episodeNumber' => 1, 'seasonNumber' => 1, 'title' => 'Pilot'],
        ], 200),
        '*/api/v3/release*' => Http::response([
            ['guid' => 'ep-g', 'title' => 'S01E01', 'indexerId' => 1, 'size' => 0, 'quality' => ['quality' => ['name' => 'HD']], 'protocol' => 'torrent', 'rejections' => []],
        ], 200),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'breaking')
        ->call('openDetail', 0)
        ->call('loadEpisodeReleases', 1, 1);

    expect($component->get('detailReleasesLabel'))->toBe('S01E01');
    expect($component->get('detailEpisodeId'))->toBe(101);

    $component->call('closeDetail');

    expect($component->get('detailReleasesLabel'))->toBeNull();
    expect($component->get('detailEpisodeId'))->toBeNull();
    expect($component->get('detailReleases'))->toBe([]);
});
