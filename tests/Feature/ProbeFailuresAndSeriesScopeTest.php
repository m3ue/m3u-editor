<?php

use App\Enums\SeriesProbeScope;
use App\Events\PlaylistCreated;
use App\Events\PlaylistUpdated;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Jobs\ProbeStreams;
use App\Jobs\ProbeStreamsChunk;
use App\Jobs\ProbeStreamsComplete;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Support\ProbeCircuitBreaker;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Covers issue #1575 (failed probes recorded, retried after a window, circuit breaker) and
 * issue #1576 (sampled series probing that reuses one episode's stats per season/series).
 */
beforeEach(function () {
    Event::fake([PlaylistCreated::class, PlaylistUpdated::class]);
    Notification::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();

    // A fake ffprobe on PATH: URLs containing "fail" exit non-zero, anything else reports a
    // single 1080p h264 stream. Items with no URL never reach ffprobe at all.
    $this->fakeBinDir = storage_path('app/testing-fake-ffprobe');
    File::ensureDirectoryExists($this->fakeBinDir);
    File::put($this->fakeBinDir.'/ffprobe', <<<'SH'
#!/bin/sh
for last; do :; done
case "$last" in
    *fail*) exit 1 ;;
esac
echo '{"streams":[{"codec_type":"video","codec_name":"h264","width":1920,"height":1080}]}'
SH);
    chmod($this->fakeBinDir.'/ffprobe', 0755);

    $this->originalPath = getenv('PATH');
    putenv('PATH='.$this->fakeBinDir.PATH_SEPARATOR.$this->originalPath);
});

afterEach(function () {
    putenv('PATH='.$this->originalPath);
    File::deleteDirectory($this->fakeBinDir);
});

function probeVod(Playlist $playlist, array $attributes = []): Channel
{
    return Channel::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'enabled' => true,
        'is_vod' => true,
        'probe_enabled' => true,
        'url_custom' => null,
        'stream_stats' => null,
        'stream_stats_probed_at' => null,
        ...$attributes,
    ]);
}

function probeEpisode(Playlist $playlist, Series $series, Season $season, int $episodeNum, array $attributes = []): Episode
{
    return Episode::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => $season->season_number,
        'episode_num' => $episodeNum,
        'enabled' => true,
        'probe_enabled' => true,
        'stream_stats' => null,
        'stream_stats_probed_at' => null,
        ...$attributes,
    ]);
}

function probeSeason(Playlist $playlist, Series $series, int $number): Season
{
    return Season::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season_number' => $number,
    ]);
}

function sampleStats(): array
{
    return [['stream' => ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 3840, 'height' => 2160]]];
}

// ── ProbeStreamsChunk: recording outcomes ──────────────────────────────────

it('records a failed probe so the probe failed filter and incremental runs can see it', function () {
    $channel = probeVod($this->playlist, ['url' => 'http://provider.test/movie-fail.mkv']);

    (new ProbeStreamsChunk(channelIds: [$channel->id]))->handle();

    $channel->refresh();
    expect($channel->stream_stats_probed_at)->not->toBeNull()
        ->and($channel->getRawOriginal('stream_stats'))->toBeNull();
});

it('stores stream stats on a successful probe', function () {
    $channel = probeVod($this->playlist, ['url' => 'http://provider.test/movie-ok.mkv']);

    (new ProbeStreamsChunk(channelIds: [$channel->id]))->handle();

    $channel->refresh();
    expect($channel->stream_stats_probed_at)->not->toBeNull()
        ->and($channel->stream_stats[0]['stream']['codec_name'])->toBe('h264');
});

it('keeps last known good stats when a re-probe fails', function () {
    $probedAt = now()->subDays(3)->startOfSecond();
    $channel = probeVod($this->playlist, [
        'url' => 'http://provider.test/movie-fail.mkv',
        'stream_stats' => sampleStats(),
        'stream_stats_probed_at' => $probedAt,
    ]);

    (new ProbeStreamsChunk(channelIds: [$channel->id]))->handle();

    $channel->refresh();
    expect($channel->stream_stats)->toBe(sampleStats())
        ->and($channel->stream_stats_probed_at->equalTo($probedAt))->toBeTrue();
});

it('replaces inferred episode stats with its own measurement or failure', function () {
    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $season = probeSeason($this->playlist, $series, 1);
    $source = probeEpisode($this->playlist, $series, $season, 1, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()]);
    $ok = probeEpisode($this->playlist, $series, $season, 2, [
        'url' => 'http://provider.test/s01e02-ok.mkv',
        'stream_stats' => sampleStats(),
        'stream_stats_probed_at' => now(),
        'stream_stats_inferred_from_id' => $source->id,
    ]);
    $failing = probeEpisode($this->playlist, $series, $season, 3, [
        'url' => 'http://provider.test/s01e03-fail.mkv',
        'stream_stats' => sampleStats(),
        'stream_stats_probed_at' => now(),
        'stream_stats_inferred_from_id' => $source->id,
    ]);

    (new ProbeStreamsChunk(episodeIds: [$ok->id, $failing->id]))->handle();

    $ok->refresh();
    $failing->refresh();
    expect($ok->stream_stats_inferred_from_id)->toBeNull()
        ->and($ok->stream_stats[0]['stream']['codec_name'])->toBe('h264')
        ->and($failing->stream_stats_inferred_from_id)->toBeNull()
        ->and($failing->getRawOriginal('stream_stats'))->toBeNull()
        ->and($failing->stream_stats_probed_at)->not->toBeNull();
});

it('shares a sampled episode measurement with its siblings without overwriting real measurements', function () {
    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $season = probeSeason($this->playlist, $series, 1);
    $sample = probeEpisode($this->playlist, $series, $season, 1, ['url' => 'http://provider.test/s01e01-ok.mkv']);
    $unprobed = probeEpisode($this->playlist, $series, $season, 2);
    $measured = probeEpisode($this->playlist, $series, $season, 3, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()]);

    (new ProbeStreamsChunk(
        episodeIds: [$sample->id],
        episodeSiblings: [$sample->id => [$unprobed->id, $measured->id]],
    ))->handle();

    $unprobed->refresh();
    $measured->refresh();
    expect($unprobed->stream_stats_inferred_from_id)->toBe($sample->id)
        ->and($unprobed->stream_stats[0]['stream']['codec_name'])->toBe('h264')
        ->and($measured->stream_stats_inferred_from_id)->toBeNull()
        ->and($measured->stream_stats)->toBe(sampleStats());
});

// ── Circuit breaker ────────────────────────────────────────────────────────

it('stops a run once the failure threshold trips, leaving the rest unprobed', function () {
    $channels = collect(range(1, ProbeCircuitBreaker::MIN_SAMPLE + 5))
        ->map(fn () => probeVod($this->playlist, ['url' => 'http://provider.test/movie-fail.mkv']));

    (new ProbeStreamsChunk(
        channelIds: $channels->pluck('id')->all(),
        probeRunKey: 'test-run',
        failureThreshold: 80,
    ))->handle();

    expect(Channel::whereIn('id', $channels->pluck('id'))->whereNotNull('stream_stats_probed_at')->count())
        ->toBe(ProbeCircuitBreaker::MIN_SAMPLE)
        ->and(ProbeCircuitBreaker::forRun('test-run', 80)->isTripped())->toBeTrue();
});

it('does not count retries of known failures toward the breaker', function () {
    $channels = collect(range(1, ProbeCircuitBreaker::MIN_SAMPLE + 5))
        ->map(fn () => probeVod($this->playlist, [
            'url' => 'http://provider.test/movie-fail.mkv',
            'stream_stats_probed_at' => now()->subDays(30),
        ]));

    (new ProbeStreamsChunk(
        channelIds: $channels->pluck('id')->all(),
        probeRunKey: 'retry-run',
        failureThreshold: 80,
    ))->handle();

    expect(Channel::whereIn('id', $channels->pluck('id'))->where('stream_stats_probed_at', '>=', now()->subMinute())->count())
        ->toBe($channels->count())
        ->and(ProbeCircuitBreaker::forRun('retry-run', 80)->isTripped())->toBeFalse();
});

it('sends a paused notification when the run breaker tripped', function () {
    Cache::put('probe-run:paused-run:attempted', 20);
    Cache::put('probe-run:paused-run:failed', 19);
    Cache::put('probe-run:paused-run:tripped', true);

    (new ProbeStreamsComplete(
        playlistId: $this->playlist->id,
        total: 100,
        start: now()->subMinute(),
        probeRunKey: 'paused-run',
        failureThreshold: 80,
    ))->handle();

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn ($notification) => $notification->toArray()['title'] === 'VOD stream probing paused'
            && str_contains($notification->toArray()['body'], 'Stopped after 19 of 20 probes failed (more than 80%)')
    );
    expect(Cache::has('probe-run:paused-run:tripped'))->toBeFalse();
});

it('counts only real measurements as probed in the completion notification', function () {
    $start = now()->subMinute();
    probeVod($this->playlist, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()]);
    probeVod($this->playlist, ['stream_stats_probed_at' => now()]);

    (new ProbeStreamsComplete(playlistId: $this->playlist->id, total: 2, start: $start))->handle();

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn ($notification) => $notification->toArray()['body'] === 'Probed 1 of 2 VOD channel(s) and episode(s). (1 failed)'
    );
});

// ── ProbeStreams: selection ────────────────────────────────────────────────

it('retries failed probes only once the retry window has passed', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_vod_streams_retry_failed_days' => 7]);

    $unprobed = probeVod($this->playlist);
    $dueFailure = probeVod($this->playlist, ['stream_stats_probed_at' => now()->subDays(8)]);
    probeVod($this->playlist, ['stream_stats_probed_at' => now()->subDays(2)]);
    probeVod($this->playlist, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()->subDays(30)]);

    (new ProbeStreams(playlistId: $this->playlist->id))->handle();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => collect($job->channelIds)->sort()->values()->all() === collect([$unprobed->id, $dueFailure->id])->sort()->values()->all()
            && $job->probeRunKey !== null
            && $job->failureThreshold === 80,
        ProbeStreamsComplete::class,
    ]);
});

it('retries every failure when the retry window is 0', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_vod_streams_retry_failed_days' => 0]);

    $recentFailure = probeVod($this->playlist, ['stream_stats_probed_at' => now()->subMinute()]);

    (new ProbeStreams(playlistId: $this->playlist->id))->handle();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->channelIds === [$recentFailure->id],
        ProbeStreamsComplete::class,
    ]);
});

it('probes only its own content type per sync phase', function () {
    Bus::fake();
    $vod = probeVod($this->playlist);
    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $episode = probeEpisode($this->playlist, $series, probeSeason($this->playlist, $series, 1), 1);

    (new ProbeStreams(playlistId: $this->playlist->id))->handle();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->channelIds === [$vod->id] && $job->episodeIds === [],
        ProbeStreamsComplete::class,
    ]);

    Bus::fake();
    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->channelIds === [] && $job->episodeIds === [$episode->id],
        ProbeStreamsComplete::class,
    ]);
});

it('treats inferred episodes as unprobed when every episode is probed', function () {
    Bus::fake();
    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $season = probeSeason($this->playlist, $series, 1);
    $source = probeEpisode($this->playlist, $series, $season, 1, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()]);
    $inferred = probeEpisode($this->playlist, $series, $season, 2, [
        'stream_stats' => sampleStats(),
        'stream_stats_probed_at' => now(),
        'stream_stats_inferred_from_id' => $source->id,
    ]);

    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->episodeIds === [$inferred->id],
        ProbeStreamsComplete::class,
    ]);
});

it('probes the first episode of each season and maps the rest as siblings', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_series_scope' => SeriesProbeScope::Season]);

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $seasonOne = probeSeason($this->playlist, $series, 1);
    $seasonTwo = probeSeason($this->playlist, $series, 2);
    $s1e2 = probeEpisode($this->playlist, $series, $seasonOne, 2);
    $s1e1 = probeEpisode($this->playlist, $series, $seasonOne, 1);
    $s1e3 = probeEpisode($this->playlist, $series, $seasonOne, 3);
    $s2e1 = probeEpisode($this->playlist, $series, $seasonTwo, 1);
    $s2e2 = probeEpisode($this->playlist, $series, $seasonTwo, 2);

    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => collect($job->episodeIds)->sort()->values()->all() === collect([$s1e1->id, $s2e1->id])->sort()->values()->all()
            && $job->episodeSiblings[$s1e1->id] === [$s1e2->id, $s1e3->id]
            && $job->episodeSiblings[$s2e1->id] === [$s2e2->id],
        ProbeStreamsComplete::class,
    ]);
});

it('probes the first episode of each series when scoped per series', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_series_scope' => SeriesProbeScope::Series]);

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $seasonOne = probeSeason($this->playlist, $series, 1);
    $seasonTwo = probeSeason($this->playlist, $series, 2);
    $s2e1 = probeEpisode($this->playlist, $series, $seasonTwo, 1);
    $s1e1 = probeEpisode($this->playlist, $series, $seasonOne, 1);
    $s1e2 = probeEpisode($this->playlist, $series, $seasonOne, 2);

    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->episodeIds === [$s1e1->id]
            && $job->episodeSiblings[$s1e1->id] === [$s1e2->id, $s2e1->id],
        ProbeStreamsComplete::class,
    ]);
});

it('fills new episodes of an already measured season without probing them', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_series_scope' => SeriesProbeScope::Season]);

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $measuredSeason = probeSeason($this->playlist, $series, 1);
    $newSeason = probeSeason($this->playlist, $series, 2);
    $source = probeEpisode($this->playlist, $series, $measuredSeason, 1, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()->subDays(10)]);
    $newEpisode = probeEpisode($this->playlist, $series, $measuredSeason, 2);
    $newSeasonFirst = probeEpisode($this->playlist, $series, $newSeason, 1);

    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    $newEpisode->refresh();
    expect($newEpisode->stream_stats_inferred_from_id)->toBe($source->id)
        ->and($newEpisode->stream_stats)->toBe(sampleStats());

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->episodeIds === [$newSeasonFirst->id]
            && $job->episodeSiblings[$newSeasonFirst->id] === [],
        ProbeStreamsComplete::class,
    ]);
});

it('samples later seasons once the scope narrows from series to season', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_series_scope' => SeriesProbeScope::Season]);

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $seasonOne = probeSeason($this->playlist, $series, 1);
    $seasonTwo = probeSeason($this->playlist, $series, 2);
    $source = probeEpisode($this->playlist, $series, $seasonOne, 1, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()->subDays(10)]);
    $copiedFromSeries = ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()->subDays(10), 'stream_stats_inferred_from_id' => $source->id];
    $s1e2 = probeEpisode($this->playlist, $series, $seasonOne, 2, $copiedFromSeries);
    $s2e1 = probeEpisode($this->playlist, $series, $seasonTwo, 1, $copiedFromSeries);
    $s2e2 = probeEpisode($this->playlist, $series, $seasonTwo, 2, $copiedFromSeries);

    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->episodeIds === [$s2e1->id]
            && $job->episodeSiblings[$s2e1->id] === [$s2e2->id],
        ProbeStreamsComplete::class,
    ]);
    expect($s1e2->fresh()->stream_stats_probed_at->lt(now()->subDays(9)))->toBeTrue();
});

it('refreshes inferred copies once their source is re-probed', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_series_scope' => SeriesProbeScope::Season]);

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $season = probeSeason($this->playlist, $series, 1);
    $source = probeEpisode($this->playlist, $series, $season, 1, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()]);
    $copy = probeEpisode($this->playlist, $series, $season, 2, [
        'stream_stats' => [['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]]],
        'stream_stats_probed_at' => now()->subDay(),
        'stream_stats_inferred_from_id' => $source->id,
    ]);

    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    Bus::assertNothingDispatched();
    $copy->refresh();
    expect($copy->stream_stats)->toBe(sampleStats())
        ->and($copy->stream_stats_inferred_from_id)->toBe($source->id);
});

it('leaves up to date inferred copies alone', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_series_scope' => SeriesProbeScope::Series]);

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $seasonOne = probeSeason($this->playlist, $series, 1);
    $seasonTwo = probeSeason($this->playlist, $series, 2);
    $source = probeEpisode($this->playlist, $series, $seasonOne, 1, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()->subDays(10)]);
    $copy = probeEpisode($this->playlist, $series, $seasonTwo, 1, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()->subDays(5), 'stream_stats_inferred_from_id' => $source->id]);

    // A specials-only series is sampled from a special, which never counts as a series-wide
    // source, so its copies must not send it back for probing every run.
    $specialsOnly = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $specials = probeSeason($this->playlist, $specialsOnly, 0);
    $special = probeEpisode($this->playlist, $specialsOnly, $specials, 1, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()->subDays(10)]);
    $specialCopy = probeEpisode($this->playlist, $specialsOnly, $specials, 2, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()->subDays(5), 'stream_stats_inferred_from_id' => $special->id]);

    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    Bus::assertNothingDispatched();
    expect($copy->fresh()->stream_stats_probed_at->lt(now()->subDays(4)))->toBeTrue()
        ->and($specialCopy->fresh()->stream_stats_probed_at->lt(now()->subDays(4)))->toBeTrue();
});

it('never samples a special when probing one episode per series', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_series_scope' => SeriesProbeScope::Series]);

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $specials = probeSeason($this->playlist, $series, 0);
    $seasonOne = probeSeason($this->playlist, $series, 1);
    $special = probeEpisode($this->playlist, $series, $specials, 1);
    $s1e1 = probeEpisode($this->playlist, $series, $seasonOne, 1);
    $s1e2 = probeEpisode($this->playlist, $series, $seasonOne, 2);

    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->episodeIds === [$s1e1->id]
            && $job->episodeSiblings[$s1e1->id] === [$special->id, $s1e2->id],
        ProbeStreamsComplete::class,
    ]);
});

it('does not let a measured special stand in for a whole series', function () {
    Bus::fake();
    $this->playlist->update(['auto_probe_series_scope' => SeriesProbeScope::Series]);

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $specials = probeSeason($this->playlist, $series, 0);
    $seasonOne = probeSeason($this->playlist, $series, 1);
    probeEpisode($this->playlist, $series, $specials, 1, ['stream_stats' => sampleStats(), 'stream_stats_probed_at' => now()]);
    $s1e1 = probeEpisode($this->playlist, $series, $seasonOne, 1);
    $s1e2 = probeEpisode($this->playlist, $series, $seasonOne, 2);

    (new ProbeStreams(playlistId: $this->playlist->id, isSeriesProbe: true))->handle();

    expect($s1e2->fresh()->stream_stats_inferred_from_id)->toBeNull();

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->episodeIds === [$s1e1->id]
            && $job->episodeSiblings[$s1e1->id] === [$s1e2->id],
        ProbeStreamsComplete::class,
    ]);
});

it('skips a chunk entirely once its run breaker has tripped', function () {
    Cache::put('probe-run:tripped-run:tripped', true);
    $channel = probeVod($this->playlist, ['url' => 'http://provider.test/movie-ok.mkv']);

    (new ProbeStreamsChunk(
        channelIds: [$channel->id],
        probeRunKey: 'tripped-run',
        failureThreshold: 80,
    ))->handle();

    expect($channel->fresh()->stream_stats_probed_at)->toBeNull();
});

// ── Playlist form ──────────────────────────────────────────────────────────

it('saves the probe retry, breaker and series scope settings from the playlist form', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'sync_interval' => '0 0 * * *',
            'auto_probe_vod_streams' => true,
            'auto_probe_series_scope' => SeriesProbeScope::Season->value,
            'auto_probe_vod_streams_retry_failed_days' => 14,
            'auto_probe_vod_streams_failure_threshold' => 50,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $playlist = $this->playlist->fresh();
    expect($playlist->auto_probe_series_scope)->toBe(SeriesProbeScope::Season)
        ->and($playlist->auto_probe_vod_streams_retry_failed_days)->toBe(14)
        ->and($playlist->auto_probe_vod_streams_failure_threshold)->toBe(50);
});

it('rejects fractional probe settings', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'sync_interval' => '0 0 * * *',
            'auto_probe_vod_streams' => true,
            'auto_probe_vod_streams_only_unprobed' => true,
            'auto_probe_vod_streams_retry_failed_days' => 7.5,
            'auto_probe_vod_streams_failure_threshold' => 50.5,
            'probe_timeout' => 12.5,
        ])
        ->call('save')
        ->assertHasFormErrors([
            'auto_probe_vod_streams_retry_failed_days' => 'integer',
            'auto_probe_vod_streams_failure_threshold' => 'integer',
            'probe_timeout' => 'integer',
        ]);
});

it('only shows the retry window when incremental vod probing is on', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'auto_probe_vod_streams' => true,
            'auto_probe_vod_streams_only_unprobed' => false,
        ])
        ->assertFormFieldIsHidden('auto_probe_vod_streams_retry_failed_days')
        ->fillForm(['auto_probe_vod_streams_only_unprobed' => true])
        ->assertFormFieldVisible('auto_probe_vod_streams_retry_failed_days');
});
