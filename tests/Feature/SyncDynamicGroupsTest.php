<?php

use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Jobs\SyncDynamicGroups;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The PlaylistUpdated listener dispatches into the sync pipeline, which
    // talks to Redis. We don't want any of that during these unit tests.
    Bus::fake();

    $this->user = User::factory()->create();
    // createQuietly avoids the Process* listeners that would otherwise fan
    // out a real sync pipeline and bypass the test's fakes.
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
        'dynamic_groups_config' => null,
    ]);

    // Bind TmdbService via a singleton so the job's app() helper resolves
    // to a service that thinks it's configured. The constructor pulls from
    // GeneralSettings, so we set the api key on the singleton directly.
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    $settings->tmdb_confidence_threshold = 80;

    $this->tmdb = new TmdbService($settings);
    app()->instance(GeneralSettings::class, $settings);
    app()->instance(TmdbService::class, $this->tmdb);

    // TmdbService::waitForRateLimit() touches RateLimiter — fake it so the
    // tests don't need a live Redis connection.
    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);
});

// ──────────────────────────────────────────────────────────────────────────────
// Job: trending rule
// ──────────────────────────────────────────────────────────────────────────────

it('creates a DynamicGroup and attaches matching VOD channels for a trending rule', function () {
    Http::fake([
        'https://api.themoviedb.org/3/trending/movie/week*' => Http::response([
            'results' => [
                ['id' => 100, 'title' => 'Hot Movie', 'media_type' => 'movie', 'release_date' => '2024-01-01'],
                ['id' => 200, 'title' => 'Cold Movie', 'media_type' => 'movie', 'release_date' => '2024-02-02'],
            ],
        ], 200),
    ]);

    Channel::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => '100', // matches
    ]);
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => '999', // does not match
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [
            [
                'enabled' => true,
                'type' => 'vod',
                'source' => 'trending',
                'name' => 'Trending Now',
                'tmdb_params' => ['time_window' => 'week'],
            ],
        ],
    ]);

    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    $group = DynamicGroup::where('playlist_id', $this->playlist->id)->first();
    expect($group)->not->toBeNull()
        ->and($group->type)->toBe('vod')
        ->and($group->source)->toBe('trending')
        ->and($group->name)->toBe('Trending Now')
        ->and($group->sort_order)->toBe(0)
        ->and($group->last_synced_at)->not->toBeNull();

    // Only the two channels matching tmdb_id=100 should be attached.
    expect($group->channels()->count())->toBe(2);
});

// ──────────────────────────────────────────────────────────────────────────────
// Job: full-sync semantics (re-run drops stale rows)
// ──────────────────────────────────────────────────────────────────────────────

it('drops stale membership rows when faked results change on a re-run', function () {
    // First run: include id 100 only.
    Http::fake([
        'https://api.themoviedb.org/3/trending/movie/week*' => Http::sequence()
            ->push(['results' => [
                ['id' => 100, 'title' => 'Hot Movie', 'media_type' => 'movie', 'release_date' => '2024-01-01'],
            ]], 200)
            ->push(['results' => [
                ['id' => 200, 'title' => 'Cold Movie', 'media_type' => 'movie', 'release_date' => '2024-02-02'],
            ]], 200),
    ]);

    $chan100 = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => '100',
    ]);
    $chan200 = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => '200',
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Trending Now',
            'tmdb_params' => ['time_window' => 'week'],
        ]],
    ]);

    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    $group = DynamicGroup::where('playlist_id', $this->playlist->id)->first();
    expect($group->channels()->count())->toBe(1)
        ->and($group->channels()->first()->id)->toBe($chan100->id)
        ->and($group->last_synced_at)->not->toBeNull();

    // Second run: trending list now returns id 200 — chan100 must be detached.
    $firstSyncAt = $group->last_synced_at;
    sleep(1); // ensure timestamp bumps

    // TmdbService::getTrending() is cache-remembered (30 min TTL) — flush so
    // the second run actually hits the fake again with the new response.
    Cache::flush();

    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    $group->refresh();
    expect($group->channels()->count())->toBe(1)
        ->and($group->channels()->first()->id)->toBe($chan200->id)
        ->and($group->last_synced_at->gt($firstSyncAt))->toBeTrue();

    // Re-running the same config must not create a duplicate group row —
    // unique (playlist_id, type, source, name) constraint is the safety net.
    expect(DynamicGroup::where('playlist_id', $this->playlist->id)->count())->toBe(1);
});

// ──────────────────────────────────────────────────────────────────────────────
// Job: rule removed from config
// ──────────────────────────────────────────────────────────────────────────────

it('deletes the DynamicGroup row (and cascades its items) when the rule is removed from config', function () {
    Http::fake([
        'https://api.themoviedb.org/3/trending/movie/week*' => Http::response([
            'results' => [
                ['id' => 100, 'title' => 'Hot Movie', 'media_type' => 'movie', 'release_date' => '2024-01-01'],
            ],
        ], 200),
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => '100',
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Trending Now',
            'tmdb_params' => [],
        ]],
    ]);

    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();
    expect(DynamicGroup::where('playlist_id', $this->playlist->id)->count())->toBe(1);

    // Remove the rule and re-run.
    $this->playlist->update(['dynamic_groups_config' => []]);
    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    expect(DynamicGroup::where('playlist_id', $this->playlist->id)->count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// Job: TMDB unconfigured → still completes the pipeline phase
// ──────────────────────────────────────────────────────────────────────────────

it('completes the pipeline phase even when TMDB is unconfigured', function () {
    $unconfiguredSettings = new GeneralSettings;
    $unconfiguredSettings->tmdb_api_key = null;
    app()->instance(GeneralSettings::class, $unconfiguredSettings);
    app()->instance(TmdbService::class, new TmdbService($unconfiguredSettings));

    $syncRun = SyncRun::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'phases' => [SyncRunPhase::DynamicGroups->value],
        'phase_statuses' => (object) [],
        'status' => SyncRunStatus::Running->value,
        'current_phase' => SyncRunPhase::DynamicGroups->value,
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Trending Now',
            'tmdb_params' => [],
        ]],
    ]);

    $pipeline = Mockery::mock(SyncPipelineService::class)->makePartial();
    $pipeline->shouldReceive('completePhase')
        ->once()
        ->with($syncRun->id, SyncRunPhase::DynamicGroups);
    app()->instance(SyncPipelineService::class, $pipeline);

    (new SyncDynamicGroups(
        playlistId: $this->playlist->id,
        syncRunId: $syncRun->id,
        completionPhase: SyncRunPhase::DynamicGroups,
    ))->handle();

    // No groups should have been created.
    expect(DynamicGroup::where('playlist_id', $this->playlist->id)->count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// Job: series tmdb_network rule uses discoverTv with with_networks
// ──────────────────────────────────────────────────────────────────────────────

it('uses discoverTv with with_networks for series tmdb_network rules', function () {
    Http::fake([
        'https://api.themoviedb.org/3/discover/tv*' => Http::response([
            'results' => [
                ['id' => 500, 'name' => 'Netflix Show', 'first_air_date' => '2024-01-01'],
            ],
            'total_pages' => 1,
        ], 200),
    ]);

    Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
        'tmdb_id' => '500',
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'series',
            'source' => 'tmdb_network',
            'name' => 'Netflix Originals',
            'tmdb_params' => ['network_id' => 213], // Netflix
        ]],
    ]);

    // discoverTv() is cached (30 min) — flush to make the faked HTTP request
    // observable in the assertion.
    Cache::flush();

    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    Http::assertSent(function ($request) {
        $query = $request->data() ?? [];

        return str_starts_with($request->url(), 'https://api.themoviedb.org/3/discover/tv')
            && ($query['with_networks'] ?? null) == 213;
    });

    $group = DynamicGroup::where('playlist_id', $this->playlist->id)
        ->where('type', 'series')
        ->where('source', 'tmdb_network')
        ->first();
    expect($group)->not->toBeNull()
        ->and($group->series()->count())->toBe(1);
});

// ──────────────────────────────────────────────────────────────────────────────
// Job: TMDB transient [] keeps existing group + membership intact
// ──────────────────────────────────────────────────────────────────────────────

it('keeps the existing DynamicGroup and membership when TMDB transiently returns no results', function () {
    Http::fake([
        'https://api.themoviedb.org/3/trending/movie/week*' => Http::sequence()
            ->push(['results' => [
                ['id' => 100, 'title' => 'Hot Movie', 'media_type' => 'movie', 'release_date' => '2024-01-01'],
            ]], 200)
            // Second call — TMDB outage / transient error: service returns [].
            ->push(['results' => []], 200),
    ]);

    $chan = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'enabled' => true,
        'tmdb_id' => '100',
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Trending Now',
            'tmdb_params' => ['time_window' => 'week'],
        ]],
    ]);

    // First run: successful fetch creates the row + membership.
    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    $group = DynamicGroup::where('playlist_id', $this->playlist->id)->first();
    expect($group)->not->toBeNull();
    $firstId = $group->id;
    expect($group->channels()->count())->toBe(1);
    $firstSyncAt = $group->last_synced_at;

    sleep(1); // ensure timestamp would bump if a re-sync happens

    // Second run with TMDB returning []: the row + membership must survive,
    // last_synced_at must NOT bump (we didn't actually re-sync), and the
    // Xtream category_id must remain stable for client-side caching.
    Cache::flush();
    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    $group->refresh();
    expect($group->id)->toBe($firstId)
        ->and($group->channels()->count())->toBe(1)
        ->and($group->channels()->first()->id)->toBe($chan->id)
        ->and($group->last_synced_at->eq($firstSyncAt))->toBeTrue();
});
