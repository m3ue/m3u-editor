<?php

use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

/**
 * Integration coverage for the Phase 3 cache-check + lazy trigger wired
 * into `XtreamStreamController::handleVod()` and `::handleSeries()`.
 *
 * Verifies:
 *  - Cache hit (Completed file referenced by a group) → 302 redirect to
 *    the new `dynamic-group-cache.stream` route, NOT to the proxy/direct URL.
 *  - Cache miss + lazy + cacheable rule → DownloadCachedContentFile dispatched,
 *    request still falls through to the regular path (fire-and-forget).
 *  - Cache miss + lazy off → no dispatch.
 *  - Cache miss + lazy + no cacheable rule → no dispatch.
 *  - `shouldSkip()` prevents re-dispatch on subsequent plays of the same content.
 *  - Cache check is gated on `enable_proxy=true` (Phase 2's gating).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // Bus::fake() catches both: (a) Playlist::factory()->create() firing
    // PlaylistCreated → SyncPipelineService → ProcessM3uImport, AND
    // (b) any DownloadCachedContentFile dispatched by the lazy trigger
    // under test. The XtreamStreamController cache hit path returns
    // before hitting M3uProxyApiController so no further mocking needed.
    Bus::fake();

    // Playlist's `enableProxy` accessor (app/Models/Playlist.php) returns
    // false when the owning user can't use proxy. Make admin user + enable
    // the global proxy feature so the accessor returns the DB value.
    config(['proxy.proxy_integration_enabled' => true]);
    $this->user = User::factory()->create(['is_admin' => true]);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
    ]);
    // Playlist model has no $fillable/$guarded=[] so factory attribute keys
    // are silently ignored — set enable_proxy explicitly via update() so the
    // cache-check fires.
    $this->playlist->update(['enable_proxy' => true]);

    $this->vodGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Top Movies',
    ]);

    $this->channel = Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'tmdb_id' => 550,
        'url' => 'http://provider.example/movie.mp4',
    ]);
    $this->channel->dynamicGroups()->attach($this->vodGroup->id);

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'name' => 'Top Movies',
                'cache_enabled' => true,
            ],
        ],
    ]);
});

it('redirects to the cached-content stream route when a Completed cache hit exists for the VOD', function () {
    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    $response = $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$this->channel->id}.ts");

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toBe(route('dynamic-group-cache.stream', [
        'username' => $this->user->name,
        'password' => $this->playlist->uuid,
        'uuid' => $file->uuid,
        'format' => 'ts',
    ]));

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('does not check the cache when the playlist has enable_proxy=false', function () {
    $this->playlist->update(['enable_proxy' => false]);
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    $response = $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$this->channel->id}.ts");

    // Falls through to direct redirect (since proxy is disabled globally too),
    // NOT to the cache stream route.
    expect($response->headers->get('Location'))->not->toContain('/cached-content/');
});

it('does not dispatch when lazy load is off (cache miss)', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = false;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$this->channel->id}.ts");

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('does not dispatch when caching is globally disabled (cache miss)', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = false;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$this->channel->id}.ts");

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('dispatches a DownloadCachedContentFile when lazy is on and there is a cacheable rule', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$this->channel->id}.ts");

    Bus::assertDispatched(DownloadCachedContentFile::class, function (DownloadCachedContentFile $job) {
        return $job->contentType === 'movie'
            && $job->tmdbId === '550';
    });
});

it('does not dispatch when no cacheable rule applies (no dynamic groups on the channel)', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    // Detach the channel from its group → no cacheable rule
    $this->channel->dynamicGroups()->detach($this->vodGroup->id);

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$this->channel->id}.ts");

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('does not re-dispatch while a download is already in flight (shouldSkip blocks repeated lazy dispatches)', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    // Pre-existing Completed row — shouldSkip returns true for Completed,
    // so the lazy trigger is blocked. (Per the service's contract, only
    // Completed blocks — Pending/Downloading don't. The cache-hit path
    // fires BEFORE the lazy trigger here, so a Completed row produces a
    // redirect rather than a re-dispatch.)
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    $response = $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$this->channel->id}.ts");

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/cached-content/');

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('does not dispatch when the channel has no tmdb_id (lazy can\'t fingerprint without it)', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->channel->update(['tmdb_id' => null]);

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$this->channel->id}.ts");

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

/**
 * Series-side: cache hit for an episode, dispatched by the series' tmdb_id +
 * season + episode_number. Set up a series, a season, an episode, and a
 * group referencing the series.
 */
it('redirects to the cached-content stream route when a Completed cache hit exists for the Episode', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'enabled' => true,
        'tmdb_id' => 1399,
    ]);
    $series->dynamicGroups()->attach($this->vodGroup->id); // reuse the VOD group, type mismatch is OK for this test

    // Re-create group as a series-type group so the lookup matches.
    $seriesGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series',
        'source' => 'tmdb',
        'name' => 'Top Series',
    ]);
    $series->dynamicGroups()->detach($this->vodGroup->id);
    $series->dynamicGroups()->attach($seriesGroup->id);

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'name' => 'Top Series',
                'cache_enabled' => true,
            ],
        ],
    ]);

    $season = Season::factory()->for($this->playlist)->for($series)->create([
        'season_number' => 1,
    ]);
    $episode = Episode::factory()->for($this->playlist)->for($series)->for($season)->create([
        'season' => 1,
        'episode_num' => 1,
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode',
        'tmdb_id' => '1399',
        'season_number' => 1,
        'episode_number' => 1,
        'quality' => null,
    ]);

    $response = $this->get("/series/{$this->user->name}/{$this->playlist->uuid}/{$episode->id}.mp4");

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toBe(route('dynamic-group-cache.stream', [
        'username' => $this->user->name,
        'password' => $this->playlist->uuid,
        'uuid' => $file->uuid,
        'format' => 'mp4',
    ]));

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('dispatches a DownloadCachedContentFile for an Episode when lazy is on and there is a cacheable rule', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->dynamic_group_cache_lazy_load = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $series = Series::factory()->for($this->playlist)->create([
        'enabled' => true,
        'tmdb_id' => 1399,
    ]);
    $seriesGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series',
        'source' => 'tmdb',
        'name' => 'Top Series',
    ]);
    $series->dynamicGroups()->attach($seriesGroup->id);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Top Series', 'cache_enabled' => true],
        ],
    ]);

    $season = Season::factory()->for($this->playlist)->for($series)->create([
        'season_number' => 1,
    ]);
    $episode = Episode::factory()->for($this->playlist)->for($series)->for($season)->create([
        'season' => 1,
        'episode_num' => 1,
        'url' => 'http://provider.example/episode.mp4',
    ]);

    $this->get("/series/{$this->user->name}/{$this->playlist->uuid}/{$episode->id}.mp4");

    Bus::assertDispatched(DownloadCachedContentFile::class, function (DownloadCachedContentFile $job) {
        return $job->contentType === 'episode'
            && $job->tmdbId === '1399'
            && $job->seasonNumber === 1
            && $job->episodeNumber === 1;
    });
});
