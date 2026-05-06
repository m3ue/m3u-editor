<?php

/**
 * Phase abstraction tests for Step 3 of the sync pipeline refactor.
 *
 * Covers:
 *   - AbstractPhase happy path: markPhaseStarted -> execute -> markPhaseCompleted
 *   - AbstractPhase failure path: exceptions trigger markPhaseFailed and rethrow
 *   - shouldRun gating (verified per concrete phase below)
 *   - Each post-sync phase dispatches the expected jobs (and skips when
 *     it shouldn't run).
 */

use App\Enums\SyncPhaseStatus;
use App\Jobs\AutoSyncGroupsToCustomPlaylist;
use App\Jobs\MergeChannels;
use App\Jobs\ProbeChannelStreams;
use App\Jobs\ProcessChannelScrubber;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\RunPlaylistSortAlpha;
use App\Jobs\RunPostProcess;
use App\Jobs\SyncPlexDvrJob;
use App\Models\ChannelScrubber;
use App\Models\Playlist;
use App\Models\PostProcess;
use App\Models\SyncRun;
use App\Models\User;
use App\Plugins\PluginHookDispatcher;
use App\Sync\Phases\AbstractPhase;
use App\Sync\Phases\AutoSyncToCustomPhase;
use App\Sync\Phases\ChannelScanPhase;
use App\Sync\Phases\FindReplaceAndSortAlphaPhase;
use App\Sync\Phases\PlexDvrSyncPhase;
use App\Sync\Phases\PluginDispatchPhase;
use App\Sync\Phases\PostProcessPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->run = SyncRun::openFor($this->playlist);
});

// -----------------------------------------------------------------------------
// AbstractPhase
// -----------------------------------------------------------------------------

it('marks phase started then completed on successful execute', function () {
    $phase = new class extends AbstractPhase
    {
        public static function slug(): string
        {
            return 'test_ok';
        }

        protected function execute($run, $playlist, $context): ?array
        {
            return ['ran' => true];
        }
    };

    $result = $phase->run($this->run, $this->playlist);

    expect($result)->toBe(['ran' => true]);
    $fresh = $this->run->fresh();
    expect($fresh->phaseStatus('test_ok'))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phases['test_ok']['started_at'])->not->toBeNull();
    expect($fresh->phases['test_ok']['finished_at'])->not->toBeNull();
});

it('marks phase failed and rethrows when execute throws', function () {
    $phase = new class extends AbstractPhase
    {
        public static function slug(): string
        {
            return 'test_boom';
        }

        protected function execute($run, $playlist, $context): ?array
        {
            throw new RuntimeException('boom');
        }
    };

    expect(fn () => $phase->run($this->run, $this->playlist))
        ->toThrow(RuntimeException::class, 'boom');

    $fresh = $this->run->fresh();
    expect($fresh->phaseStatus('test_boom'))->toBe(SyncPhaseStatus::Failed);
    expect($fresh->phases['test_boom']['error'])->toBe('boom');
    expect($fresh->errors)->toHaveCount(1);
    expect($fresh->errors[0]['phase'])->toBe('test_boom');
});

// -----------------------------------------------------------------------------
// FindReplaceAndSortAlphaPhase
// -----------------------------------------------------------------------------

it('skips F/R+sort phase when neither configured', function () {
    $phase = app(FindReplaceAndSortAlphaPhase::class);
    expect($phase->shouldRun($this->playlist))->toBeFalse();
});

it('chains F/R then sort_alpha when both enabled', function () {
    $this->playlist->update([
        'find_replace_rules' => [['enabled' => true, 'find' => 'a', 'replace' => 'b']],
        'sort_alpha_config' => [['enabled' => true]],
    ]);

    app(FindReplaceAndSortAlphaPhase::class)->run($this->run, $this->playlist->fresh());

    Bus::assertChained([RunPlaylistFindReplaceRules::class, RunPlaylistSortAlpha::class]);
});

it('dispatches sort_alpha alone when F/R not configured', function () {
    $this->playlist->update([
        'sort_alpha_config' => [['enabled' => true]],
    ]);

    app(FindReplaceAndSortAlphaPhase::class)->run($this->run, $this->playlist->fresh());

    Bus::assertDispatched(RunPlaylistSortAlpha::class);
    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
});

it('consumes F/R skip marker and only dispatches sort_alpha', function () {
    $this->playlist->update([
        'find_replace_rules' => [['enabled' => true, 'find' => 'a', 'replace' => 'b']],
        'sort_alpha_config' => [['enabled' => true]],
    ]);
    Cache::put(RunPlaylistFindReplaceRules::ranMarkerKey($this->playlist), 1, 60);

    app(FindReplaceAndSortAlphaPhase::class)->run($this->run, $this->playlist->fresh());

    Bus::assertDispatched(RunPlaylistSortAlpha::class);
    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
    expect(Cache::has(RunPlaylistFindReplaceRules::ranMarkerKey($this->playlist)))->toBeFalse();
});

// -----------------------------------------------------------------------------
// ChannelScanPhase
// -----------------------------------------------------------------------------

it('skips channel scan when nothing enabled', function () {
    expect(app(ChannelScanPhase::class)->shouldRun($this->playlist))->toBeFalse();
});

it('chains merge -> scrubbers -> probe in order', function () {
    $this->playlist->update([
        'auto_merge_channels_enabled' => true,
        'auto_merge_config' => ['preferred_playlist_id' => $this->playlist->id],
        'auto_probe_streams' => true,
    ]);
    ChannelScrubber::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'recurring' => true,
    ]);

    app(ChannelScanPhase::class)->run($this->run, $this->playlist->fresh());

    Bus::assertChained([
        MergeChannels::class,
        ProcessChannelScrubber::class,
        ProbeChannelStreams::class,
    ]);
});

// -----------------------------------------------------------------------------
// AutoSyncToCustomPhase
// -----------------------------------------------------------------------------

it('skips auto-sync phase when no enabled rules', function () {
    expect(app(AutoSyncToCustomPhase::class)->shouldRun($this->playlist))->toBeFalse();
});

it('dispatches one job per enabled auto-sync rule', function () {
    $this->playlist->update([
        'auto_sync_to_custom_config' => [
            ['enabled' => true, 'custom_playlist_id' => 99, 'groups' => [1, 2], 'type' => 'groups'],
            ['enabled' => false, 'custom_playlist_id' => 100, 'groups' => [3], 'type' => 'groups'],
            ['enabled' => true, 'custom_playlist_id' => 101, 'groups' => [4], 'type' => 'series_categories'],
        ],
    ]);

    $result = app(AutoSyncToCustomPhase::class)->run($this->run, $this->playlist->fresh());

    expect($result['auto_sync_rules_dispatched'])->toBe(2);
    Bus::assertDispatchedTimes(AutoSyncGroupsToCustomPlaylist::class, 2);
});

it('skips auto-sync rules missing required fields', function () {
    $this->playlist->update([
        'auto_sync_to_custom_config' => [
            ['enabled' => true, 'custom_playlist_id' => 0, 'groups' => [1], 'type' => 'groups'],
            ['enabled' => true, 'custom_playlist_id' => 99, 'groups' => [], 'type' => 'groups'],
        ],
    ]);

    app(AutoSyncToCustomPhase::class)->run($this->run, $this->playlist->fresh());

    Bus::assertNotDispatched(AutoSyncGroupsToCustomPlaylist::class);
});

// -----------------------------------------------------------------------------
// PlexDvrSyncPhase
// -----------------------------------------------------------------------------

it('always dispatches Plex DVR sync', function () {
    app(PlexDvrSyncPhase::class)->run($this->run, $this->playlist);

    Bus::assertDispatched(SyncPlexDvrJob::class);
});

// -----------------------------------------------------------------------------
// PostProcessPhase
// -----------------------------------------------------------------------------

it('skips post-process phase when no enabled synced post-processes', function () {
    expect(app(PostProcessPhase::class)->shouldRun($this->playlist))->toBeFalse();
});

it('dispatches RunPostProcess for each enabled synced post-process', function () {
    $enabled = PostProcess::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'event' => 'synced',
        'enabled' => true,
    ]);
    $disabled = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => 'synced',
        'enabled' => false,
    ]);

    $this->playlist->postProcesses()->attach(
        $enabled->pluck('id')->concat([$disabled->id])->all(),
    );

    $result = app(PostProcessPhase::class)->run($this->run, $this->playlist->fresh());

    expect($result['post_processes_dispatched'])->toBe(2);
    Bus::assertDispatchedTimes(RunPostProcess::class, 2);
});

// -----------------------------------------------------------------------------
// PluginDispatchPhase
// -----------------------------------------------------------------------------

it('invokes the plugin hook dispatcher with playlist context', function () {
    $this->mock(PluginHookDispatcher::class, function (MockInterface $mock) {
        $mock->shouldReceive('dispatch')
            ->once()
            ->with('playlist.synced',
                ['playlist_id' => $this->playlist->id, 'user_id' => $this->user->id],
                ['dry_run' => false, 'user_id' => $this->user->id],
            );
    });

    app(PluginDispatchPhase::class)->run($this->run, $this->playlist);

    expect($this->run->fresh()->phaseStatus(PluginDispatchPhase::slug()))
        ->toBe(SyncPhaseStatus::Completed);
});
