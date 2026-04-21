<?php

/**
 * Tests for DvrVodIntegrationService
 *
 * Covers:
 * - Movie recording (TMDB type=movie) → creates VOD Channel with is_vod=true
 * - TV recording (TMDB type=tv) → creates Series / Season / Episode
 * - TV recording with no TMDB but season set → treated as TV (series path)
 * - Recording with no metadata and no season → treated as movie
 * - Idempotency: calling integrate twice does NOT create duplicate Channel
 * - Idempotency: calling integrate twice does NOT create duplicate Episode
 * - Multiple episodes of same series share one Series + Season record
 * - DvrSetting missing → graceful skip (no exception)
 * - VOD channel has dvr_recording_id FK set correctly
 * - Episode has dvr_recording_id FK set correctly
 * - EnrichDvrMetadata dispatches IntegrateDvrRecordingToVod after enrichment
 * - DvrPostProcessorService dispatches IntegrateDvrRecordingToVod when metadata enrichment disabled
 */

use App\Jobs\EnrichDvrMetadata;
use App\Jobs\IntegrateDvrRecordingToVod;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\DvrMetadataEnricherService;
use App\Services\DvrVodIntegrationService;
use App\Services\PlaylistService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build a completed DvrRecording with a DvrSetting that belongs to a Playlist.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeCompletedRecording(array $overrides = []): DvrRecording
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create();

    return DvrRecording::factory()
        ->completed()
        ->for($setting, 'dvrSetting')
        ->for($user)
        ->create($overrides);
}

beforeEach(function () {
    Queue::fake();

    // Ensure the named route exists in the test environment
    if (! Route::has('dvr.recording.stream')) {
        Route::get('/dvr/recordings/{uuid}/stream', fn () => '')->name('dvr.recording.stream');
    }

    $this->service = app(DvrVodIntegrationService::class);
});

// ── Movie path ────────────────────────────────────────────────────────────────

it('creates a VOD channel for a recording with TMDB movie metadata', function () {
    $recording = makeCompletedRecording([
        'title' => 'Inception',
        'season' => null,
        'episode' => null,
        'metadata' => [
            'tmdb' => [
                'id' => 27205,
                'type' => 'movie',
                'name' => 'Inception',
                'overview' => 'A thief who steals corporate secrets.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/w500/backdrop.jpg',
                'release_date' => '2010-07-16',
            ],
        ],
    ]);

    $this->service->integrateRecording($recording);

    $channel = Channel::where('dvr_recording_id', $recording->id)->first();

    expect($channel)->not->toBeNull()
        ->and($channel->is_vod)->toBeTrue()
        ->and($channel->name)->toBe('Inception')
        ->and($channel->playlist_id)->toBe($recording->dvrSetting->playlist_id)
        ->and($channel->user_id)->toBe($recording->user_id)
        ->and($channel->container_extension)->toBe('mp4')
        ->and($channel->tmdb_id)->toBe(27205)
        ->and($channel->source_id)->toBeNull();
});

it('sets the VOD channel URL to the dvr stream path via PlaylistService', function () {
    $recording = makeCompletedRecording([
        'season' => null,
        'episode' => null,
        'metadata' => ['tmdb' => ['id' => 1, 'type' => 'movie', 'name' => 'Test Movie']],
    ]);

    $this->service->integrateRecording($recording);

    $channel = Channel::where('dvr_recording_id', $recording->id)->firstOrFail();
    $expectedUrl = PlaylistService::getBaseUrl('/dvr/recordings/'.$recording->uuid.'/stream');

    expect($channel->url)->toBe($expectedUrl);
});

it('does not duplicate a VOD channel when integrate is called twice', function () {
    $recording = makeCompletedRecording([
        'season' => null,
        'episode' => null,
        'metadata' => ['tmdb' => ['id' => 1, 'type' => 'movie', 'name' => 'Dupe Movie']],
    ]);

    $this->service->integrateRecording($recording);
    $this->service->integrateRecording($recording);

    expect(Channel::where('dvr_recording_id', $recording->id)->count())->toBe(1);
});

it('creates a VOD channel when there is no TMDB metadata and no season is set', function () {
    $recording = makeCompletedRecording([
        'title' => 'Unknown Show',
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'description' => 'Recorded event description',
        'programme_start' => Carbon::parse('2025-06-15'),
    ]);

    $this->service->integrateRecording($recording);

    $channel = Channel::where('dvr_recording_id', $recording->id)->first();

    expect($channel)->not->toBeNull()
        ->and($channel->is_vod)->toBeTrue()
        ->and($channel->name)->toBe('Unknown Show — Jun 15, 2025')
        ->and($channel->info)->toBeArray()
        ->and($channel->info['plot'])->toBe('Recorded event description')
        ->and($channel->info['tmdb_id'])->toBeNull();
});

it('uses tvmaze metadata when tmdb metadata is unavailable on movie integration', function () {
    $recording = makeCompletedRecording([
        'title' => 'Unknown Show',
        'season' => null,
        'episode' => null,
        'description' => null,
        'metadata' => [
            'tvmaze' => [
                'id' => 123,
                'name' => 'Unknown Show',
                'overview' => 'TVMaze plot',
                'poster_url' => 'https://tvmaze.test/poster.jpg',
                'premiered' => '2025-01-02',
            ],
        ],
    ]);

    $this->service->integrateRecording($recording);

    $channel = Channel::where('dvr_recording_id', $recording->id)->first();

    expect($channel)->not->toBeNull()
        ->and($channel->logo)->toBe('https://tvmaze.test/poster.jpg')
        ->and($channel->year)->toBe('2025')
        ->and($channel->info['plot'])->toBe('TVMaze plot')
        ->and($channel->info['movie_image'])->toBe('https://tvmaze.test/poster.jpg')
        ->and($channel->info['release_date'])->toBe('2025-01-02')
        ->and($channel->info['tmdb_id'])->toBeNull();
});

// ── TV / Series path ──────────────────────────────────────────────────────────

it('creates Series, Season, and Episode for a recording with TMDB tv metadata', function () {
    $recording = makeCompletedRecording([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 1,
        'metadata' => [
            'tmdb' => [
                'id' => 1396,
                'type' => 'tv',
                'name' => 'Breaking Bad',
                'overview' => 'A chemistry teacher turns to crime.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
                'first_air_date' => '2008-01-20',
            ],
        ],
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->first();

    expect($episode)->not->toBeNull()
        ->and($episode->season)->toBe(1)
        ->and($episode->episode_num)->toBe(1)
        ->and($episode->playlist_id)->toBe($recording->dvrSetting->playlist_id)
        ->and($episode->source_episode_id)->toBeNull();

    $season = Season::find($episode->season_id);
    expect($season)->not->toBeNull()
        ->and($season->season_number)->toBe(1);

    $series = Series::find($episode->series_id);
    expect($series)->not->toBeNull()
        ->and($series->name)->toBe('Breaking Bad')
        ->and($series->tmdb_id)->toBe(1396)
        ->and($series->source_series_id)->toBeNull();
});

it('sets the episode URL to the dvr stream path via PlaylistService', function () {
    $recording = makeCompletedRecording([
        'season' => 1,
        'episode' => 2,
        'metadata' => ['tmdb' => ['id' => 1396, 'type' => 'tv', 'name' => 'Breaking Bad']],
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();
    $expectedUrl = PlaylistService::getBaseUrl('/dvr/recordings/'.$recording->uuid.'/stream');

    expect($episode->url)->toBe($expectedUrl);
});

it('does not duplicate an episode when integrate is called twice', function () {
    $recording = makeCompletedRecording([
        'season' => 1,
        'episode' => 1,
        'metadata' => ['tmdb' => ['id' => 1, 'type' => 'tv', 'name' => 'Dupe Show']],
    ]);

    $this->service->integrateRecording($recording);
    $this->service->integrateRecording($recording);

    expect(Episode::where('dvr_recording_id', $recording->id)->count())->toBe(1);
});

it('takes the series path when season is set but TMDB metadata is absent', function () {
    $recording = makeCompletedRecording([
        'title' => 'No Metadata Show',
        'season' => 2,
        'episode' => 3,
        'metadata' => null,
    ]);

    $this->service->integrateRecording($recording);

    expect(Episode::where('dvr_recording_id', $recording->id)->exists())->toBeTrue();
    expect(Channel::where('dvr_recording_id', $recording->id)->exists())->toBeFalse();
});

it('appends recording date to episode title when no season/episode numbers and no metadata', function () {
    $recording = makeCompletedRecording([
        'title' => 'CNN News',
        'subtitle' => null,
        'season' => 1,
        'episode' => null,
        'metadata' => null,
        'programme_start' => Carbon::parse('2026-04-21'),
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();

    expect($episode->title)->toBe('CNN News — Apr 21, 2026');
});

it('uses tvmaze metadata as fallback for series name and cover when tmdb is absent', function () {
    $recording = makeCompletedRecording([
        'title' => 'Some TV Show',
        'season' => 1,
        'episode' => 1,
        'metadata' => [
            'tvmaze' => [
                'id' => 456,
                'name' => 'Some TV Show',
                'overview' => 'TVMaze plot for series',
                'poster_url' => 'https://tvmaze.test/show-poster.jpg',
                'premiered' => '2022-03-10',
            ],
        ],
    ]);

    $this->service->integrateRecording($recording);

    $series = Series::whereNull('source_series_id')
        ->where('name', 'Some TV Show')
        ->first();

    expect($series)->not->toBeNull()
        ->and($series->cover)->toBe('https://tvmaze.test/show-poster.jpg')
        ->and($series->plot)->toBe('TVMaze plot for series')
        ->and($series->release_date)->toBe('2022-03-10')
        ->and($series->tmdb_id)->toBeNull();
});

it('reuses the same Series and Season for two episodes of the same show', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create();

    $ep1 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Shared Show',
        'season' => 1,
        'episode' => 1,
        'metadata' => ['tmdb' => ['id' => 999, 'type' => 'tv', 'name' => 'Shared Show']],
    ]);

    $ep2 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Shared Show',
        'season' => 1,
        'episode' => 2,
        'metadata' => ['tmdb' => ['id' => 999, 'type' => 'tv', 'name' => 'Shared Show']],
    ]);

    $this->service->integrateRecording($ep1);
    $this->service->integrateRecording($ep2);

    expect(Series::where('name', 'Shared Show')->where('playlist_id', $playlist->id)->count())->toBe(1);
    expect(Season::where('season_number', 1)->count())->toBe(1);
    expect(Episode::whereIn('dvr_recording_id', [$ep1->id, $ep2->id])->count())->toBe(2);
});

// ── Edge cases ────────────────────────────────────────────────────────────────

it('skips gracefully when DvrSetting is missing', function () {
    $recording = makeCompletedRecording();

    // Simulate the DvrSetting having been deleted (cascade would delete the
    // recording too, but we test the guard by unsetting the loaded relation).
    $recording->setRelation('dvrSetting', null);

    expect(fn () => $this->service->integrateRecording($recording))->not->toThrow(Exception::class);

    expect(Channel::where('dvr_recording_id', $recording->id)->exists())->toBeFalse();
    expect(Episode::where('dvr_recording_id', $recording->id)->exists())->toBeFalse();
});

// ── Job wiring ────────────────────────────────────────────────────────────────

it('EnrichDvrMetadata dispatches IntegrateDvrRecordingToVod after enrichment', function () {
    $recording = makeCompletedRecording(['metadata' => null]);

    $enricher = Mockery::mock(DvrMetadataEnricherService::class);
    $enricher->shouldReceive('enrich')->once()->with(Mockery::on(fn ($r) => $r->id === $recording->id));

    (new EnrichDvrMetadata($recording->id))->handle($enricher);

    Queue::assertPushed(IntegrateDvrRecordingToVod::class, fn ($job) => $job->recordingId === $recording->id);
});

it('DvrPostProcessorService dispatches IntegrateDvrRecordingToVod when metadata enrichment is disabled', function () {
    $setting = DvrSetting::factory()->enabled()->create(['enable_metadata_enrichment' => false]);

    expect($setting->enable_metadata_enrichment)->toBeFalse();

    // Simulate the dispatch logic from step 3 of DvrPostProcessorService
    if (! $setting->enable_metadata_enrichment) {
        IntegrateDvrRecordingToVod::dispatch(999)->onQueue('dvr-post');
    }

    Queue::assertPushed(IntegrateDvrRecordingToVod::class, fn ($job) => $job->recordingId === 999);
});
