<?php

use App\Jobs\FetchTmdbIds;
use App\Livewire\AioStreamsBrowse;
use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    $settings->tmdb_confidence_threshold = 80;
    $settings->tmdb_auto_create_groups = false;
    $settings->aiostreams_rate_limit = 20;
    app()->instance(GeneralSettings::class, $settings);
    Cache::flush();

    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
        'aiostreams_tmdb_enrich' => true,
    ]);
});

function fakeAioLibraryMeta(): void
{
    Http::fake([
        '*/meta/movie/tt1.json' => Http::response(['meta' => [
            'id' => 'tt1', 'name' => 'Test Movie', 'description' => 'Stremio plot.', 'poster' => 'https://x/poster.jpg',
        ]]),
        '*/meta/series/tt2.json' => Http::response(['meta' => [
            'id' => 'tt2', 'name' => 'Test Series', 'description' => 'Stremio plot.', 'poster' => 'https://x/poster.jpg',
            'videos' => [['id' => 'tt2:1:1', 'season' => 1, 'episode' => 1, 'title' => 'Pilot', 'released' => '2020-01-01']],
        ]]),
        // TMDB lookups the browse view's own meta enrichment makes - irrelevant here.
        'api.themoviedb.org/*' => Http::response([], 404),
    ]);
}

it('queues TMDB backfill enrichment for a movie added to the library', function () {
    Bus::fake();
    fakeAioLibraryMeta();

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tt1')
        ->call('addMovieToLibrary');

    $channel = Channel::where('aio_item_id', 'tt1')->firstOrFail();

    Bus::assertDispatched(FetchTmdbIds::class, fn (FetchTmdbIds $job) => $job->vodChannelIds === [$channel->id]
        && $job->backfillEnrichment
        && ! $job->sendCompletionNotification);
});

it('queues TMDB backfill enrichment for a series and again when episodes are added', function () {
    Bus::fake();
    fakeAioLibraryMeta();

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt2')
        ->call('addSeriesToLibrary')
        ->call('addEpisodeToLibrary', 1, 1);

    $series = Series::where('aio_item_id', 'tt2')->firstOrFail();

    Bus::assertDispatchedTimes(FetchTmdbIds::class, 2);
    Bus::assertDispatched(FetchTmdbIds::class, fn (FetchTmdbIds $job) => $job->seriesIds === [$series->id] && $job->backfillEnrichment);
});

it('does not queue TMDB enrichment when the integration opts out of it', function () {
    Bus::fake();
    fakeAioLibraryMeta();
    $this->integration->update(['aiostreams_tmdb_enrich' => false]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tt1')
        ->call('addMovieToLibrary');

    Bus::assertNotDispatched(FetchTmdbIds::class);
});

it('fills cast and clearlogo on a Stremio-sourced movie only when backfilling', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'is_custom' => true,
        'title' => 'The Matrix',
        'tmdb_id' => 603,
        'aio_integration_id' => $this->integration->id,
        'aio_item_id' => 'tt0133093',
        // What add-to-library stores from the Stremio meta: plot + poster, which
        // the regular (non-backfill) job treats as "already enriched".
        'info' => ['plot' => 'Stremio plot.', 'cover_big' => 'https://x/poster.jpg', 'movie_image' => 'https://x/poster.jpg'],
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
            'overview' => 'A computer hacker learns about the true nature of reality.',
            'poster_path' => '/matrix.jpg',
            'credits' => ['cast' => [
                ['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'profile_path' => '/keanu.jpg'],
            ]],
            'images' => ['logos' => [
                ['file_path' => '/matrix-logo.png', 'iso_639_1' => 'en', 'vote_average' => 5.0],
            ]],
        ]),
        'api.themoviedb.org/*' => Http::response([], 404),
    ]);

    // The regular job sees plot + poster and skips the full details fetch.
    (new FetchTmdbIds(vodChannelIds: [$channel->id], sendCompletionNotification: false))->handle(app(TmdbService::class));

    expect($channel->fresh()->info)->not->toHaveKey('cast_list')
        ->and($channel->fresh()->info)->not->toHaveKey('clearlogo');

    (new FetchTmdbIds(vodChannelIds: [$channel->id], sendCompletionNotification: false, backfillEnrichment: true))->handle(app(TmdbService::class));

    $info = $channel->fresh()->info;
    expect($info['cast_list'][0]['name'] ?? null)->toBe('Keanu Reeves')
        ->and($info['clearlogo'] ?? '')->toContain('/matrix-logo.png');
});
