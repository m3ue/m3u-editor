<?php

use App\Livewire\ArrSearch;
use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\User;
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
    $this->integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/media/tv',
    ]);
});

it('renders without an integration selected', function () {
    Livewire::test(ArrSearch::class)
        ->assertOk();
});

it('returns the active integration', function () {
    $component = Livewire::test(ArrSearch::class, ['integrationId' => $this->integration->id]);

    expect($component->get('integration'))->toBeInstanceOf(ArrIntegration::class);
    expect($component->get('integration')->id)->toBe($this->integration->id);
});

it('searches the Sonarr API', function () {
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

    Livewire::test(ArrSearch::class, ['integrationId' => $this->integration->id])
        ->set('searchTerm', 'breaking')
        ->assertSet('results.0.title', 'Breaking Bad')
        ->assertSet('results.0.tvdbId', 12345);
});

it('searches the Radarr API when integration is radarr', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 27205, 'title' => 'Inception', 'year' => 2010],
        ], 200),
    ]);

    Livewire::test(ArrSearch::class, ['integrationId' => $radarr->id])
        ->set('searchTerm', 'inception')
        ->assertSet('results.0.title', 'Inception')
        ->assertSet('results.0.tmdbId', 27205);
});

it('does not search when term is too short', function () {
    Livewire::test(ArrSearch::class, ['integrationId' => $this->integration->id])
        ->set('searchTerm', 'a')
        ->assertSet('results', []);
});

it('clears results when integration changes', function () {
    $other = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([['tvdbId' => 1, 'title' => 'Foo']], 200),
    ]);

    $component = Livewire::test(ArrSearch::class, ['integrationId' => $this->integration->id])
        ->set('searchTerm', 'foo')
        ->assertSet('results.0.title', 'Foo');

    $component->set('integrationId', $other->id)
        ->assertSet('results', [])
        ->assertSet('searchTerm', '');
});

it('submits a request to Sonarr when request action fires', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 12345, 'title' => 'Breaking Bad', 'titleSlug' => 'breaking-bad'],
        ], 200),
        '*/api/v3/series' => Http::response(['id' => 99, 'title' => 'Breaking Bad'], 201),
    ]);

    Livewire::test(ArrSearch::class, ['integrationId' => $this->integration->id])
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

it('submits a request to Radarr with tmdbId', function () {
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'quality_profile_id' => 2,
        'root_folder_path' => '/media/movies',
    ]);

    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 27205, 'title' => 'Inception'],
        ], 200),
        '*/api/v3/movie' => Http::response(['id' => 99, 'title' => 'Inception'], 201),
    ]);

    Livewire::test(ArrSearch::class, ['integrationId' => $radarr->id])
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

it('loads the queue on mount', function () {
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

    Livewire::test(ArrSearch::class, ['integrationId' => $this->integration->id])
        ->assertSet('queue.0.title', 'Breaking Bad')
        ->assertSet('queue.0.progress', 75);
});

it('returns empty queue when no integration selected', function () {
    Livewire::test(ArrSearch::class)
        ->assertSet('queue', []);
});

it('returns poll interval of 5 seconds when polling enabled', function () {
    $component = Livewire::test(ArrSearch::class, ['integrationId' => $this->integration->id]);

    expect($component->get('queuePollInterval'))->toBe(5);
});

it('passes guestMode through to the component', function () {
    Livewire::test(ArrSearch::class, ['integrationId' => $this->integration->id, 'guestMode' => true])
        ->assertSet('guestMode', true);
});

it('handles search errors gracefully', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => function () {
            throw new ConnectionException('connection refused');
        },
    ]);

    Livewire::test(ArrSearch::class, ['integrationId' => $this->integration->id])
        ->set('searchTerm', 'breaking')
        ->assertSet('results', []);
});
