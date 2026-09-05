<?php

use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Jobs\SyncDynamicGroups;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\DynamicGroupItemSnapshot;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
        'dynamic_groups_config' => null,
    ]);

    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    $settings->tmdb_confidence_threshold = 80;
    app()->instance(GeneralSettings::class, $settings);
    app()->instance(TmdbService::class, new TmdbService($settings));

    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);
});

// ──────────────────────────────────────────────────────────────────────────────
// Capture: pipeline (syncRunId set) writes snapshots; cron (syncRunId null) skips.
// ──────────────────────────────────────────────────────────────────────────────

it('captures a snapshot per item when the job is dispatched via the pipeline', function () {
    Http::fake([
        'https://api.themoviedb.org/3/trending/movie/week*' => Http::response([
            'results' => [
                ['id' => 100, 'title' => 'Hot Movie', 'media_type' => 'movie', 'release_date' => '2024-01-01'],
                ['id' => 200, 'title' => 'Cold Movie', 'media_type' => 'movie', 'release_date' => '2024-02-02'],
            ],
        ], 200),
    ]);

    $chanA = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true, 'enabled' => true, 'tmdb_id' => '100',
    ]);
    $chanB = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true, 'enabled' => true, 'tmdb_id' => '200',
    ]);

    $this->playlist->update(['dynamic_groups_config' => [[
        'enabled' => true, 'type' => 'vod', 'source' => 'trending',
        'name' => 'Trending Now', 'tmdb_params' => [],
    ]]]);

    $run = SyncRun::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'phases' => [SyncRunPhase::DynamicGroups->value],
        'status' => SyncRunStatus::Running->value,
    ]);

    (new SyncDynamicGroups(
        playlistId: $this->playlist->id,
        syncRunId: $run->id,
    ))->handle();

    $group = DynamicGroup::where('playlist_id', $this->playlist->id)->first();

    // Two snapshot rows: one per matched channel.
    expect(DynamicGroupItemSnapshot::where('dynamic_group_id', $group->id)
        ->where('sync_run_id', $run->id)
        ->count())->toBe(2)
        ->and(DynamicGroupItemSnapshot::where('dynamic_group_id', $group->id)
            ->where('sync_run_id', $run->id)
            ->pluck('item_id')->sort()->values()->all())
        ->toBe(collect([$chanA->id, $chanB->id])->sort()->values()->all());
});

it('does NOT capture a snapshot when the job runs without a syncRunId (cron path)', function () {
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
        'is_vod' => true, 'enabled' => true, 'tmdb_id' => '100',
    ]);

    $this->playlist->update(['dynamic_groups_config' => [[
        'enabled' => true, 'type' => 'vod', 'source' => 'trending',
        'name' => 'Trending Now', 'tmdb_params' => [],
    ]]]);

    // No syncRunId - this is the `app:refresh-dynamic-groups` cron shape.
    (new SyncDynamicGroups(playlistId: $this->playlist->id))->handle();

    $group = DynamicGroup::where('playlist_id', $this->playlist->id)->first();
    expect(DynamicGroupItemSnapshot::where('dynamic_group_id', $group->id)->count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// diffForRun — added/removed set computation.
// ──────────────────────────────────────────────────────────────────────────────

it('diffForRun reports added and removed items between two consecutive runs', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Diff Test',
    ]);

    // Run 1: items {1,2,3,4,5}
    $run1 = SyncRun::factory()->create(['playlist_id' => $this->playlist->id, 'user_id' => $this->user->id]);
    DynamicGroupItemSnapshot::insert([
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run1->id, 'item_type' => Channel::class, 'item_id' => 1, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run1->id, 'item_type' => Channel::class, 'item_id' => 2, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run1->id, 'item_type' => Channel::class, 'item_id' => 3, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run1->id, 'item_type' => Channel::class, 'item_id' => 4, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run1->id, 'item_type' => Channel::class, 'item_id' => 5, 'captured_at' => now()],
    ]);

    // Run 2: items {1,2,3,6,7} -- removed 4,5; added 6,7
    $run2 = SyncRun::factory()->create(['playlist_id' => $this->playlist->id, 'user_id' => $this->user->id]);
    DynamicGroupItemSnapshot::insert([
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run2->id, 'item_type' => Channel::class, 'item_id' => 1, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run2->id, 'item_type' => Channel::class, 'item_id' => 2, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run2->id, 'item_type' => Channel::class, 'item_id' => 3, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run2->id, 'item_type' => Channel::class, 'item_id' => 6, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run2->id, 'item_type' => Channel::class, 'item_id' => 7, 'captured_at' => now()],
    ]);

    $diff = DynamicGroupItemSnapshot::diffForRun($group->id, $run2->id);

    expect($diff['has_previous'])->toBeTrue()
        ->and($diff['added']->sort()->values()->all())->toBe([6, 7])
        ->and($diff['removed']->sort()->values()->all())->toBe([4, 5]);
});

it('diffForRun treats the first captured run as a baseline (everything added, nothing removed)', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Baseline Test',
    ]);

    $run = SyncRun::factory()->create(['playlist_id' => $this->playlist->id, 'user_id' => $this->user->id]);
    DynamicGroupItemSnapshot::insert([
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run->id, 'item_type' => Channel::class, 'item_id' => 10, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run->id, 'item_type' => Channel::class, 'item_id' => 20, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run->id, 'item_type' => Channel::class, 'item_id' => 30, 'captured_at' => now()],
    ]);

    $diff = DynamicGroupItemSnapshot::diffForRun($group->id, $run->id);

    expect($diff['has_previous'])->toBeFalse()
        ->and($diff['added']->sort()->values()->all())->toBe([10, 20, 30])
        ->and($diff['removed']->all())->toBe([]);
});

it('diffForRun returns empty sets when called with null current run id', function () {
    $diff = DynamicGroupItemSnapshot::diffForRun(123, null);

    expect($diff['added']->all())->toBe([])
        ->and($diff['removed']->all())->toBe([])
        ->and($diff['has_previous'])->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// Pruning — same 30-day policy as SyncRun and PlaylistSyncStatus.
// ──────────────────────────────────────────────────────────────────────────────

it('prunable() only targets rows older than 30 days', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Prune Test',
    ]);

    $fresh = DynamicGroupItemSnapshot::factory()->create([
        'dynamic_group_id' => $group->id,
        'captured_at' => now()->subDays(5),
    ]);
    $stale = DynamicGroupItemSnapshot::factory()->create([
        'dynamic_group_id' => $group->id,
        'captured_at' => now()->subDays(31),
    ]);

    $prunableIds = (new DynamicGroupItemSnapshot)->prunable()->pluck('id')->all();

    expect($prunableIds)->toContain($stale->id)
        ->and($prunableIds)->not->toContain($fresh->id);
});
