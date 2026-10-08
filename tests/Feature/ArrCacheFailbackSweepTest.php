<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\CachedContentFiles\CachedContentFileResource;
use App\Jobs\DownloadCachedContentFile;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\ArrCacheFailbackService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/**
 * Helpers are prefixed acf* (arr cache failback); CacheViaArrTest owns the
 * cva* ones, so the suites stay independently runnable.
 */
function acfPlaylist(): Playlist
{
    return Playlist::factory()->create(['prefer_media_server_sources' => true]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function acfArr(Playlist $playlist, string $type, array $attributes = []): ArrIntegration
{
    return ArrIntegration::factory()->{$type}()->cacheEnabled()->cacheFailback()->create([
        'user_id' => $playlist->user_id,
        'url' => "http://{$type}.test",
        ...$attributes,
    ]);
}

function acfChannel(Playlist $playlist, int $tmdbId = 550): Channel
{
    return Channel::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => $tmdbId,
        'url' => "https://provider.example.com/movie/{$tmdbId}.mkv",
    ]);
}

function acfEpisode(Playlist $playlist, int $season = 2, int $episodeNum = 3, ?Series $series = null): Episode
{
    $series ??= Series::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'enabled' => true,
        'tvdb_id' => 81189,
    ]);

    return Episode::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'enabled' => true,
        'season' => $season,
        'episode_num' => $episodeNum,
        'url' => "https://provider.example.com/{$series->id}/s{$season}e{$episodeNum}.mkv",
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function acfRow(Channel|Episode $item, ?ArrIntegration $arr, array $overrides = []): CachedContentFile
{
    return CachedContentFile::factory()->forItem($item)->create([
        ...CachedContentFile::buildAttributes($item),
        'status' => CachedContentFileStatus::Pending,
        'source' => 'arr',
        'arr_integration_id' => $arr?->id,
        'arr_requested_at' => now()->subMinutes(5),
        ...$overrides,
    ]);
}

function acfService(): ArrCacheFailbackService
{
    return app(ArrCacheFailbackService::class);
}

/**
 * @return array<string, mixed>
 */
function acfMovieQueue(string $status, string $state, int $tmdbId = 550): array
{
    return ['status' => $status, 'trackedDownloadState' => $state, 'movie' => ['tmdbId' => $tmdbId, 'title' => 'Fight Club']];
}

/**
 * Radarr fake: status, queue, library lookup (/movie?tmdbId=) and the
 * unmonitor GET/PUT on /movie/{id}. `$library` maps TMDB id => hasFile.
 *
 * @param  array<int, array<string, mixed>>  $queue
 * @param  array<int, bool>  $library
 */
function acfRadarrFake(array $queue = [], array $library = [550 => false], bool $healthy = true): void
{
    Http::fake(function (Request $request) use ($queue, $library, $healthy) {
        $url = $request->url();

        if (str_contains($url, '/system/status')) {
            return $healthy ? Http::response(['version' => '5.0']) : Http::response(['error' => 'boom'], 500);
        }

        if (str_contains($url, '/queue')) {
            return Http::response(['records' => $queue]);
        }

        if (preg_match('#/movie/(\d+)$#', $url, $match)) {
            return Http::response(['id' => (int) $match[1], 'monitored' => true]);
        }

        if (preg_match('#/movie\?tmdbId=(\d+)#', $url, $match)) {
            $tmdbId = (int) $match[1];

            return Http::response(array_key_exists($tmdbId, $library)
                ? [['id' => $tmdbId + 1000, 'tmdbId' => $tmdbId, 'hasFile' => $library[$tmdbId]]]
                : []);
        }

        throw new ConnectionException('unexpected arr request: '.$url);
    });
}

/**
 * Sonarr fake: status, queue, series lookup (series 9), episodes and the
 * episode monitor PUT. `$episodes` maps "season:episode" => hasFile.
 *
 * @param  array<int, array<string, mixed>>  $queue
 * @param  array<string, bool>  $episodes
 */
function acfSonarrFake(array $queue = [], array $episodes = ['2:3' => false]): void
{
    Http::fake(function (Request $request) use ($queue, $episodes) {
        $url = $request->url();

        if (str_contains($url, '/system/status')) {
            return Http::response(['version' => '4.0']);
        }

        // /queue before /episode: the queue URL's includeEpisode=true would
        // otherwise match the episodes branch.
        if (str_contains($url, '/queue')) {
            return Http::response(['records' => $queue]);
        }

        if (str_contains($url, '/episode/monitor')) {
            return Http::response([]);
        }

        if (str_contains($url, '/episode')) {
            return Http::response(collect($episodes)->map(function (bool $hasFile, string $key): array {
                [$season, $episode] = array_map('intval', explode(':', $key));

                return ['id' => $season * 100 + $episode, 'seasonNumber' => $season, 'episodeNumber' => $episode, 'hasFile' => $hasFile];
            })->values()->all());
        }

        if (str_contains($url, '/series/lookup')) {
            return Http::response([['id' => 9, 'tvdbId' => 81189]]);
        }

        throw new ConnectionException('unexpected arr request: '.$url);
    });
}

function acfSent(string $method, string $pattern): int
{
    return Http::recorded(fn (Request $request): bool => $request->method() === $method
        && preg_match($pattern, $request->url()) === 1)->count();
}

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    Http::preventStrayRequests();
    Sleep::fake();
});

// --- Movies (Radarr) ---

it('falls back on a failed download and unmonitors the movie', function () {
    $playlist = acfPlaylist();
    $row = acfRow(acfChannel($playlist), acfArr($playlist, 'radarr'));

    acfRadarrFake(queue: [acfMovieQueue('failed', 'failedPending')]);

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('provider')
        ->and($row->status)->toBe(CachedContentFileStatus::Pending);
    Bus::assertDispatched(DownloadCachedContentFile::class, fn (DownloadCachedContentFile $job): bool => $job->cachedContentFileId === $row->id);

    // Unmonitor, never delete.
    expect(acfSent('PUT', '#/movie/1550$#'))->toBe(1)
        ->and(acfSent('DELETE', '#.#'))->toBe(0);
});

it('falls back once the deadline passes without a download', function () {
    $playlist = acfPlaylist();
    $fresh = acfRow(acfChannel($playlist, 550), $arr = acfArr($playlist, 'radarr'));
    $late = acfRow(acfChannel($playlist, 551), $arr, ['arr_requested_at' => now()->subHours(25)]);

    acfRadarrFake(library: [550 => false, 551 => false]);

    acfService()->sweep();

    expect($fresh->refresh()->source)->toBe('arr')
        ->and($late->refresh()->source)->toBe('provider');
    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 1);
});

it('falls back when the movie is no longer in the arr after the deadline', function () {
    $playlist = acfPlaylist();
    $row = acfRow(acfChannel($playlist), acfArr($playlist, 'radarr'), ['arr_requested_at' => now()->subHours(25)]);

    acfRadarrFake(library: []);

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('provider');
});

it('waits on an active download however long it takes', function () {
    $playlist = acfPlaylist();
    $row = acfRow(acfChannel($playlist), acfArr($playlist, 'radarr'), ['arr_requested_at' => now()->subHours(30)]);

    acfRadarrFake(queue: [acfMovieQueue('warning', 'downloading')]);

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('arr');
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('waits on a stuck import until the deadline, then falls back', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $fresh = acfRow(acfChannel($playlist, 550), $arr);
    $late = acfRow(acfChannel($playlist, 551), $arr, ['arr_requested_at' => now()->subHours(25)]);

    acfRadarrFake(queue: [
        acfMovieQueue('completed', 'importBlocked', 550),
        acfMovieQueue('completed', 'importBlocked', 551),
    ], library: [550 => false, 551 => false]);

    acfService()->sweep();

    expect($fresh->refresh()->source)->toBe('arr')
        ->and($late->refresh()->source)->toBe('provider');
});

it('drops the row once the arr has the file', function () {
    $playlist = acfPlaylist();
    acfRow(acfChannel($playlist), acfArr($playlist, 'radarr'), ['arr_requested_at' => now()->subHours(30)]);

    acfRadarrFake(library: [550 => true]);

    acfService()->sweep();

    expect(CachedContentFile::query()->count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

// --- Episodes (Sonarr) ---

it('falls back a failed episode and unmonitors just that episode', function () {
    $playlist = acfPlaylist();
    $row = acfRow(acfEpisode($playlist), acfArr($playlist, 'sonarr'));

    acfSonarrFake(queue: [[
        'status' => 'failed',
        'trackedDownloadState' => 'failedPending',
        'series' => ['tvdbId' => 81189, 'title' => 'Breaking Bad'],
        'episode' => ['seasonNumber' => 2, 'episodeNumber' => 3, 'title' => 'Bit by a Dead Bee'],
    ]]);

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('provider');
    Bus::assertDispatched(DownloadCachedContentFile::class);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/episode/monitor')
        && $request['episodeIds'] === [203])->count())->toBe(1);
});

it('judges each episode of a series against one episode list', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'sonarr');
    $delivered = acfEpisode($playlist, 2, 3);
    $missing = acfEpisode($playlist, 2, 4, $delivered->series);
    acfRow($delivered, $arr);
    $late = acfRow($missing, $arr, ['arr_requested_at' => now()->subHours(25)]);

    acfSonarrFake(episodes: ['2:3' => true, '2:4' => false]);

    acfService()->sweep();

    expect(CachedContentFile::query()->pluck('id')->all())->toBe([$late->id])
        ->and($late->refresh()->source)->toBe('provider')
        // One series lookup and one episode list for both rows, plus the
        // unmonitor's own lookups.
        ->and(acfSent('GET', '#/series/lookup#'))->toBe(2)
        ->and(acfSent('GET', '#/episode\?#'))->toBe(2);
});

// --- Guards ---

it('skips the sweep when the arr is unreachable', function () {
    $playlist = acfPlaylist();
    $row = acfRow(acfChannel($playlist), acfArr($playlist, 'radarr'), ['arr_requested_at' => now()->subHours(30)]);

    acfRadarrFake(healthy: false);

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('arr');
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('still queues the provider when the unmonitor call fails', function () {
    $playlist = acfPlaylist();
    $row = acfRow(acfChannel($playlist), acfArr($playlist, 'radarr'));

    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), '/system/status') => Http::response(['version' => '5.0']),
        str_contains($request->url(), '/queue') => Http::response(['records' => [acfMovieQueue('failed', 'failed')]]),
        default => throw new ConnectionException('radarr went away'),
    });

    acfService()->sweep();

    expect($row->refresh()->source)->toBe('provider');
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('never queues the provider twice for the same row', function () {
    $playlist = acfPlaylist();
    $row = acfRow(acfChannel($playlist), acfArr($playlist, 'radarr'));

    acfRadarrFake(queue: [acfMovieQueue('failed', 'failedPending')]);

    acfService()->sweep();
    acfService()->sweep();

    expect(acfService()->fallBack($row))->toBeFalse();
    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 1);
});

it('keeps sweeping when one row cannot be judged', function () {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    $bad = acfRow(acfChannel($playlist, 550), $arr, ['arr_requested_at' => now()->subHours(30)]);
    $good = acfRow(acfChannel($playlist, 551), $arr, ['arr_requested_at' => now()->subHours(30)]);

    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), '/system/status') => Http::response(['version' => '5.0']),
        str_contains($request->url(), '/queue') => Http::response(['records' => []]),
        str_contains($request->url(), 'tmdbId=550') => throw new ConnectionException('timeout'),
        str_contains($request->url(), 'tmdbId=551') => Http::response([['id' => 8, 'tmdbId' => 551, 'hasFile' => false]]),
        default => Http::response(['id' => 8]),
    });

    acfService()->sweep();

    expect($bad->refresh()->source)->toBe('arr')
        ->and($good->refresh()->source)->toBe('provider');
});

it('stops tracking rows whose integration no longer fails back', function (?array $changes) {
    $playlist = acfPlaylist();
    $arr = acfArr($playlist, 'radarr');
    acfRow(acfChannel($playlist), $arr, ['arr_requested_at' => now()->subHours(30)]);

    $changes === null ? $arr->delete() : $arr->update($changes);

    acfService()->sweep();

    expect(CachedContentFile::query()->count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
    Http::assertNothingSent();
})->with([
    'failback turned off' => [['cache_failback' => false]],
    'caching turned off' => [['cache_enabled' => false]],
    'integration disabled' => [['enabled' => false]],
    'integration deleted' => [null],
]);

it('unmonitors the title when an arr row is canceled', function () {
    $playlist = acfPlaylist();
    $row = acfRow(acfChannel($playlist), acfArr($playlist, 'radarr'));
    $this->actingAs($playlist->user);

    acfRadarrFake();

    CachedContentFileResource::deleteCachedFile($row);

    expect(CachedContentFile::query()->count())->toBe(0)
        ->and(acfSent('PUT', '#/movie/1550$#'))->toBe(1)
        ->and(acfSent('DELETE', '#.#'))->toBe(0);
});

it('does not serve an arr tracking row to playback', function () {
    $playlist = acfPlaylist();
    $channel = acfChannel($playlist);
    acfRow($channel, acfArr($playlist, 'radarr'));

    expect(CachedContentFile::findServableFor($channel))->toBeNull();
});

it('never lets the downloader claim an arr-tracked row', function () {
    $playlist = acfPlaylist();
    $row = acfRow(acfChannel($playlist), acfArr($playlist, 'radarr'));

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);

    Http::fake();
    (new DownloadCachedContentFile($row->id))->handle();

    expect($row->refresh()->status)->toBe(CachedContentFileStatus::Pending)
        ->and($row->source)->toBe('arr');
    Http::assertNothingSent();
});
