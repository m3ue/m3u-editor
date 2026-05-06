<?php

/**
 * Covers SyncPlan::stepSlugs() introspection helper and SyncPlanRegistry
 * resolution by SyncRun kind / meta.
 */

use App\Enums\Status;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Sync\Plans\PlaylistPostSyncPlan;
use App\Sync\Plans\PlaylistPreSyncPlan;
use App\Sync\SyncPlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('returns ordered step metadata from stepSlugs()', function () {
    $plan = PlaylistPreSyncPlan::build();
    $rows = $plan->stepSlugs();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row)->toHaveKeys(['slug', 'phase_class', 'required', 'parallel_group', 'chain_group']);
        expect($row['slug'])->toBeString()->not->toBeEmpty();
        expect(class_exists($row['phase_class']))->toBeTrue();
        expect($row['required'])->toBeBool();
    }

    $slugs = array_column($rows, 'slug');
    expect($slugs)->toBe(array_values($slugs)); // preserves order
});

it('returns the pre-sync plan for kind=sync runs', function () {
    $run = SyncRun::openFor($this->playlist, kind: 'sync');

    $plan = SyncPlanRegistry::for($run);

    expect($plan)->not->toBeNull();
    expect($plan->name)->toBe(PlaylistPreSyncPlan::build()->name);
});

it('returns the full post-sync plan when playlist completed', function () {
    $run = SyncRun::openFor($this->playlist, kind: 'post_sync', meta: [
        'playlist_status' => Status::Completed->value,
    ]);

    $plan = SyncPlanRegistry::for($run);

    expect($plan)->not->toBeNull();
    expect($plan->name)->toBe(PlaylistPostSyncPlan::build()->name);
    expect(count($plan->steps()))->toBe(count(PlaylistPostSyncPlan::build()->steps()));
});

it('falls back to post-process-only plan when playlist did not complete', function () {
    $run = SyncRun::openFor($this->playlist, kind: 'post_sync', meta: [
        'playlist_status' => Status::Failed->value,
    ]);

    $plan = SyncPlanRegistry::for($run);

    expect($plan)->not->toBeNull();
    expect(count($plan->steps()))->toBe(count(PlaylistPostSyncPlan::buildPostProcessOnly()->steps()));
});

it('returns null for unknown run kinds', function () {
    $run = SyncRun::openFor($this->playlist, kind: 'mystery');

    expect(SyncPlanRegistry::for($run))->toBeNull();
});
