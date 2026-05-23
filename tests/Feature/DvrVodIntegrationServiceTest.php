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

use App\Enums\DvrRecordingStatus;
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
use App\Services\DvrPostProcessorService;
use App\Services\DvrVodIntegrationService;
use Carbon\Carbon;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build a completed DvrRecording with a DvrSetting that belongs to a Playlist.
 *
 * @param  array<string, mixed>  $overrides
 */
/**
 * @param  array<string, mixed>  $overrides
 */
function makeCompletedRecording(array $overrides = [], ?DvrSetting $reuseSetting = null): DvrRecording
{
    if ($reuseSetting !== null) {
        $setting = $reuseSetting;
        $user = $setting->user;
    } else {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create();
    }

    return DvrRecording::factory()
        ->completed()
        ->for($setting, 'dvrSetting')
        ->for($user)
        ->create($overrides);
}

beforeEach(function () {
    Queue::fake();

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
        ->and($channel->container_extension)->toBe('ts')
        ->and($channel->tmdb_id)->toBe(27205)
        ->and($channel->source_id)->toBeNull();
});

it('sets the VOD channel URL to the authenticated dvr stream route', function () {
    $recording = makeCompletedRecording([
        'season' => null,
        'episode' => null,
        'metadata' => ['tmdb' => ['id' => 1, 'type' => 'movie', 'name' => 'Test Movie']],
    ]);

    $this->service->integrateRecording($recording);

    $channel = Channel::where('dvr_recording_id', $recording->id)->firstOrFail();
    $setting = $recording->dvrSetting;
    $expectedUrl = route('dvr.recording.stream', [
        'username' => $recording->user->name,
        'password' => $setting->playlist->uuid,
        'uuid' => $recording->uuid,
        'format' => $setting->dvr_output_format ?? 'ts',
    ]);

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
        ->and($channel->year)->toBe(2025)
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

it('sets the episode URL to the authenticated dvr stream route', function () {
    $recording = makeCompletedRecording([
        'season' => 1,
        'episode' => 2,
        'metadata' => ['tmdb' => ['id' => 1396, 'type' => 'tv', 'name' => 'Breaking Bad']],
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();
    $setting = $recording->dvrSetting;
    $expectedUrl = route('dvr.recording.stream', [
        'username' => $recording->user->name,
        'password' => $setting->playlist->uuid,
        'uuid' => $recording->uuid,
        'format' => $setting->dvr_output_format ?? 'ts',
    ]);

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

it('sets episode_count to 1 on the season after a single TV recording is integrated', function () {
    $recording = makeCompletedRecording([
        'season' => 1,
        'episode' => 1,
        'metadata' => ['tmdb' => ['id' => 1, 'type' => 'tv', 'name' => 'Count Show']],
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();
    $season = Season::find($episode->season_id);

    expect($season->episode_count)->toBe(1);
});

it('increments episode_count when a second recording is added to the same season', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create();

    $ep1 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Count Show',
        'season' => 1,
        'episode' => 1,
        'metadata' => ['tmdb' => ['id' => 1, 'type' => 'tv', 'name' => 'Count Show']],
    ]);
    $ep2 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Count Show',
        'season' => 1,
        'episode' => 2,
        'metadata' => ['tmdb' => ['id' => 1, 'type' => 'tv', 'name' => 'Count Show']],
    ]);

    $this->service->integrateRecording($ep1);
    $this->service->integrateRecording($ep2);

    $season = Season::where('season_number', 1)->first();

    expect($season->episode_count)->toBe(2);
});

it('keeps episode_count at 1 when the same recording is integrated twice (idempotency)', function () {
    $recording = makeCompletedRecording([
        'season' => 2,
        'episode' => 1,
        'metadata' => ['tmdb' => ['id' => 1, 'type' => 'tv', 'name' => 'Idempotent Show']],
    ]);

    $this->service->integrateRecording($recording);
    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();
    $season = Season::find($episode->season_id);

    expect($season->episode_count)->toBe(1);
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
    Queue::fake();
    Notification::fake();
    Storage::fake('dvr');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create([
        'storage_disk' => 'dvr',
        'enable_metadata_enrichment' => false,
        'dvr_output_format' => 'ts',
    ]);

    $recording = DvrRecording::factory()
        ->for($setting, 'dvrSetting')
        ->for($user)
        ->create([
            'status' => DvrRecordingStatus::PostProcessing,
            'programme_start' => now()->subHour(),
            'scheduled_start' => now()->subHour(),
            'season' => null,
            'episode' => null,
            'proxy_network_id' => null,
        ]);

    // Write a minimal single-segment HLS manifest and its TS segment
    // (no proxy_network_id → post-processor falls back to local files at live/<recording_uuid>/)
    Storage::disk('dvr')->put(
        "live/{$recording->uuid}/live.m3u8",
        "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXTINF:10.0,\nseg000.ts\n#EXT-X-ENDLIST\n"
    );
    Storage::disk('dvr')->put("live/{$recording->uuid}/seg000.ts", str_repeat("\x00", 188));

    app(DvrPostProcessorService::class)->run($recording);

    $recording->refresh();
    expect($recording->status)->toBe(DvrRecordingStatus::Completed);
    Queue::assertPushed(IntegrateDvrRecordingToVod::class, fn ($job) => $job->recordingId === $recording->id);

    // Bell notification sent on completion
    Notification::assertSentTo($user, DatabaseNotification::class);
});

// ── Fix #2: created_at fallback in formatRecordingDate ────────────────────────

it('uses scheduled_start as date fallback when programme_start is null', function () {
    $recording = makeCompletedRecording([
        'title' => 'Late Night Show',
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'programme_start' => null,
        'scheduled_start' => Carbon::parse('2025-11-08 20:00:00'),
    ]);

    $this->service->integrateRecording($recording);

    $channel = Channel::where('dvr_recording_id', $recording->id)->firstOrFail();

    expect($channel->name)->toBe('Late Night Show — Nov 8, 2025');
});

// ── Fix #3: date-based episode numbers when episode is null ───────────────────

it('derives a date-based episode number (MMDD) when season is set but episode is null', function () {
    $recording = makeCompletedRecording([
        'title' => 'Daily News',
        'season' => 2025,
        'episode' => null,
        'metadata' => null,
        'programme_start' => Carbon::parse('2025-04-21'),
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();

    // Apr 21 → 4 * 100 + 21 = 421
    expect($episode->episode_num)->toBe(421);
});

it('two recordings on different dates with no episode number get distinct episode_nums', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create();

    $ep1 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Daily News',
        'season' => 2025,
        'episode' => null,
        'metadata' => null,
        'programme_start' => Carbon::parse('2025-04-21'),
    ]);

    $ep2 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Daily News',
        'season' => 2025,
        'episode' => null,
        'metadata' => null,
        'programme_start' => Carbon::parse('2025-04-22'),
    ]);

    $this->service->integrateRecording($ep1);
    $this->service->integrateRecording($ep2);

    $nums = Episode::whereIn('dvr_recording_id', [$ep1->id, $ep2->id])
        ->pluck('episode_num')
        ->sort()
        ->values();

    expect($nums->toArray())->toBe([421, 422]);
});

it('date-based episode number is idempotent — re-running integration does not change episode_num', function () {
    $recording = makeCompletedRecording([
        'title' => 'Daily News',
        'season' => 2025,
        'episode' => null,
        'metadata' => null,
        'programme_start' => Carbon::parse('2025-06-15'),
    ]);

    $this->service->integrateRecording($recording);
    $first = Episode::where('dvr_recording_id', $recording->id)->value('episode_num');

    $this->service->integrateRecording($recording);
    $second = Episode::where('dvr_recording_id', $recording->id)->value('episode_num');

    expect($first)->toBe(615)->and($second)->toBe(615);
    expect(Episode::where('dvr_recording_id', $recording->id)->count())->toBe(1);
});

// ── Fix #4: case-insensitive series name deduplication ───────────────────────

it('reuses the same Series when a recording title differs only in case from an existing series', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create();

    // First recording — no TMDB, stores title as-is ("breaking bad")
    $ep1 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'breaking bad',
        'season' => 1,
        'episode' => 1,
        'metadata' => null,
    ]);

    // Second recording — TMDB enriched with canonical casing ("Breaking Bad")
    $ep2 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 2,
        'metadata' => ['tmdb' => ['id' => 1396, 'type' => 'tv', 'name' => 'Breaking Bad']],
    ]);

    $this->service->integrateRecording($ep1);
    $this->service->integrateRecording($ep2);

    // Should be exactly one Series, not two
    expect(Series::where('playlist_id', $playlist->id)->whereNull('source_series_id')->count())->toBe(1);

    // The TMDB backfill should have upgraded the name and set tmdb_id
    $series = Series::where('playlist_id', $playlist->id)->whereNull('source_series_id')->first();
    expect($series->name)->toBe('Breaking Bad')
        ->and($series->tmdb_id)->toBe(1396);
});

// ── Fix #1: tmdb_id-first series matching (rename-resilient dedup) ──────────

it('reuses the same Series when tmdb_id matches even if the title was renamed', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create();

    // First recording creates a series with tmdb_id 1396 under the original
    // localized title.
    $ep1 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Breaking Bad: Original Localized Title',
        'season' => 1,
        'episode' => 1,
        'metadata' => ['tmdb' => ['id' => 1396, 'type' => 'tv', 'name' => 'Breaking Bad: Original Localized Title']],
    ]);
    $this->service->integrateRecording($ep1);

    // Second recording for the same TMDB show has a completely different
    // display title (e.g. provider switched to canonical English name).
    // Without tmdb_id matching this would create a duplicate Series row.
    $ep2 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 2,
        'metadata' => ['tmdb' => ['id' => 1396, 'type' => 'tv', 'name' => 'Breaking Bad']],
    ]);
    $this->service->integrateRecording($ep2);

    expect(Series::where('playlist_id', $playlist->id)->whereNull('source_series_id')->count())->toBe(1);

    $series = Series::where('playlist_id', $playlist->id)->whereNull('source_series_id')->first();
    expect((string) $series->tmdb_id)->toBe('1396');

    // Both episodes attach to the single shared series.
    expect(Episode::where('series_id', $series->id)->count())->toBe(2);
});

it('falls back to name match when no tmdb_id is provided and reuses existing series', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create();

    $ep1 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Local Show',
        'season' => 1,
        'episode' => 1,
        'metadata' => null,
    ]);

    $ep2 = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'title' => 'Local Show',
        'season' => 1,
        'episode' => 2,
        'metadata' => null,
    ]);

    $this->service->integrateRecording($ep1);
    $this->service->integrateRecording($ep2);

    expect(Series::where('playlist_id', $playlist->id)->whereNull('source_series_id')->count())->toBe(1);
});

// ── Gap 2: isTvContent routes via epg_programme_data.episode_num ─────────────

it('routes to series when episode_num is in epg_programme_data even if season/episode columns are null', function () {
    $recording = makeCompletedRecording([
        'title' => 'Breaking Bad',
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'epg_programme_data' => ['episode_num' => 'S01E03'],
    ]);

    $this->service->integrateRecording($recording);

    // Must create a Series/Episode, NOT a movie VOD channel
    expect(Episode::where('dvr_recording_id', $recording->id)->exists())->toBeTrue();
    expect(Channel::where('dvr_recording_id', $recording->id)->exists())->toBeFalse();
});

it('routes to movie when epg_programme_data has no episode_num and season is null', function () {
    $recording = makeCompletedRecording([
        'title' => 'A Movie',
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'epg_programme_data' => ['epg_channel_id' => 'test.ch'],
    ]);

    $this->service->integrateRecording($recording);

    expect(Channel::where('dvr_recording_id', $recording->id)->exists())->toBeTrue();
    expect(Episode::where('dvr_recording_id', $recording->id)->exists())->toBeFalse();
});

// ── Gap 2: resolveEpisodeNumber parses episode_num when episode column is null ─

it('parses episode_num from epg_programme_data to get episode number when recording.episode is null', function () {
    $recording = makeCompletedRecording([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => null,
        'metadata' => null,
        'epg_programme_data' => ['episode_num' => 'S01E07'],
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();
    expect($episode->episode_num)->toBe(7);
});

it('falls through to date-based episode number when episode_num is not parseable', function () {
    $recording = makeCompletedRecording([
        'title' => 'Live Sport',
        'season' => 2025,
        'episode' => null,
        'metadata' => null,
        'programme_start' => Carbon::parse('2025-09-03'),
        'epg_programme_data' => ['episode_num' => ''],  // empty — no parseable data
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();
    // Sep 3 → 9 * 100 + 3 = 903
    expect($episode->episode_num)->toBe(903);
});

// ── Gap 3: episode-level metadata used in episode info block ─────────────────

it('uses tmdb_episode still_url and overview in episode info when present', function () {
    $recording = makeCompletedRecording([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 3,
        'metadata' => [
            'tmdb' => ['id' => 1396, 'type' => 'tv', 'name' => 'Breaking Bad', 'overview' => 'Show overview.'],
            'tmdb_episode' => [
                'id' => 62085,
                'name' => 'Pilot',
                'overview' => 'Episode-specific overview.',
                'still_url' => 'https://image.tmdb.org/t/p/w300/still.jpg',
                'air_date' => '2008-02-10',
            ],
        ],
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();
    expect($episode->info['plot'])->toBe('Episode-specific overview.')
        ->and($episode->info['movie_image'])->toBe('https://image.tmdb.org/t/p/w300/still.jpg')
        ->and($episode->info['release_date'])->toBe('2008-02-10');
});

it('falls back to show-level TMDB data in episode info when tmdb_episode is absent and recording has no description', function () {
    $recording = makeCompletedRecording([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 3,
        'programme_start' => Carbon::parse('2008-02-10'),
        'description' => null,
        'metadata' => [
            'tmdb' => [
                'id' => 1396,
                'type' => 'tv',
                'name' => 'Breaking Bad',
                'overview' => 'Show-level overview.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            ],
        ],
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();
    expect($episode->info['plot'])->toBe('Show-level overview.')
        ->and($episode->info['movie_image'])->toBe('https://image.tmdb.org/t/p/w500/poster.jpg')
        ->and($episode->info['release_date'])->toBe('2008-02-10');
});

it('prefers recording description over show-level overview when tmdb_episode is absent', function () {
    $recording = makeCompletedRecording([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 3,
        'programme_start' => Carbon::parse('2008-02-10'),
        'description' => 'Episode description from EPG — specific to this airing.',
        'metadata' => [
            'tmdb' => [
                'id' => 1396,
                'type' => 'tv',
                'name' => 'Breaking Bad',
                'overview' => 'Show-level generic overview.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            ],
        ],
    ]);

    $this->service->integrateRecording($recording);

    $episode = Episode::where('dvr_recording_id', $recording->id)->firstOrFail();
    expect($episode->info['plot'])->toBe('Episode description from EPG — specific to this airing.');
});

// ── Fix #8: TVMaze name + id backfill on later runs ──────────────────────────

it('upgrades a stripped-title series to the TVMaze canonical name when TVMaze data arrives later', function () {
    // First recording: no enrichment at all.  Series gets stripped recording
    // title as its name and no metadata.
    $first = makeCompletedRecording([
        'title' => 'breaking bad',
        'season' => 1,
        'episode' => 1,
        'metadata' => null,
    ]);

    $this->service->integrateRecording($first);

    $seriesAfterFirst = Series::whereNull('source_series_id')->firstOrFail();
    expect($seriesAfterFirst->name)->toBe('breaking bad')
        ->and($seriesAfterFirst->tmdb_id)->toBeNull()
        ->and($seriesAfterFirst->metadata)->toBeNull();

    // Second recording: same show, now enriched with TVMaze (still no TMDB).
    $second = makeCompletedRecording([
        'title' => 'breaking bad',
        'season' => 1,
        'episode' => 2,
        'metadata' => [
            'tvmaze' => [
                'id' => 169,
                'name' => 'Breaking Bad',
                'overview' => 'A high school chemistry teacher.',
                'poster_url' => 'https://tvmaze.test/bb-poster.jpg',
                'premiered' => '2008-01-20',
            ],
        ],
    ], $first->dvrSetting);

    $this->service->integrateRecording($second);

    // Both episodes should now point at the *same* series, and that series
    // should have been upgraded to the canonical TVMaze name + metadata.
    $allSeries = Series::whereNull('source_series_id')->get();
    expect($allSeries)->toHaveCount(1);

    $series = $allSeries->first();
    expect($series->name)->toBe('Breaking Bad')
        ->and($series->cover)->toBe('https://tvmaze.test/bb-poster.jpg')
        ->and($series->plot)->toBe('A high school chemistry teacher.')
        ->and($series->release_date)->toBe('2008-01-20')
        ->and($series->metadata['tvmaze']['id'] ?? null)->toBe(169);
});

it('reuses an existing TVMaze-enriched series via metadata.tvmaze.id even when the title differs', function () {
    // First recording creates a TVMaze-enriched series.
    $first = makeCompletedRecording([
        'title' => 'Severance',
        'season' => 1,
        'episode' => 1,
        'metadata' => [
            'tvmaze' => [
                'id' => 37776,
                'name' => 'Severance',
                'overview' => 'Mark leads a team.',
                'poster_url' => 'https://tvmaze.test/sev.jpg',
                'premiered' => '2022-02-18',
            ],
        ],
    ]);

    $this->service->integrateRecording($first);

    $seriesAfterFirst = Series::whereNull('source_series_id')->firstOrFail();
    expect($seriesAfterFirst->metadata['tvmaze']['id'] ?? null)->toBe(37776);

    // Second recording: provider sends a slightly different title (e.g. a
    // localized variant) but TVMaze resolves to the same id.
    $second = makeCompletedRecording([
        'title' => 'Severance (US)',
        'season' => 1,
        'episode' => 2,
        'metadata' => [
            'tvmaze' => [
                'id' => 37776,
                'name' => 'Severance',
                'overview' => 'Mark leads a team.',
                'poster_url' => 'https://tvmaze.test/sev.jpg',
                'premiered' => '2022-02-18',
            ],
        ],
    ], $first->dvrSetting);

    $this->service->integrateRecording($second);

    // Should NOT create a duplicate series — TVMaze id match wins.
    expect(Series::whereNull('source_series_id')->count())->toBe(1);
});

it('does not clobber a TMDB-derived series name with a later TVMaze name', function () {
    // First recording: TMDB enrichment establishes the canonical name.
    $first = makeCompletedRecording([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 1,
        'metadata' => [
            'tmdb' => [
                'id' => 1396,
                'type' => 'tv',
                'name' => 'Breaking Bad',
                'overview' => 'TMDB plot.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/bb.jpg',
                'first_air_date' => '2008-01-20',
            ],
        ],
    ]);

    $this->service->integrateRecording($first);

    $seriesAfterFirst = Series::whereNull('source_series_id')->firstOrFail();
    expect((string) $seriesAfterFirst->tmdb_id)->toBe('1396')
        ->and($seriesAfterFirst->name)->toBe('Breaking Bad');

    // Second recording: only TVMaze enrichment, with a *different* name
    // (simulating a TVMaze locale mismatch).  Existing tmdb_id should keep
    // the lookup pointing at the same row, and TVMaze must NOT overwrite
    // the canonical TMDB name.
    $second = makeCompletedRecording([
        'title' => 'Breaking Bad',
        'season' => 1,
        'episode' => 2,
        'metadata' => [
            'tmdb' => [
                'id' => 1396,
                'type' => 'tv',
                'name' => 'Breaking Bad',
                'overview' => 'TMDB plot.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/bb.jpg',
                'first_air_date' => '2008-01-20',
            ],
            'tvmaze' => [
                'id' => 169,
                'name' => 'Wrong Localized Name',
                'overview' => 'TVMaze plot.',
                'poster_url' => 'https://tvmaze.test/wrong.jpg',
                'premiered' => '2008-01-20',
            ],
        ],
    ], $first->dvrSetting);

    $this->service->integrateRecording($second);

    expect(Series::whereNull('source_series_id')->count())->toBe(1);
    $series = Series::whereNull('source_series_id')->first();
    expect($series->name)->toBe('Breaking Bad')
        ->and((string) $series->tmdb_id)->toBe('1396');
});

// ── Fix #4: EPG category as TV/movie classification signal ────────────────────

it('classifies recording with category="series" as TV when no S/E numbers', function () {
    $recording = makeCompletedRecording([
        'title' => 'The Late Show',
        'subtitle' => 'Tonight with Special Guest',
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'epg_programme_data' => [
            'category' => 'series',
        ],
        'programme_start' => Carbon::parse('2025-04-21 23:00:00'),
    ]);

    $this->service->integrateRecording($recording);

    expect(Series::count())->toBe(1)
        ->and(Channel::where('dvr_recording_id', $recording->id)->exists())->toBeFalse();

    $series = Series::first();
    expect($series->name)->toBe('The Late Show');
});

it('classifies recording with category="movie" as movie even when subtitle present', function () {
    $recording = makeCompletedRecording([
        'title' => 'Inception',
        'subtitle' => "Director's Cut",
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'epg_programme_data' => [
            'category' => 'movie',
        ],
    ]);

    $this->service->integrateRecording($recording);

    expect(Series::count())->toBe(0)
        ->and(Channel::where('dvr_recording_id', $recording->id)->exists())->toBeTrue();
});

it('classifies recording with non-movie category and subtitle as TV', function () {
    $recording = makeCompletedRecording([
        'title' => 'Premier League',
        'subtitle' => 'Liverpool vs Arsenal',
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'epg_programme_data' => [
            'category' => 'sports',
        ],
        'programme_start' => Carbon::parse('2025-04-21 15:00:00'),
    ]);

    $this->service->integrateRecording($recording);

    expect(Series::count())->toBe(1);
    $series = Series::first();
    expect($series->name)->toBe('Premier League');
});

it('falls through to movie when no category, no S/E, no subtitle', function () {
    $recording = makeCompletedRecording([
        'title' => 'Some Random Programme',
        'subtitle' => null,
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'epg_programme_data' => null,
    ]);

    $this->service->integrateRecording($recording);

    expect(Series::count())->toBe(0)
        ->and(Channel::where('dvr_recording_id', $recording->id)->exists())->toBeTrue();
});

it('classifies category="news" without S/E as TV', function () {
    $recording = makeCompletedRecording([
        'title' => 'Local Evening News',
        'subtitle' => null,
        'season' => null,
        'episode' => null,
        'metadata' => null,
        'epg_programme_data' => [
            'category' => 'News',
        ],
        'programme_start' => Carbon::parse('2025-04-21 18:00:00'),
    ]);

    $this->service->integrateRecording($recording);

    expect(Series::count())->toBe(1);
});
