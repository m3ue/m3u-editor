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
use Illuminate\Support\Carbon;
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

// ── Fix #6: transient API failures must not poison the negative cache ────────

it('does not cache a negative result when TMDB returns a transient 5xx failure', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['error' => 'rate limited'], 429),
        '*/search/movie*' => Http::response(['error' => 'rate limited'], 429),
        '*/singlesearch/shows*' => Http::response(['error' => 'down'], 503),
    ]);

    $recording = makeEnricherRecording(['title' => 'Some Show']);

    $this->enricher->enrich($recording);

    // No cache key should have been written for this title.
    $tmdbKey = 'dvr.tmdb.'.md5('Some Show');
    $tvmazeKey = 'dvr.tvmaze.'.md5('Some Show');

    expect(Cache::has($tmdbKey))->toBeFalse()
        ->and(Cache::has($tvmazeKey))->toBeFalse();
});

it('retries the API on a subsequent call after a transient TMDB failure', function () {
    // First call: TV search 500 → transient → no cache.
    // Second call: TV search 200 with a real result → match, cache positive.
    Http::fakeSequence('*/search/tv*')
        ->push(['error' => 'server'], 500)
        ->push([
            'results' => [[
                'id' => 1396,
                'name' => 'Breaking Bad',
                'overview' => 'A chemistry teacher.',
                'poster_path' => '/p.jpg',
                'backdrop_path' => '/b.jpg',
                'first_air_date' => '2008-01-20',
            ]],
        ]);

    // Movie / TVMaze fallbacks must still be matched on call 1 (because TMDB
    // TV failed transiently, code falls through to movie + TVMaze).  Both
    // miss confirmedly so they would be cached — but on call 2 the TMDB TV
    // search succeeds before either fallback runs.
    Http::fake([
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording1 = makeEnricherRecording(['title' => 'Breaking Bad']);
    $recording2 = makeEnricherRecording(['title' => 'Breaking Bad']);

    $this->enricher->enrich($recording1);
    $recording1->refresh();

    // Call 1: no TMDB match (transient TV failure, movie miss, TVMaze 404).
    expect(isset($recording1->metadata['tmdb']))->toBeFalse();

    $this->enricher->enrich($recording2);
    $recording2->refresh();

    // Call 2: TV search retried and succeeded.
    expect($recording2->metadata['tmdb']['id'])->toBe(1396);
});

it('caches a confirmed TVMaze 404 miss separately from transient failures', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording(['title' => 'Definitely Not A Show']);

    $this->enricher->enrich($recording);

    $tvmazeKey = 'dvr.tvmaze.'.md5('Definitely Not A Show');

    // 404 from TVMaze IS a confirmed miss → should be cached as false.
    expect(Cache::has($tvmazeKey))->toBeTrue()
        ->and(Cache::get($tvmazeKey))->toBeFalse();
});

// ── Fix #5: TMDB result ranking — exact name beats popularity ─────────────────

it('prefers an exact-name TMDB match over a more popular fuzzy match', function () {
    // Recording is for "The Office" (US).  TMDB returns the UK version first
    // (because it's slightly more popular for the bare query "The Office"),
    // then the US version.  Old code took [0] blindly; new ranker picks
    // exact-name first.
    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 2001,
                    'name' => 'The Office (UK)',
                    'popularity' => 95.0,
                    'overview' => 'UK version.',
                    'first_air_date' => '2001-07-09',
                ],
                [
                    'id' => 2316,
                    'name' => 'The Office',
                    'popularity' => 90.0,
                    'overview' => 'US version.',
                    'first_air_date' => '2005-03-24',
                ],
            ],
        ]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording(['title' => 'The Office']);

    $this->enricher->enrich($recording);
    $recording->refresh();

    // Exact match wins despite being lower-popularity / second in the list.
    expect($recording->metadata['tmdb']['id'])->toBe(2316)
        ->and($recording->metadata['tmdb']['name'])->toBe('The Office');
});

it('falls back to highest popularity when no exact name match exists', function () {
    // No result matches "Severance" exactly (case-insensitive trim).  Among
    // fuzzy matches, the highest popularity wins.
    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 1,
                    'name' => 'Severance Pay',
                    'popularity' => 5.0,
                    'first_air_date' => '1999-01-01',
                ],
                [
                    'id' => 2,
                    'name' => 'Severance: The Aftermath',
                    'popularity' => 50.0,
                    'first_air_date' => '2024-01-01',
                ],
                [
                    'id' => 3,
                    'name' => 'A Severance Story',
                    'popularity' => 12.0,
                    'first_air_date' => '2010-01-01',
                ],
            ],
        ]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording(['title' => 'Severance']);

    $this->enricher->enrich($recording);
    $recording->refresh();

    expect($recording->metadata['tmdb']['id'])->toBe(2);
});

it('preserves TMDB index order when popularity ties and no exact match exists', function () {
    // No exact match; all popularities equal → first result wins (index tiebreaker).
    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [
                ['id' => 10, 'name' => 'Foo Bar Alpha', 'popularity' => 1.0],
                ['id' => 11, 'name' => 'Foo Bar Beta', 'popularity' => 1.0],
            ],
        ]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording(['title' => 'Foo Bar']);

    $this->enricher->enrich($recording);
    $recording->refresh();

    expect($recording->metadata['tmdb']['id'])->toBe(10);
});

it('matches case-insensitively when ranking TMDB results', function () {
    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [
                ['id' => 100, 'name' => 'House of Cards (Original)', 'popularity' => 80.0],
                ['id' => 101, 'name' => 'house of cards', 'popularity' => 30.0],
            ],
        ]),
        '*/search/movie*' => Http::response(['results' => []]),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording(['title' => 'House of Cards']);

    $this->enricher->enrich($recording);
    $recording->refresh();

    // Lowercase exact match (id 101) beats higher-popularity inexact match (100).
    expect($recording->metadata['tmdb']['id'])->toBe(101);
});

// ── Pass 2b: description-prefix backfill ─────────────────────────────────────

it('backfills season/episode/subtitle from description prefix', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []], 200),
        '*/search/movie*' => Http::response(['results' => []], 200),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording([
        'title' => 'Halt and Catch Fire',
        'description' => "S01 E06 Landfall\nAfter a breakthrough, Cameron is at odds with Gordon.",
        'subtitle' => null,
    ]);

    $this->enricher->enrich($recording);
    $recording->refresh();

    expect($recording->season)->toBe(1)
        ->and($recording->episode)->toBe(6)
        ->and($recording->subtitle)->toBe('Landfall');
});

it('promotes extracted episode title to subtitle when subtitle is empty', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []], 200),
        '*/search/movie*' => Http::response(['results' => []], 200),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording([
        'title' => 'Some Show',
        'description' => "1x03 - The One Where They Argue\nPlot stuff...",
        'subtitle' => null,
    ]);

    $this->enricher->enrich($recording);
    $recording->refresh();

    expect($recording->season)->toBe(1)
        ->and($recording->episode)->toBe(3)
        ->and($recording->subtitle)->toBe('The One Where They Argue');
});

it('does not overwrite existing subtitle with description title', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []], 200),
        '*/search/movie*' => Http::response(['results' => []], 200),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording([
        'title' => 'Some Show',
        'description' => "S03E01 Pilot\nSynopsis...",
        'subtitle' => 'Existing Subtitle',
    ]);

    $this->enricher->enrich($recording);
    $recording->refresh();

    expect($recording->season)->toBe(3)
        ->and($recording->episode)->toBe(1)
        ->and($recording->subtitle)->toBe('Existing Subtitle');
});

it('strips the S/E prefix from description after backfill', function () {
    Http::fake([
        '*/search/tv*' => Http::response(['results' => []], 200),
        '*/search/movie*' => Http::response(['results' => []], 200),
        '*/singlesearch/shows*' => Http::response([], 404),
    ]);

    $recording = makeEnricherRecording([
        'title' => 'Series Title',
        'description' => 'S05E12: Season Finale. The big confrontation happens at dawn.',
    ]);

    $this->enricher->enrich($recording);
    $recording->refresh();

    expect($recording->description)
        ->not->toContain('S05E12')
        ->not->toContain('Season Finale')
        ->toContain('The big confrontation happens at dawn.');
});

// ── Pass 2.5: air-date episode resolution ────────────────────────────────────

it('resolves season/episode via TMDB air-date when EPG omits episode_num', function () {
    $tmdbShowId = 59659;

    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [[
                'id' => $tmdbShowId,
                'name' => 'Halt and Catch Fire',
                'popularity' => 20,
            ]],
        ]),
        '*/search/movie*' => Http::response(['results' => []]),
        "*/tv/{$tmdbShowId}?*" => Http::response([
            'seasons' => [
                ['season_number' => 1, 'episode_count' => 10],
            ],
        ]),
        "*/tv/{$tmdbShowId}/season/1?*" => Http::response([
            'episodes' => [
                [
                    'episode_number' => 1, 'name' => 'I/O',
                    'air_date' => '2014-06-01', 'overview' => 'Pilot.',
                ],
                [
                    'episode_number' => 6, 'name' => 'Landfall',
                    'air_date' => '2014-07-06', 'overview' => 'Tensions rise.',
                ],
            ],
        ]),
    ]);

    $recording = makeEnricherRecording([
        'title' => 'Halt and Catch Fire',
        'season' => null,
        'episode' => null,
        'programme_start' => Carbon::parse('2014-07-06 21:00:00'),
        'description' => 'After a breakthrough, Cameron is at odds with Gordon.',
    ]);

    $this->enricher->enrich($recording);
    $recording->refresh();

    expect($recording->season)->toBe(1)
        ->and($recording->episode)->toBe(6);
});

it('falls through to MMDD when air-date matches multiple episodes', function () {
    $tmdbShowId = 100;

    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [[
                'id' => $tmdbShowId,
                'name' => 'Ambiguous Show',
                'popularity' => 10,
            ]],
        ]),
        '*/search/movie*' => Http::response(['results' => []]),
        "*/tv/{$tmdbShowId}?*" => Http::response([
            'seasons' => [
                ['season_number' => 1, 'episode_count' => 10],
                ['season_number' => 2, 'episode_count' => 10],
            ],
        ]),
        "*/tv/{$tmdbShowId}/season/1?*" => Http::response([
            'episodes' => [
                ['episode_number' => 5, 'air_date' => '2025-06-01'],
            ],
        ]),
        "*/tv/{$tmdbShowId}/season/2?*" => Http::response([
            'episodes' => [
                ['episode_number' => 3, 'air_date' => '2025-06-01'],
            ],
        ]),
    ]);

    $recording = makeEnricherRecording([
        'title' => 'Ambiguous Show',
        'season' => null,
        'episode' => null,
        'programme_start' => Carbon::parse('2025-06-01 20:00:00'),
    ]);

    $this->enricher->enrich($recording);
    $recording->refresh();

    // Still null — multiple matches, falls through to MMDD at integration time.
    expect($recording->season)->toBeNull()
        ->and($recording->episode)->toBeNull();
});

it('does not run air-date resolution when season is already set', function () {
    Http::fake([
        '*/search/tv*' => Http::response([
            'results' => [[
                'id' => 59659,
                'name' => 'Halt and Catch Fire',
                'popularity' => 20,
            ]],
        ]),
        '*/search/movie*' => Http::response(['results' => []]),
    ]);
    // No /tv/{id} fake — if it tries to hit it, the test would fail.

    $recording = makeEnricherRecording([
        'title' => 'Halt and Catch Fire',
        'season' => 1,
        'episode' => 3,
        'programme_start' => Carbon::parse('2014-07-06 21:00:00'),
    ]);

    $this->enricher->enrich($recording);
    $recording->refresh();

    // Season/episode unchanged — air-date pass was skipped.
    expect($recording->season)->toBe(1)
        ->and($recording->episode)->toBe(3);
});
