<?php

/**
 * Tests for DvrMetadataEnricherService
 *
 * Covers the three enrichment passes:
 *
 *   Pass 1 — Show-level (TMDB → TVMaze): title search, caching, miss-caching
 *   Pass 2 — Season/episode backfill from epg_programme_data.episode_num
 *   Pass 3 — Episode-level TMDB/TVMaze fetch when season + episode are known
 */

use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use App\Services\DvrMetadataEnricherService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $overrides
 * @param  array<string, mixed>  $settingOverrides
 */
function makeEnricherRecording(array $overrides = [], array $settingOverrides = []): DvrRecording
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create(array_merge([
        'enable_metadata_enrichment' => true,
        'tmdb_api_key' => 'test-tmdb-key',
    ], $settingOverrides));

    return DvrRecording::factory()
        ->completed()
        ->for($setting, 'dvrSetting')
        ->for($user)
        ->create(array_merge([
            'title' => 'Test Show',
            'season' => null,
            'episode' => null,
            'metadata' => null,
            'epg_programme_data' => null,
        ], $overrides));
}

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();

    // Bind a mock GeneralSettings with no global tmdb key so per-setting key is used.
    $this->mock(GeneralSettings::class, function ($mock) {
        $mock->shouldReceive('getAttribute')->with('tmdb_api_key')->andReturn(null);
        $mock->tmdb_api_key = null;
    });

    $this->enricher = app(DvrMetadataEnricherService::class);
});

// ── Pass 1: show-level TMDB ───────────────────────────────────────────────────

it('stores TMDB show data on recording.metadata.tmdb', function () {
    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [[
                'id' => 1396,
                'name' => 'Breaking Bad',
                'overview' => 'A chemistry teacher turns to crime.',
                'poster_path' => '/poster.jpg',
                'backdrop_path' => '/backdrop.jpg',
                'first_air_date' => '2008-01-20',
            ]],
        ]),
    ]);

    $recording = makeEnricherRecording(['title' => 'Breaking Bad', 'season' => null, 'episode' => null]);

    $this->enricher->enrich($recording);

    $recording->refresh();
    expect($recording->metadata['tmdb']['id'])->toBe(1396)
        ->and($recording->metadata['tmdb']['type'])->toBe('tv')
        ->and($recording->metadata['tmdb']['name'])->toBe('Breaking Bad');
});

it('falls back to TVMaze when TMDB returns no results', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([
            'id' => 169,
            'name' => 'Breaking Bad',
            'summary' => '<p>A chemistry teacher.</p>',
            'image' => ['original' => 'https://tvmaze.com/poster.jpg'],
            'premiered' => '2008-01-20',
            'genres' => ['Drama', 'Crime'],
            'network' => ['name' => 'AMC'],
        ]),
    ]);

    $recording = makeEnricherRecording(['title' => 'Breaking Bad']);

    $this->enricher->enrich($recording);

    $recording->refresh();
    expect($recording->metadata['tvmaze']['id'])->toBe(169)
        ->and($recording->metadata['tvmaze']['name'])->toBe('Breaking Bad')
        ->and(isset($recording->metadata['tmdb']))->toBeFalse();
});

it('caches a TMDB miss so the API is not called again for the same title', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording1 = makeEnricherRecording(['title' => 'No Such Show Ever']);
    $recording2 = makeEnricherRecording(['title' => 'No Such Show Ever']);

    $this->enricher->enrich($recording1);
    $this->enricher->enrich($recording2);

    // recording1 hits TMDB TV + TMDB movie + TVMaze = 3 requests.
    // recording2 finds both cache keys set to false → zero additional requests.
    Http::assertSentCount(3);
});

// ── Pass 2: season/episode backfill ──────────────────────────────────────────

it('backfills season and episode from epg_programme_data.episode_num (SxxExx format)', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording([
        'season' => null,
        'episode' => null,
        'epg_programme_data' => ['episode_num' => 'S02E05'],
    ]);

    $this->enricher->enrich($recording);

    $recording->refresh();
    expect($recording->season)->toBe(2)
        ->and($recording->episode)->toBe(5);
});

it('backfills season and episode from epg_programme_data.episode_num (xmltv_ns dot format)', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording([
        'season' => null,
        'episode' => null,
        'epg_programme_data' => ['episode_num' => '2.4.'],  // 0-indexed → season 3, episode 5
    ]);

    $this->enricher->enrich($recording);

    $recording->refresh();
    expect($recording->season)->toBe(3)
        ->and($recording->episode)->toBe(5);
});

it('does not overwrite season/episode that are already set', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording([
        'season' => 1,
        'episode' => 3,
        'epg_programme_data' => ['episode_num' => 'S02E05'],
    ]);

    $this->enricher->enrich($recording);

    $recording->refresh();
    // Should remain 1 / 3 — not overwritten with S02E05
    expect($recording->season)->toBe(1)
        ->and($recording->episode)->toBe(3);
});

it('skips backfill when epg_programme_data has no episode_num', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording([
        'season' => null,
        'episode' => null,
        'epg_programme_data' => ['epg_channel_id' => 'test.ch'],
    ]);

    $this->enricher->enrich($recording);

    $recording->refresh();
    expect($recording->season)->toBeNull()
        ->and($recording->episode)->toBeNull();
});

// ── Pass 3: episode-level TMDB ───────────────────────────────────────────────

it('fetches episode-level TMDB data after show match and stores it under metadata.tmdb_episode', function () {
    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [[
                'id' => 1396,
                'name' => 'Breaking Bad',
                'overview' => 'Show overview.',
                'poster_path' => '/poster.jpg',
                'backdrop_path' => '/backdrop.jpg',
                'first_air_date' => '2008-01-20',
            ]],
        ]),
        '*/tv/1396/season/1/episode/3*' => Http::response([
            'id' => 62085,
            'name' => '...And the Bag\'s in the River',
            'overview' => 'Episode overview.',
            'still_path' => '/still.jpg',
            'air_date' => '2008-02-10',
            'episode_number' => 3,
            'season_number' => 1,
        ]),
    ]);

    $recording = makeEnricherRecording([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 3,
    ]);

    $this->enricher->enrich($recording);

    $recording->refresh();
    expect($recording->metadata['tmdb_episode']['id'])->toBe(62085)
        ->and($recording->metadata['tmdb_episode']['name'])->toBe('...And the Bag\'s in the River')
        ->and($recording->metadata['tmdb_episode']['still_url'])->toContain('/still.jpg')
        ->and($recording->metadata['tmdb_episode']['air_date'])->toBe('2008-02-10');
});

it('fetches episode-level TVMaze data when no TMDB key is configured', function () {
    Http::fake([
        '*/singlesearch/shows*' => Http::response([
            'id' => 169,
            'name' => 'Breaking Bad',
            'summary' => '<p>Show.</p>',
            'image' => ['original' => 'https://tvmaze.com/poster.jpg'],
            'premiered' => '2008-01-20',
            'genres' => [],
            'network' => ['name' => 'AMC'],
        ]),
        '*/shows/169/episodebynumber*' => Http::response([
            'id' => 9999,
            'name' => 'Pilot',
            'summary' => '<p>Episode summary.</p>',
            'image' => ['original' => 'https://tvmaze.com/ep.jpg', 'medium' => null],
            'airdate' => '2008-01-20',
        ]),
    ]);

    // No TMDB key at all — should fall through to TVMaze for both passes
    $recording = makeEnricherRecording([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 1,
    ], ['tmdb_api_key' => null]);

    $this->enricher->enrich($recording);

    $recording->refresh();
    expect($recording->metadata['tvmaze']['id'])->toBe(169)
        ->and($recording->metadata['tvmaze_episode']['id'])->toBe(9999)
        ->and($recording->metadata['tvmaze_episode']['name'])->toBe('Pilot')
        ->and($recording->metadata['tvmaze_episode']['summary'])->toBe('Episode summary.')
        ->and($recording->metadata['tvmaze_episode']['image'])->toBe('https://tvmaze.com/ep.jpg');
});

it('skips episode-level fetch when season and episode are both null after backfill', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording([
        'season' => null,
        'episode' => null,
        'epg_programme_data' => null,
    ]);

    $this->enricher->enrich($recording);

    // Pass 3 should be entirely skipped — no season/episode endpoint called.
    // Only the three show-level lookups (TMDB TV, TMDB movie, TVMaze 404) run.
    Http::assertSentCount(3);
    $recording->refresh();
    expect($recording->metadata)->toBeNull();
});

it('full pipeline: backfill then episode fetch when recording has episode_num and TMDB show match', function () {
    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [[
                'id' => 1396,
                'name' => 'Breaking Bad',
                'overview' => 'Show overview.',
                'poster_path' => '/poster.jpg',
                'backdrop_path' => '/backdrop.jpg',
                'first_air_date' => '2008-01-20',
            ]],
        ]),
        '*/tv/1396/season/2/episode/5*' => Http::response([
            'id' => 62090,
            'name' => 'Breakage',
            'overview' => 'Episode 5 overview.',
            'still_path' => '/still5.jpg',
            'air_date' => '2009-03-08',
            'episode_number' => 5,
            'season_number' => 2,
        ]),
    ]);

    // Recording created before parseEpisodeNumbers fix: season/episode null, but episode_num stored
    $recording = makeEnricherRecording([
        'title' => 'Breaking Bad',
        'season' => null,
        'episode' => null,
        'epg_programme_data' => ['episode_num' => 'S02E05'],
    ]);

    $this->enricher->enrich($recording);

    $recording->refresh();

    // Pass 2: backfilled
    expect($recording->season)->toBe(2)
        ->and($recording->episode)->toBe(5);

    // Pass 3: episode-level data fetched
    expect($recording->metadata['tmdb']['id'])->toBe(1396)
        ->and($recording->metadata['tmdb_episode']['id'])->toBe(62090)
        ->and($recording->metadata['tmdb_episode']['name'])->toBe('Breakage');
});
