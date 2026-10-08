<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CacheDispatchResult;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Jobs\DownloadCachedContentFile;
use App\Jobs\RequestArrEpisode;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function cvaPlaylist(bool $preferMediaServer = true): Playlist
{
    return Playlist::factory()->create(['prefer_media_server_sources' => $preferMediaServer]);
}

/**
 * A caching Radarr/Sonarr owned by the playlist's user.
 *
 * @param  array<string, mixed>  $attributes
 */
function cvaArr(Playlist $playlist, string $type, array $attributes = []): ArrIntegration
{
    return ArrIntegration::factory()->{$type}()->cacheEnabled()->create([
        'user_id' => $playlist->user_id,
        'url' => "http://{$type}.test",
        'quality_profile_id' => 1,
        'root_folder_path' => '/media',
        ...$attributes,
    ]);
}

function cvaChannel(Playlist $playlist, int $tmdbId = 550): Channel
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

function cvaSeries(Playlist $playlist): Series
{
    return Series::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'enabled' => true,
        'tvdb_id' => 81189,
    ]);
}

function cvaEpisode(Series $series, int $season, int $episodeNum): Episode
{
    return Episode::factory()->create([
        'user_id' => $series->user_id,
        'playlist_id' => $series->playlist_id,
        'series_id' => $series->id,
        'enabled' => true,
        'season' => $season,
        'episode_num' => $episodeNum,
        'url' => "https://provider.example.com/{$series->id}/s{$season}e{$episodeNum}.mkv",
    ]);
}

/**
 * Radarr /movie/lookup result. `$libraryId` set means it's in the library.
 *
 * @return array<int, array<string, mixed>>
 */
function cvaMovieLookup(int $tmdbId = 550, ?int $libraryId = null, bool $hasFile = false): array
{
    return [array_filter([
        'tmdbId' => $tmdbId,
        'title' => 'Fight Club',
        'titleSlug' => 'fight-club-'.$tmdbId,
        'images' => [],
        'id' => $libraryId,
        'hasFile' => $hasFile,
    ], fn ($value): bool => $value !== null)];
}

/**
 * Sonarr /series/lookup result for TVDB 81189 with seasons 0-2.
 *
 * @return array<int, array<string, mixed>>
 */
function cvaSeriesLookup(?int $libraryId = null): array
{
    return [array_filter([
        'tvdbId' => 81189,
        'title' => 'Breaking Bad',
        'titleSlug' => 'breaking-bad',
        'seasons' => [
            ['seasonNumber' => 0, 'monitored' => false],
            ['seasonNumber' => 1, 'monitored' => false],
            ['seasonNumber' => 2, 'monitored' => false],
        ],
        'id' => $libraryId,
    ], fn ($value): bool => $value !== null)];
}

function cvaSentTo(string $method, string $path): int
{
    return Http::recorded(fn (Request $request): bool => $request->method() === $method
        && str_contains($request->url(), '/api/v3'.$path))->count();
}

function cvaService(): CachedContentDispatchService
{
    return app(CachedContentDispatchService::class);
}

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    Http::preventStrayRequests();
    Sleep::fake();

    $settings = new GeneralSettings;
    $settings->enable_cache = true;
    $settings->tmdb_api_key = 'fake-api-key';
    app()->instance(GeneralSettings::class, $settings);
});

// --- Radarr ---

it('sends a movie Radarr does not have to Radarr instead of the provider', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup()),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);

    expect(cvaService()->dispatch(cvaChannel($playlist)))->toBe(CacheDispatchResult::SentToArr)
        ->and(CachedContentFile::query()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['tmdbId'] === 550
        && $request['qualityProfileId'] === 1
        && $request['rootFolderPath'] === '/media'
        && $request['addOptions']['searchForMovie'] === true);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('counts a movie Radarr already has a file for as cached', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    Http::fake(['radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup(libraryId: 7, hasFile: true))]);

    expect(cvaService()->dispatch(cvaChannel($playlist)))->toBe(CacheDispatchResult::InArrLibrary)
        ->and(CachedContentFile::query()->count())->toBe(0)
        ->and(cvaSentTo('POST', '/movie'))->toBe(0);
});

it('downloads from the provider on Cache Now when Radarr has the movie without a file', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    Http::fake(['radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup(libraryId: 7))]);

    expect(cvaService()->dispatch(cvaChannel($playlist)))->toBe(CacheDispatchResult::Queued)
        ->and(cvaSentTo('POST', '/movie'))->toBe(0);
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('leaves a movie Radarr already has to Radarr on auto-cache', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    Http::fake(['radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup(libraryId: 7))]);

    expect(cvaService()->dispatch(cvaChannel($playlist), automatic: true))->toBe(CacheDispatchResult::InArrLibrary)
        ->and(CachedContentFile::query()->count())->toBe(0);
});

it('falls back to the provider when Radarr rejects the add', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup()),
        'radarr.test/api/v3/movie' => Http::response(['message' => 'Root folder missing'], 400),
    ]);

    expect(cvaService()->dispatch(cvaChannel($playlist)))->toBe(CacheDispatchResult::Queued);
});

it('counts a rejected add as sent when another run just added the movie', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::sequence()
            ->push(cvaMovieLookup())
            ->push(cvaMovieLookup(libraryId: 7)),
        'radarr.test/api/v3/movie' => Http::response(['message' => 'This movie has already been added'], 400),
    ]);

    expect(cvaService()->dispatch(cvaChannel($playlist), automatic: true))->toBe(CacheDispatchResult::SentToArr)
        ->and(CachedContentFile::query()->count())->toBe(0);
});

it('falls back to the provider when Radarr is unreachable and stops asking it for the rest of the run', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    $lookedUp = [];
    Http::fake(function (Request $request) use (&$lookedUp) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $lookedUp[] = $query['term'] ?? null;

        throw new ConnectionException('connection refused');
    });

    $counts = cvaService()->dispatchMany([cvaChannel($playlist, 550), cvaChannel($playlist, 551)]);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(2)
        ->and($lookedUp)->toContain('tmdb:550')
        ->and($lookedUp)->not->toContain('tmdb:551');
});

it('routes to Radarr when the channel\'s playlist was loaded without the routing columns', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup()),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);

    // The VOD table eager loads the playlist with a narrow select that
    // leaves out prefer_media_server_sources.
    $channel = Channel::query()
        ->with(['playlist' => fn ($query) => $query->select('id', 'name', 'uuid', 'auto_sort', 'enable_proxy', 'enable_logo_proxy', 'user_id')])
        ->findOrFail(cvaChannel($playlist)->id);

    expect(cvaService()->dispatch($channel))->toBe(CacheDispatchResult::SentToArr)
        ->and(CachedContentFile::query()->count())->toBe(0);
});

it('sends Cache Now from the VOD list to Radarr', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    $channel = cvaChannel($playlist);
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup()),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);

    $this->actingAs($playlist->user);

    Livewire::test(ListVod::class)
        ->callAction(TestAction::make('cache_now')->table($channel));

    expect(cvaSentTo('POST', '/movie'))->toBe(1)
        ->and(CachedContentFile::query()->count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('uses the provider on playlists that do not prefer media server sources', function () {
    $playlist = cvaPlaylist(preferMediaServer: false);
    cvaArr($playlist, 'radarr');

    expect(cvaService()->dispatch(cvaChannel($playlist)))->toBe(CacheDispatchResult::Queued);
    Http::assertNothingSent();
});

it('ignores integrations without Use for caching and other users\' integrations', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr', ['cache_enabled' => false]);
    cvaArr($playlist, 'radarr', ['user_id' => User::factory()->create()->id]);

    expect(cvaService()->dispatch(cvaChannel($playlist)))->toBe(CacheDispatchResult::Queued);
    Http::assertNothingSent();
});

it('keeps cache:content on the provider', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    cvaChannel($playlist);

    $this->artisan('cache:content', ['--playlist' => $playlist->id])->assertSuccessful();

    expect(CachedContentFile::query()->count())->toBe(1);
    Http::assertNothingSent();
});

// --- Sonarr ---

it('asks Sonarr for just the episode on Cache Now when it does not have the series', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'sonarr');
    Http::fake([
        'sonarr.test/api/v3/series/lookup*' => Http::response(cvaSeriesLookup()),
        'sonarr.test/api/v3/series' => Http::response(['id' => 9]),
    ]);

    $episode = cvaEpisode(cvaSeries($playlist), 2, 3);

    expect(cvaService()->dispatch($episode))->toBe(CacheDispatchResult::SentToArr)
        ->and(CachedContentFile::query()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && collect($request['seasons'])->every(fn (array $season): bool => $season['monitored'] === false));
    Bus::assertDispatched(RequestArrEpisode::class, fn (RequestArrEpisode $job): bool => $job->sonarrSeriesId === 9
        && $job->seasonNumber === 2
        && $job->episodeNumber === 3);
});

it('uses the Sonarr file or the provider for episodes of a series Sonarr already has', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'sonarr');
    Http::fake([
        'sonarr.test/api/v3/series/lookup*' => Http::response(cvaSeriesLookup(libraryId: 9)),
        'sonarr.test/api/v3/episode*' => Http::response([
            ['seasonNumber' => 1, 'episodeNumber' => 1, 'hasFile' => true],
            ['seasonNumber' => 1, 'episodeNumber' => 2, 'hasFile' => false],
        ]),
    ]);

    $series = cvaSeries($playlist);
    $service = cvaService();

    expect($service->dispatch(cvaEpisode($series, 1, 1)))->toBe(CacheDispatchResult::InArrLibrary)
        ->and($service->dispatch(cvaEpisode($series, 1, 2)))->toBe(CacheDispatchResult::Queued)
        ->and(cvaSentTo('GET', '/series/lookup'))->toBe(1)
        ->and(cvaSentTo('GET', '/episode'))->toBe(1)
        ->and(cvaSentTo('POST', '/series'))->toBe(0);
});

it('adds the whole series to Sonarr on Cache all episodes', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'sonarr');
    Http::fake([
        'sonarr.test/api/v3/series/lookup*' => Http::response(cvaSeriesLookup()),
        'sonarr.test/api/v3/series' => Http::response(['id' => 9]),
    ]);

    $series = cvaSeries($playlist);
    cvaEpisode($series, 1, 1);
    cvaEpisode($series, 1, 2);
    cvaEpisode($series, 2, 1);

    $counts = cvaService()->dispatchSeries($series);

    expect($counts[CacheDispatchResult::SentToArr->value])->toBe(3)
        ->and(CachedContentFile::query()->count())->toBe(0)
        ->and(cvaSentTo('GET', '/series/lookup'))->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['addOptions']['searchForMissingEpisodes'] === true
        && collect($request['seasons'])->pluck('monitored', 'seasonNumber')->all() === [0 => false, 1 => true, 2 => true]);
});

it('caches the missing episodes from the provider on Cache all episodes when Sonarr already has the series', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'sonarr');
    Http::fake([
        'sonarr.test/api/v3/series/lookup*' => Http::response(cvaSeriesLookup(libraryId: 9)),
        'sonarr.test/api/v3/episode*' => Http::response([
            ['seasonNumber' => 1, 'episodeNumber' => 1, 'hasFile' => true],
        ]),
    ]);

    $series = cvaSeries($playlist);
    cvaEpisode($series, 1, 1);
    cvaEpisode($series, 1, 2);

    $counts = cvaService()->dispatchSeries($series);

    expect($counts[CacheDispatchResult::InArrLibrary->value])->toBe(1)
        ->and($counts[CacheDispatchResult::Queued->value])->toBe(1)
        ->and(cvaSentTo('GET', '/series/lookup'))->toBe(1)
        ->and(cvaSentTo('POST', '/series'))->toBe(0);
});

// --- Dynamic groups ---

/**
 * A dynamic group with caching on and `$member` attached.
 */
function cvaGroup(Playlist $playlist, Channel|Series $member): DynamicGroup
{
    $type = $member instanceof Channel ? 'vod' : 'series';
    $playlist->update(['dynamic_groups_config' => [[
        'enabled' => true,
        'type' => $type,
        'source' => 'trending',
        'name' => 'Trending',
        'tmdb_params' => [],
        'cache_enabled' => true,
    ]]]);

    $group = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => $type,
        'source' => 'trending',
        'name' => 'Trending',
    ]);
    $group->{$member instanceof Channel ? 'channels' : 'series'}()->attach($member->id, ['position' => 0]);

    return $group->fresh();
}

it('sends dynamic group movies to Radarr', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup()),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);

    $counts = cvaService()->dispatchForDynamicGroup(cvaGroup($playlist, cvaChannel($playlist)));

    expect($counts[CacheDispatchResult::SentToArr->value])->toBe(1)
        ->and(CachedContentFile::query()->count())->toBe(0);
});

it('adds a dynamic group series to Sonarr with only its latest season monitored', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'sonarr');
    Http::fake([
        'sonarr.test/api/v3/series/lookup*' => Http::response(cvaSeriesLookup()),
        'sonarr.test/api/v3/series' => Http::response(['id' => 9]),
    ]);

    $series = cvaSeries($playlist);
    cvaEpisode($series, 1, 1);
    cvaEpisode($series, 2, 1);
    cvaEpisode($series, 2, 2);

    $counts = cvaService()->dispatchForDynamicGroup(cvaGroup($playlist, $series));

    expect($counts[CacheDispatchResult::SentToArr->value])->toBe(2)
        ->and(CachedContentFile::query()->count())->toBe(0)
        ->and(cvaSentTo('GET', '/series/lookup'))->toBe(1)
        ->and(cvaSentTo('POST', '/series'))->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && collect($request['seasons'])->pluck('monitored', 'seasonNumber')->all() === [0 => false, 1 => false, 2 => true]);
});

it('leaves a dynamic group series Sonarr already has to Sonarr', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'sonarr');
    Http::fake(['sonarr.test/api/v3/series/lookup*' => Http::response(cvaSeriesLookup(libraryId: 9))]);

    $series = cvaSeries($playlist);
    cvaEpisode($series, 1, 1);
    cvaEpisode($series, 1, 2);

    $counts = cvaService()->dispatchForDynamicGroup(cvaGroup($playlist, $series));

    expect($counts[CacheDispatchResult::InArrLibrary->value])->toBe(2)
        ->and(CachedContentFile::query()->count())->toBe(0)
        ->and(cvaSentTo('GET', '/episode'))->toBe(0);
});

// --- Failback tracking rows ---

it('tracks a movie sent to Radarr on integrations with failback on', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr', ['cache_failback' => true]);
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup()),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);

    $channel = cvaChannel($playlist);

    expect(cvaService()->dispatch($channel))->toBe(CacheDispatchResult::SentToArr);

    $row = CachedContentFile::query()->sole();

    expect($row->source)->toBe('arr')
        ->and($row->arr_integration_id)->not->toBeNull()
        ->and($row->arr_requested_at)->not->toBeNull()
        ->and($row->status)->toBe(CachedContentFileStatus::Pending);
});

it('falls back to the provider on Cache Now for a title waiting on the arr', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr', ['cache_failback' => true]);
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup()),
        'radarr.test/api/v3/movie?tmdbId=550' => Http::response([['id' => 7, 'tmdbId' => 550, 'hasFile' => false]]),
        'radarr.test/api/v3/movie/7' => Http::response(['id' => 7, 'monitored' => true]),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);

    $channel = cvaChannel($playlist);

    expect(cvaService()->dispatch($channel, automatic: true))->toBe(CacheDispatchResult::SentToArr)
        // Auto-cache leaves it to the arr.
        ->and(cvaService()->dispatch($channel, automatic: true))->toBe(CacheDispatchResult::AlreadyQueued);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);

    expect(cvaService()->dispatch($channel))->toBe(CacheDispatchResult::Queued)
        ->and(CachedContentFile::query()->sole()->source)->toBe('provider')
        ->and(cvaSentTo('PUT', '/movie/7'))->toBe(1);
    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('does not track arr requests without the failback flag', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr');
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup()),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);

    expect(cvaService()->dispatch(cvaChannel($playlist)))->toBe(CacheDispatchResult::SentToArr)
        ->and(CachedContentFile::query()->count())->toBe(0);
});

it('does not track titles already in the arr library', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'radarr', ['cache_failback' => true]);
    Http::fake(['radarr.test/api/v3/movie/lookup*' => Http::response(cvaMovieLookup(libraryId: 7))]);

    expect(cvaService()->dispatch(cvaChannel($playlist), automatic: true))->toBe(CacheDispatchResult::InArrLibrary)
        ->and(CachedContentFile::query()->count())->toBe(0);
});

it('tracks single episodes sent to Sonarr but not whole-series requests', function () {
    $playlist = cvaPlaylist();
    cvaArr($playlist, 'sonarr', ['cache_failback' => true]);
    Http::fake([
        'sonarr.test/api/v3/series/lookup*' => Http::response(cvaSeriesLookup()),
        'sonarr.test/api/v3/series' => Http::response(['id' => 9]),
    ]);

    $series = cvaSeries($playlist);

    expect(cvaService()->dispatch(cvaEpisode($series, 2, 3)))->toBe(CacheDispatchResult::SentToArr)
        ->and(CachedContentFile::query()->where('source', 'arr')->count())->toBe(1);

    cvaEpisode($series, 1, 1);
    cvaEpisode($series, 2, 1);

    $counts = cvaService()->dispatchSeries($series);

    expect($counts[CacheDispatchResult::SentToArr->value])->toBe(3)
        // Only the single-episode row from the first dispatch exists.
        ->and(CachedContentFile::query()->where('source', 'arr')->count())->toBe(1);
});

// --- Notifications ---

it('names the arr in the Cache Now notification', function () {
    $playlist = cvaPlaylist();
    $channel = cvaChannel($playlist);
    $episode = cvaEpisode(cvaSeries($playlist), 1, 1);

    expect(CachedContentDispatchService::cacheNowNotification($channel, CacheDispatchResult::SentToArr)->getTitle())->toBe('Sent to Radarr')
        ->and(CachedContentDispatchService::cacheNowNotification($episode, CacheDispatchResult::InArrLibrary)->getTitle())->toBe('Already in Sonarr');
});

it('counts sent titles in the summary notification', function () {
    $counts = array_fill_keys(array_map(fn (CacheDispatchResult $result): string => $result->value, CacheDispatchResult::cases()), 0);
    $counts[CacheDispatchResult::SentToArr->value] = 3;
    $counts[CacheDispatchResult::InArrLibrary->value] = 1;

    $notification = CachedContentDispatchService::summaryNotification($counts);

    expect($notification->getTitle())->toBe('Queued 3 episodes for caching')
        ->and($notification->getBody())->toBe('3 sent to Sonarr, 1 already cached or queued, 0 without a cacheable source.');
});
