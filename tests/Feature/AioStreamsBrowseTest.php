<?php

use App\Livewire\AioStreamsBrowse;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistViewer;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
        'aiostreams_catalogs' => [
            ['id' => 'top', 'type' => 'movie', 'name' => 'Top Movies', 'searchable' => true],
            ['id' => 'top-series', 'type' => 'series', 'name' => 'Top Series', 'searchable' => true],
        ],
        'aiostreams_enable_all_catalogs' => true,
    ]);
});

it('renders without hitting the network until a catalog is requested', function () {
    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->assertOk()
        ->assertSet('searchResults', []);
});

it('filters catalogs by type', function () {
    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->set('typeFilter', 'movie')
        ->assertSet('typeFilter', 'movie');
});

it('searches searchable catalogs and dedupes results', function () {
    Http::fake([
        '*/catalog/movie/top/search=batman.json' => Http::response([
            'metas' => [
                ['id' => 'tt3', 'type' => 'movie', 'name' => 'Batman Begins'],
            ],
        ]),
        '*/catalog/series/top-series/search=batman.json' => Http::response(['metas' => []]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->set('searchTerm', 'batman')
        ->call('search')
        ->assertSet('isSearching', false)
        ->assertSet('searchResults', [
            ['id' => 'tt3', 'type' => 'movie', 'name' => 'Batman Begins'],
        ]);
});

it('clearSearch resets state and re-renders back to the default view', function () {
    Http::fake([
        '*/catalog/movie/top/search=batman.json' => Http::response([
            'metas' => [
                ['id' => 'tt3', 'type' => 'movie', 'name' => 'Batman Begins'],
            ],
        ]),
        '*/catalog/series/top-series/search=batman.json' => Http::response(['metas' => []]),
    ]);

    $component = Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->set('searchTerm', 'batman')
        ->call('search')
        ->assertSee('Batman Begins')
        ->assertSee(__('Clear'));

    $component->call('clearSearch')
        ->assertSet('searchTerm', '')
        ->assertSet('searchResults', [])
        ->assertDontSee('Batman Begins')
        ->assertDontSee(__('Clear'));
});

it('opens the detail slide-over with fetched meta', function () {
    Http::fake([
        '*/meta/movie/tt1.json' => Http::response([
            'meta' => ['id' => 'tt1', 'name' => 'Movie One', 'description' => 'A movie.'],
        ]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tt1')
        ->assertSet('showDetail', true)
        ->assertSet('detailResult.name', 'Movie One');
});

it('normalizes episode video fields regardless of which field names the addon uses', function () {
    Http::fake([
        '*/meta/series/tt2.json' => Http::response([
            'meta' => [
                'id' => 'tt2',
                'name' => 'Rick and Morty',
                'videos' => [
                    [
                        'season' => 1,
                        'episode' => 1,
                        'title' => 'Pilot',
                        'overview' => 'Rick moves in with his daughter\'s family.',
                        'thumbnail' => 'https://x/e1.jpg',
                        'released' => '2013-12-02T00:00:00.000Z',
                    ],
                    // Some addons use "name"/"description"/"poster" instead of title/overview/thumbnail.
                    [
                        'season' => 1,
                        'episode' => 2,
                        'name' => 'Lawnmower Dog',
                        'description' => 'Rick and Morty incept Morty\'s math teacher.',
                        'poster' => 'https://x/e2.jpg',
                        'firstAired' => '2013-12-09T00:00:00.000Z',
                    ],
                ],
            ],
        ]),
    ]);

    $component = Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt2')
        ->assertSet('detailSelectedSeason', 1);

    $episodes = $component->get('detailEpisodesBySeason')[1];

    expect($episodes[0])->toMatchArray([
        'episode' => 1,
        'title' => 'Pilot',
        'overview' => 'Rick moves in with his daughter\'s family.',
        'thumbnail' => 'https://x/e1.jpg',
        'released' => '2013-12-02T00:00:00.000Z',
    ]);

    expect($episodes[1])->toMatchArray([
        'episode' => 2,
        'title' => 'Lawnmower Dog',
        'overview' => 'Rick and Morty incept Morty\'s math teacher.',
        'thumbnail' => 'https://x/e2.jpg',
        'released' => '2013-12-09T00:00:00.000Z',
    ]);
});

it('lazily loads only the selected season\'s episodes, not the whole series at once', function () {
    Http::fake([
        '*/meta/series/tt2.json' => Http::response([
            'meta' => [
                'id' => 'tt2',
                'name' => 'Rick and Morty',
                'videos' => [
                    ['season' => 1, 'episode' => 1, 'title' => 'Pilot'],
                    ['season' => 1, 'episode' => 2, 'title' => 'Lawnmower Dog'],
                    ['season' => 2, 'episode' => 1, 'title' => 'A Rickle in Time'],
                ],
            ],
        ]),
    ]);

    $component = Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt2')
        ->assertSet('detailSeasons', [1, 2])
        ->assertSet('detailSelectedSeason', 1);

    // Only season 1 is present initially — season 2's episodes aren't loaded yet.
    expect($component->get('detailEpisodesBySeason'))->toHaveKey(1)->not->toHaveKey(2);
    expect($component->get('detailEpisodesBySeason')[1])->toHaveCount(2);

    $component->call('selectSeason', 2)
        ->assertSet('detailSelectedSeason', 2);

    // Switching seasons replaces the loaded season entirely — season 1 is dropped,
    // not accumulated alongside season 2.
    expect($component->get('detailEpisodesBySeason'))->toHaveKey(2)->not->toHaveKey(1);
    expect($component->get('detailEpisodesBySeason')[2][0])->toMatchArray([
        'episode' => 1,
        'title' => 'A Rickle in Time',
    ]);

    // The full video list never lands in detailResult itself.
    expect($component->get('detailResult'))->not->toHaveKey('videos');
});

it('shows the source picker instead of auto-playing when only one stream is found', function () {
    // A lone "stream" is often a trailer/error placeholder from the addon, not a real
    // source — always surface the picker so the user confirms rather than auto-playing.
    Http::fake([
        '*/meta/movie/tt1.json' => Http::response([
            'meta' => ['id' => 'tt1', 'name' => 'Movie One'],
        ]),
        '*/stream/movie/tt1.json' => Http::response([
            'streams' => [
                ['url' => 'https://cdn.test/movie-one.mp4', 'name' => '1080p'],
            ],
        ]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tt1')
        ->call('playStream')
        ->assertNotDispatched('openFloatingStream')
        ->assertSet('showDetail', true)
        ->assertSet('streamChoices', function (array $choices) {
            expect($choices)->toHaveCount(1);
            expect($choices[0]['name'])->toBe('1080p');
            expect($choices[0]['format'])->toBe('mp4');
            expect($choices[0]['url'])->toContain('/aiostreams-media/'.$this->integration->id.'/live/')
                ->not->toContain('cdn.test');

            return true;
        });
});

it('resumeWatch opens the source picker instantly, with zero network calls, then lazily loads streams', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => $this->integration->id,
    ]);

    // Playlist creation auto-provisions an admin PlaylistViewer (see AppServiceProvider's
    // $autoCreateAdminViewer) — reuse that one rather than creating a second, duplicate
    // admin=true viewer for the same playlist.
    $viewer = PlaylistViewer::where('viewerable_type', $playlist->getMorphClass())
        ->where('viewerable_id', $playlist->id)
        ->where('is_admin', true)
        ->firstOrFail();

    $progress = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt2',
        'aio_integration_id' => $this->integration->id,
        'season_number' => 1,
        'episode_number' => 3,
        'title' => 'Rick and Morty',
        'episode_title' => 'Anatomy Park',
        'thumbnail_url' => 'https://x/rm-poster.jpg',
        'position_seconds' => 120,
        'duration_seconds' => 1200,
        'watch_count' => 1,
        'last_watched_at' => now(),
    ]);

    // No */meta/series/tt2.json fake is registered — combined with
    // Http::preventStrayRequests() in beforeEach(), this proves resumeWatch()
    // itself makes zero network calls, since it opens the modal using only the
    // progress row's own saved fields.
    $component = Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('resumeWatch', $progress->id)
        ->assertNotDispatched('openFloatingStream')
        ->assertSet('showDetail', true)
        ->assertSet('detailResult.name', 'Rick and Morty')
        ->assertSet('detailResult.poster', 'https://x/rm-poster.jpg')
        ->assertSet('detailEpisodesBySeason', [])
        ->assertSet('streamsLoading', true)
        ->assertSet('streamChoices', [])
        ->assertSee(__('S:s E:e', ['s' => 1, 'e' => 3]))
        ->assertSee('Anatomy Park');

    Http::fake([
        '*/stream/series/tt2:1:3.json' => Http::response([
            'streams' => [
                ['url' => 'https://cdn.test/rm-s1e3.mp4', 'name' => '1080p'],
            ],
        ]),
    ]);

    $component->call('loadResumeStreams')
        ->assertSet('streamsLoading', false)
        ->assertSet('streamChoices', function (array $choices) {
            expect($choices)->toHaveCount(1);
            expect($choices[0]['name'])->toBe('1080p');
            expect($choices[0]['format'])->toBe('mp4');
            expect($choices[0]['url'])->toContain('/aiostreams-media/'.$this->integration->id.'/live/')
                ->not->toContain('cdn.test');

            return true;
        })
        ->assertSet('pendingWatchContext.season_number', 1)
        ->assertSet('pendingWatchContext.episode_number', 3)
        ->assertSet('pendingWatchContext.episode_title', 'Anatomy Park');

    // Regression: playStream() (called by loadResumeStreams() above) used to
    // re-mount the already-open 'showDetail' action, and mountAction() pushes onto
    // the stack unconditionally rather than checking if it's already mounted. That
    // duplicate mount — reached via wire:init, with no real click to anchor Filament's
    // focus-trap to — was what caused the underlying page to jump-scroll to the
    // bottom, force-loading every lazy catalog row at once.
    expect($component->get('mountedActions'))->toHaveCount(1);

    // The (potentially large) per-episode video list is never fetched at all for
    // the resume flow — detailResult stays built purely from the progress row.
    expect($component->get('detailResult'))->not->toHaveKey('videos');
});

it('retries a failed resume stream lookup, preserving season/episode', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => $this->integration->id,
    ]);

    $viewer = PlaylistViewer::where('viewerable_type', $playlist->getMorphClass())
        ->where('viewerable_id', $playlist->id)
        ->where('is_admin', true)
        ->firstOrFail();

    $progress = ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt2',
        'aio_integration_id' => $this->integration->id,
        'season_number' => 1,
        'episode_number' => 3,
        'title' => 'Rick and Morty',
        'episode_title' => 'Anatomy Park',
        'position_seconds' => 120,
        'duration_seconds' => 1200,
        'watch_count' => 1,
        'last_watched_at' => now(),
    ]);

    Http::fake([
        '*/stream/series/tt2:1:3.json' => Http::sequence()
            ->push(null, 500)
            ->push([
                'streams' => [
                    ['url' => 'https://cdn.test/rm-s1e3.mp4', 'name' => '1080p'],
                ],
            ]),
    ]);

    $component = Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('resumeWatch', $progress->id)
        ->call('loadResumeStreams')
        ->assertSet('streamsLoading', false)
        ->assertSet('streamsFailed', true)
        ->assertSet('streamChoices', []);

    $component->call('retryLoadStreams')
        ->assertSet('streamsFailed', false)
        ->assertSet('streamChoices', function (array $choices) {
            expect($choices)->toHaveCount(1);
            expect($choices[0]['name'])->toBe('1080p');
            expect($choices[0]['format'])->toBe('mp4');
            expect($choices[0]['url'])->toContain('/aiostreams-media/'.$this->integration->id.'/live/')
                ->not->toContain('cdn.test');

            return true;
        });

    expect($component->get('mountedActions'))->toHaveCount(1);
});

it('shows a source picker when multiple streams are found', function () {
    Http::fake([
        '*/meta/movie/tt1.json' => Http::response([
            'meta' => ['id' => 'tt1', 'name' => 'Movie One'],
        ]),
        '*/stream/movie/tt1.json' => Http::response([
            'streams' => [
                ['url' => 'https://cdn.test/a.mp4', 'name' => '1080p'],
                ['url' => 'https://cdn.test/b.mp4', 'name' => '720p'],
            ],
        ]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tt1')
        ->call('playStream')
        ->assertNotDispatched('openFloatingStream')
        ->assertSet('streamChoices', function (array $choices) {
            expect($choices)->toHaveCount(2);
            expect($choices[0]['name'])->toBe('1080p');
            expect($choices[1]['name'])->toBe('720p');
            expect($choices[0]['format'])->toBe('mp4');
            expect($choices[1]['format'])->toBe('mp4');
            expect($choices[0]['url'])->toContain('/aiostreams-media/'.$this->integration->id.'/live/')
                ->not->toContain('cdn.test');
            expect($choices[1]['url'])->toContain('/aiostreams-media/'.$this->integration->id.'/live/')
                ->not->toContain('cdn.test');

            return true;
        });
});
