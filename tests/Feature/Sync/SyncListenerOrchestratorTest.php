<?php

/**
 * Tests for SyncListener -> SyncOrchestrator wiring.
 *
 * Asserts the listener:
 *  - Opens a SyncRun row for each playlist sync event.
 *  - Picks the full PlaylistPostSyncPlan when status is Completed.
 *  - Picks the post-process-only plan when status is not Completed.
 *  - Threads the latest sync status into the orchestrator context.
 */

use App\Enums\Status;
use App\Enums\SyncRunStatus;
use App\Events\SyncCompleted;
use App\Listeners\SyncListener;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Sync\Phases\PostProcessPhase;
use App\Sync\Plans\PlaylistPostSyncPlan;
use App\Sync\SyncOrchestrator;
use App\Sync\SyncPlan;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    config(['cache.default' => 'array']);
    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
        'find_replace_rules' => null,
        'sort_alpha_config' => null,
        'auto_merge_channels_enabled' => false,
    ]);
});

it('opens a SyncRun ledger row for every playlist sync event', function () {
    expect(SyncRun::query()->count())->toBe(0);

    (new SyncListener)->handle(new SyncCompleted($this->playlist->fresh()));

    $run = SyncRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->playlist_id)->toBe($this->playlist->id)
        ->and($run->user_id)->toBe($this->user->id)
        ->and($run->kind)->toBe('post_sync')
        ->and($run->trigger)->toBe('sync_completed')
        ->and($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->meta)->toMatchArray(['playlist_status' => Status::Completed->value]);
});

it('hands the full post-sync plan to the orchestrator when status is Completed', function () {
    $captured = null;

    $this->mock(SyncOrchestrator::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function (SyncRun $run, SyncPlan $plan, array $context) use (&$captured) {
                $captured = ['plan' => $plan, 'context' => $context, 'run' => $run];

                return $run;
            });
    });

    (new SyncListener)->handle(new SyncCompleted($this->playlist->fresh()));

    expect($captured)->not->toBeNull()
        ->and($captured['plan']->name)->toBe('playlist.post_sync')
        ->and($captured['context'])->toHaveKey('last_sync')
        ->and($captured['run']->playlist_id)->toBe($this->playlist->id);
});

it('hands the post-process-only plan when status is not Completed', function () {
    $this->playlist->update(['status' => Status::Failed]);

    $captured = null;

    $this->mock(SyncOrchestrator::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function (SyncRun $run, SyncPlan $plan, array $context) use (&$captured) {
                $captured = ['plan' => $plan];

                return $run;
            });
    });

    (new SyncListener)->handle(new SyncCompleted($this->playlist->fresh()));

    expect($captured['plan']->name)->toBe('playlist.post_sync.post_process_only');
});

it('builds a post-process-only plan that contains only the PostProcessPhase', function () {
    $plan = PlaylistPostSyncPlan::buildPostProcessOnly();
    $steps = $plan->steps();

    expect($steps)->toHaveCount(1)
        ->and($steps[0]->phaseClass)->toBe(PostProcessPhase::class)
        ->and($steps[0]->required)->toBeFalse();
});

it('stores import_duration_seconds in the post-sync SyncRun meta from playlist sync_time', function () {
    $this->playlist->update(['sync_time' => 13.0]);

    (new SyncListener)->handle(new SyncCompleted($this->playlist->fresh()));

    $run = SyncRun::query()->latest('id')->first();

    expect($run->meta)
        ->toMatchArray([
            'playlist_status' => Status::Completed->value,
            'import_duration_seconds' => 13.0,
        ]);
});

it('stores null import_duration_seconds in meta when playlist sync_time is not set', function () {
    $this->playlist->update(['sync_time' => null]);

    (new SyncListener)->handle(new SyncCompleted($this->playlist->fresh()));

    $run = SyncRun::query()->latest('id')->first();

    expect($run->meta)->toMatchArray(['import_duration_seconds' => null]);
});
