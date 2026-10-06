<?php

use App\Models\ArrCacheMovie;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Services\ArrCacheCleanupService;
use App\Services\CachedContentDispatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

function accRadarr(Playlist $playlist): ArrIntegration
{
    return ArrIntegration::factory()->radarr()->cacheEnabled()->create([
        'user_id' => $playlist->user_id,
        'cache_cleanup' => true,
        'url' => 'http://radarr.test',
        'quality_profile_id' => 1,
        'root_folder_path' => '/media',
    ]);
}

function accChannel(Playlist $playlist, int $tmdbId): Channel
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

/**
 * A VOD dynamic group (rule + row) holding `$members`, appended to the
 * playlist's rules.
 *
 * @param  array<int, Channel>  $members
 * @param  array<string, mixed>  $rule
 */
function accGroup(Playlist $playlist, string $name, array $members, array $rule = []): DynamicGroup
{
    $playlist->refresh()->update(['dynamic_groups_config' => [
        ...($playlist->dynamic_groups_config ?? []),
        [
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => $name,
            'tmdb_params' => [],
            'cache_enabled' => true,
            'cache_keep_days' => 0,
            ...$rule,
        ],
    ]]);

    $group = DynamicGroup::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => $name,
    ]);

    foreach ($members as $position => $member) {
        $group->channels()->attach($member->id, ['position' => $position]);
    }

    return $group->fresh();
}

/**
 * Track Radarr movie 7 (TMDB 550) for the integration.
 *
 * @param  array<string, mixed>  $attributes
 */
function accTracked(ArrIntegration $radarr, array $attributes = []): ArrCacheMovie
{
    return ArrCacheMovie::factory()->create([
        'arr_integration_id' => $radarr->id,
        'tmdb_id' => 550,
        'arr_movie_id' => 7,
        ...$attributes,
    ]);
}

/**
 * Fake a Radarr library of `$movies` (library id => tmdb id). Any other
 * movie is gone (404); deletes succeed.
 *
 * @param  array<int, int>  $movies
 */
function accFakeRadarr(array $movies): void
{
    $responses = [];
    foreach ($movies as $movieId => $tmdbId) {
        $responses["radarr.test/api/v3/movie/{$movieId}*"] = Http::response([
            'id' => $movieId,
            'tmdbId' => $tmdbId,
            'title' => "Movie {$tmdbId}",
        ]);
    }

    Http::fake([...$responses, 'radarr.test/*' => Http::response([], 404)]);
}

function accDeletes(): int
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE')->count();
}

function accSweep(bool $dryRun = false): array
{
    return app(ArrCacheCleanupService::class)->sweep($dryRun);
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

    $this->playlist = Playlist::factory()->create(['prefer_media_server_sources' => true]);
    $this->radarr = accRadarr($this->playlist);
});

// --- Tracking ---

/**
 * Fake a Radarr that doesn't have TMDB 550 yet and adds it as movie 7.
 */
function accFakeRadarrAdd(): void
{
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response([['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club', 'images' => []]]),
        'radarr.test/api/v3/movie' => Http::response(['id' => 7]),
    ]);
}

it('records a movie auto-cache adds to a Radarr with cleanup on', function () {
    $group = accGroup($this->playlist, 'Trending', [accChannel($this->playlist, 550)]);
    accFakeRadarrAdd();

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    $tracked = ArrCacheMovie::query()->sole();
    expect($tracked->arr_integration_id)->toBe($this->radarr->id)
        ->and($tracked->tmdb_id)->toBe(550)
        ->and($tracked->arr_movie_id)->toBe(7)
        ->and($tracked->left_at)->toBeNull();
});

it('does not record movies while cleanup is off, or from Cache Now', function () {
    $this->radarr->update(['cache_cleanup' => false]);
    $group = accGroup($this->playlist, 'Trending', [accChannel($this->playlist, 550)]);
    accFakeRadarrAdd();

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);
    $this->radarr->update(['cache_cleanup' => true]);
    app(CachedContentDispatchService::class)->dispatch(accChannel($this->playlist, 550));

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(2)
        ->and(ArrCacheMovie::query()->count())->toBe(0);
});

it('does not record a movie Radarr already has', function () {
    $group = accGroup($this->playlist, 'Trending', [accChannel($this->playlist, 550)]);
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response([['id' => 7, 'tmdbId' => 550, 'title' => 'Fight Club', 'hasFile' => true]]),
    ]);

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    expect(ArrCacheMovie::query()->count())->toBe(0);
});

it('never tracks movies a Never expire rule caches', function () {
    $group = accGroup($this->playlist, 'Trending', [accChannel($this->playlist, 550)], ['cache_never_expire' => true]);
    accFakeRadarrAdd();

    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    expect(ArrCacheMovie::query()->count())->toBe(0);
});

it('stops tracking a movie on Cache Now so cleanup keeps it', function () {
    accTracked($this->radarr);
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response([['id' => 7, 'tmdbId' => 550, 'title' => 'Fight Club', 'hasFile' => true]]),
    ]);

    app(CachedContentDispatchService::class)->dispatch(accChannel($this->playlist, 550));

    expect(ArrCacheMovie::query()->count())->toBe(0);
});

// --- Cleanup ---

it('adds a movie, then removes it once it has been out of every group for the keep days', function () {
    $channel = accChannel($this->playlist, 550);
    $group = accGroup($this->playlist, 'Trending', [$channel], ['cache_keep_days' => 2]);
    Http::fake([
        'radarr.test/api/v3/movie/lookup*' => Http::response([['tmdbId' => 550, 'title' => 'Fight Club', 'titleSlug' => 'fight-club', 'images' => []]]),
        'radarr.test/api/v3/movie/42*' => Http::response(['id' => 42, 'tmdbId' => 550, 'title' => 'Fight Club']),
        'radarr.test/api/v3/movie' => Http::response(['id' => 42]),
    ]);

    // A group refresh sends the movie to Radarr.
    app(CachedContentDispatchService::class)->dispatchForDynamicGroup($group);

    // Still in the group: the nightly run keeps it.
    $this->artisan('cache:cleanup')->assertSuccessful();
    expect(ArrCacheMovie::query()->sole()->left_at)->toBeNull();

    // A later refresh drops it from the group.
    $group->channels()->detach($channel->id);
    $this->travel(1)->days();
    $this->artisan('cache:cleanup')->assertSuccessful();
    $this->travel(1)->days();
    $this->artisan('cache:cleanup')->assertSuccessful();
    expect(accDeletes())->toBe(0);

    $this->travel(25)->hours();
    $this->artisan('cache:cleanup')
        ->expectsOutputToContain('Removed 1 dynamic-group movies from Radarr.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://radarr.test/api/v3/movie/42?deleteFiles=true&addImportExclusion=false');
    expect(accDeletes())->toBe(1)
        ->and(ArrCacheMovie::query()->count())->toBe(0);
});

it('removes a tracked movie once it has been out of every group for the longest keep days', function () {
    accGroup($this->playlist, 'Trending', [accChannel($this->playlist, 999)], ['cache_keep_days' => 3]);
    accGroup($this->playlist, 'Popular', [], ['cache_keep_days' => 5]);
    accGroup($this->playlist, 'Favorites', [], ['cache_never_expire' => true, 'cache_keep_days' => 30]);
    $tracked = accTracked($this->radarr);
    accFakeRadarr([7 => 550]);

    // First run records when it left; nothing is removed yet.
    expect(accSweep())->toBe([])
        ->and($tracked->fresh()->left_at)->not->toBeNull();

    $this->travel(4)->days();
    expect(accSweep())->toBe([])
        ->and(accDeletes())->toBe(0);

    $this->travel(2)->days();
    expect(accSweep())->toBe([[
        'integration' => $this->radarr->name,
        'movie_id' => 7,
        'tmdb_id' => 550,
        'title' => 'Movie 550',
    ]])->and($tracked->fresh())->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://radarr.test/api/v3/movie/7?deleteFiles=true&addImportExclusion=false');
});

it('waits at least a day even when keep days is 0', function () {
    accGroup($this->playlist, 'Trending', []);
    accTracked($this->radarr);
    accFakeRadarr([7 => 550]);

    accSweep();
    $this->travel(12)->hours();
    accSweep();
    expect(accDeletes())->toBe(0);

    $this->travel(13)->hours();
    accSweep();
    expect(accDeletes())->toBe(1);
});

it('keeps a movie a cache group still holds', function () {
    accGroup($this->playlist, 'Popular', [accChannel($this->playlist, 550)]);
    $tracked = accTracked($this->radarr, ['left_at' => now()->subDays(30)]);
    Http::fake();

    accSweep();

    Http::assertNothingSent();
    expect($tracked->fresh()->left_at)->toBeNull();
});

it('stops tracking a movie that is gone from Radarr or whose id was reused', function () {
    accGroup($this->playlist, 'Trending', []);
    accTracked($this->radarr, ['left_at' => now()->subDays(30)]);
    accTracked($this->radarr, ['tmdb_id' => 551, 'arr_movie_id' => 8, 'left_at' => now()->subDays(30)]);
    // Movie 7 is gone; id 8 now belongs to a different movie.
    accFakeRadarr([8 => 999]);

    expect(accSweep())->toBe([])
        ->and(accDeletes())->toBe(0)
        ->and(ArrCacheMovie::query()->count())->toBe(0);
});

it('does nothing while cleanup or Use for caching is off', function (array $integration) {
    $this->radarr->update($integration);
    accGroup($this->playlist, 'Trending', []);
    $tracked = accTracked($this->radarr, ['left_at' => now()->subDays(30)]);
    Http::fake();

    accSweep();

    Http::assertNothingSent();
    expect($tracked->fresh())->not->toBeNull();
})->with([
    'cleanup off' => [['cache_cleanup' => false]],
    'Use for caching off' => [['cache_enabled' => false]],
]);

it('changes nothing when Radarr is unreachable', function () {
    accGroup($this->playlist, 'Trending', []);
    $tracked = accTracked($this->radarr, ['left_at' => now()->subDays(30)]);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(accSweep())->toBe([])
        ->and(accDeletes())->toBe(0)
        ->and($tracked->fresh())->not->toBeNull();
});

it('keeps tracking a movie Radarr fails to delete so the next run retries', function () {
    accGroup($this->playlist, 'Trending', []);
    $tracked = accTracked($this->radarr, ['left_at' => now()->subDays(30)]);
    Http::fake(fn (Request $request) => $request->method() === 'DELETE'
        ? Http::response(['message' => 'Database locked'], 500)
        : Http::response(['id' => 7, 'tmdbId' => 550, 'title' => 'Fight Club']));

    expect(accSweep())->toBe([])
        ->and($tracked->fresh())->not->toBeNull();
});

it('lists movies on a dry run without removing or recording anything', function () {
    accGroup($this->playlist, 'Trending', []);
    accTracked($this->radarr, ['left_at' => now()->subDays(30)]);
    $justLeft = accTracked($this->radarr, ['tmdb_id' => 551, 'arr_movie_id' => 8]);
    accFakeRadarr([7 => 550, 8 => 551]);

    $this->artisan('cache:cleanup', ['--dry-run' => true])
        ->expectsOutputToContain('Movie 550 (TMDB 550)')
        ->expectsOutputToContain('[DRY RUN] Would remove 1 dynamic-group movies from Radarr.')
        ->assertSuccessful();

    expect(accDeletes())->toBe(0)
        ->and(ArrCacheMovie::query()->count())->toBe(2)
        ->and($justLeft->fresh()->left_at)->toBeNull();
});
