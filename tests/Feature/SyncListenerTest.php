<?php

/**
 * Tests for SyncListener job dispatch ordering.
 *
 * Verifies:
 *  - Group 1 (Name Processing): RunPlaylistFindReplaceRules runs before RunPlaylistSortAlpha
 *    when both are enabled, replacing + sorting in the correct sequence.
 *  - Group 2 (Channel Scan): MergeChannels runs before ProcessChannelScrubber(s) when
 *    both are enabled, ensuring scrubbers operate on the fully-merged channel list.
 *  - Jobs in each group are skipped correctly when disabled/absent.
 *  - Non-Completed playlist status prevents Group 1 and Group 2 from running.
 *  - EPG post-processing (status reset + GenerateEpgCache dispatch) only fires on success.
 */

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\GenerateEpgCache;
use App\Jobs\MergeChannels;
use App\Jobs\ProcessChannelScrubber;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\RunPlaylistSortAlpha;
use App\Jobs\SyncPlexDvrJob;
use App\Models\ChannelScrubber;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Bus::fake();
    Notification::fake();
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

// ──────────────────────────────────────────────────────────────────────────────
// Group 1: Name Processing Pipeline (find-replace → sort-alpha)
// ──────────────────────────────────────────────────────────────────────────────

it('chains find-replace before sort-alpha when both have enabled rules', function () {
    $this->playlist->update([
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '^US- ', 'replace_with' => '']],
        'sort_alpha_config' => [['enabled' => true, 'target' => 'live_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'ASC']],
    ]);

    event(new SyncCompleted($this->playlist));

    Bus::assertChained([
        RunPlaylistFindReplaceRules::class,
        RunPlaylistSortAlpha::class,
    ]);
});

it('dispatches only find-replace when sort-alpha has no enabled rules', function () {
    $this->playlist->update([
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '^US- ', 'replace_with' => '']],
        'sort_alpha_config' => [['enabled' => false, 'target' => 'live_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'ASC']],
    ]);

    event(new SyncCompleted($this->playlist));

    Bus::assertDispatched(RunPlaylistFindReplaceRules::class);
    Bus::assertNotDispatched(RunPlaylistSortAlpha::class);
});

it('dispatches only sort-alpha when find-replace has no enabled rules', function () {
    $this->playlist->update([
        'find_replace_rules' => [['enabled' => false, 'find_replace' => '^US- ', 'replace_with' => '']],
        'sort_alpha_config' => [['enabled' => true, 'target' => 'live_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'ASC']],
    ]);

    event(new SyncCompleted($this->playlist));

    Bus::assertDispatched(RunPlaylistSortAlpha::class);
    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
});

it('dispatches neither name processing job when both configs are null', function () {
    // find_replace_rules and sort_alpha_config are already null in beforeEach
    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
    Bus::assertNotDispatched(RunPlaylistSortAlpha::class);
});

it('dispatches neither name processing job when both configs contain only disabled rules', function () {
    $this->playlist->update([
        'find_replace_rules' => [['enabled' => false, 'find_replace' => '^US- ', 'replace_with' => '']],
        'sort_alpha_config' => [['enabled' => false, 'target' => 'live_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'ASC']],
    ]);

    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
    Bus::assertNotDispatched(RunPlaylistSortAlpha::class);
});

it('dispatches find-replace independently (not chained) when sort-alpha is absent', function () {
    $this->playlist->update([
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '^US- ', 'replace_with' => '']],
        'sort_alpha_config' => null,
    ]);

    event(new SyncCompleted($this->playlist));

    // A single-job dispatch, not a chain
    Bus::assertDispatched(RunPlaylistFindReplaceRules::class);
    Bus::assertNotDispatched(RunPlaylistSortAlpha::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// Group 2: Channel Scan Jobs (merge → scrubbers)
// ──────────────────────────────────────────────────────────────────────────────

it('chains merge job alone when auto-merge is enabled and no recurring scrubbers exist', function () {
    $this->playlist->update(['auto_merge_channels_enabled' => true]);

    event(new SyncCompleted($this->playlist));

    Bus::assertChained([MergeChannels::class]);
    Bus::assertNotDispatched(ProcessChannelScrubber::class);
});

it('chains merge job before scrubber when both are enabled', function () {
    $this->playlist->update(['auto_merge_channels_enabled' => true]);

    ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
        'name' => 'Recurring Scrubber',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'recurring' => true,
        'status' => 'pending',
        'check_method' => 'http',
    ]));

    event(new SyncCompleted($this->playlist));

    Bus::assertChained([MergeChannels::class, ProcessChannelScrubber::class]);
});

it('chains merge job before multiple scrubbers when both are enabled', function () {
    $this->playlist->update(['auto_merge_channels_enabled' => true]);

    foreach (['Scrubber A', 'Scrubber B'] as $name) {
        ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'name' => $name,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'recurring' => true,
            'status' => 'pending',
            'check_method' => 'http',
        ]));
    }

    event(new SyncCompleted($this->playlist));

    // Chain should be: MergeChannels → ProcessChannelScrubber × 2
    Bus::assertChained([MergeChannels::class, ProcessChannelScrubber::class, ProcessChannelScrubber::class]);
});

it('batches recurring scrubbers when auto-merge is disabled', function () {
    ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
        'name' => 'Recurring Scrubber',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'recurring' => true,
        'status' => 'pending',
        'check_method' => 'http',
    ]));

    event(new SyncCompleted($this->playlist));

    Bus::assertChained([ProcessChannelScrubber::class]);
    Bus::assertNotDispatched(MergeChannels::class);
});

it('batches all recurring scrubbers together when auto-merge is disabled', function () {
    foreach (['Scrubber X', 'Scrubber Y'] as $name) {
        ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'name' => $name,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'recurring' => true,
            'status' => 'pending',
            'check_method' => 'http',
        ]));
    }

    event(new SyncCompleted($this->playlist));

    Bus::assertChained([ProcessChannelScrubber::class, ProcessChannelScrubber::class]);
    Bus::assertNotDispatched(MergeChannels::class);
});

it('does not dispatch merge or scrubbers when both are disabled', function () {
    // auto_merge_channels_enabled is false in beforeEach, and no scrubbers exist

    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(MergeChannels::class);
    Bus::assertNotDispatched(ProcessChannelScrubber::class);
});

it('ignores non-recurring scrubbers', function () {
    ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
        'name' => 'Non-recurring Scrubber',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'recurring' => false,
        'status' => 'pending',
        'check_method' => 'http',
    ]));

    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(MergeChannels::class);
    Bus::assertNotDispatched(ProcessChannelScrubber::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// Playlist status guard
// ──────────────────────────────────────────────────────────────────────────────

it('skips all pipeline and scan jobs when playlist sync did not complete', function () {
    $this->playlist->update([
        'status' => Status::Failed,
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '^US- ', 'replace_with' => '']],
        'sort_alpha_config' => [['enabled' => true, 'target' => 'live_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'ASC']],
        'auto_merge_channels_enabled' => true,
    ]);

    ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
        'name' => 'Recurring Scrubber',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'recurring' => true,
        'status' => 'pending',
        'check_method' => 'http',
    ]));

    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
    Bus::assertNotDispatched(RunPlaylistSortAlpha::class);
    Bus::assertNotDispatched(MergeChannels::class);
    Bus::assertNotDispatched(ProcessChannelScrubber::class);
});

it('skips pipeline and scan jobs when playlist is still processing', function () {
    $this->playlist->update([
        'status' => Status::Processing,
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '^US- ', 'replace_with' => '']],
        'auto_merge_channels_enabled' => true,
    ]);

    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
    Bus::assertNotDispatched(MergeChannels::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// EPG: post-processing
// ──────────────────────────────────────────────────────────────────────────────

it('dispatches GenerateEpgCache after a successful EPG sync', function () {
    $uuid = (string) Str::orderedUuid();
    $epg = Epg::factory()->for($this->user)->createQuietly([
        'uuid' => $uuid,
        'status' => Status::Completed,
        'is_cached' => true,
        'cache_meta' => ['entries' => 100],
    ]);

    event(new SyncCompleted($epg));

    Bus::assertDispatched(
        GenerateEpgCache::class,
        fn ($job) => $job->uuid === $uuid && $job->notify === true
    );
});

it('does not dispatch GenerateEpgCache when EPG sync failed', function () {
    $epg = Epg::factory()->for($this->user)->createQuietly([
        'uuid' => (string) Str::orderedUuid(),
        'status' => Status::Failed,
    ]);

    event(new SyncCompleted($epg));

    Bus::assertNotDispatched(GenerateEpgCache::class);
});

it('does not dispatch GenerateEpgCache when EPG sync is still processing', function () {
    $epg = Epg::factory()->for($this->user)->createQuietly([
        'uuid' => (string) Str::orderedUuid(),
        'status' => Status::Processing,
    ]);

    event(new SyncCompleted($epg));

    Bus::assertNotDispatched(GenerateEpgCache::class);
});

it('resets EPG cache state to prevent stale reads before dispatching GenerateEpgCache', function () {
    $epg = Epg::factory()->for($this->user)->createQuietly([
        'uuid' => (string) Str::orderedUuid(),
        'status' => Status::Completed,
        'is_cached' => true,
        'cache_meta' => ['entries' => 100],
        'processing_started_at' => now(),
        'processing_phase' => 'channels',
    ]);

    event(new SyncCompleted($epg));

    $epg->refresh();

    expect($epg->status)->toBe(Status::Processing)
        ->and($epg->is_cached)->toBeFalse()
        ->and($epg->cache_meta)->toBeNull()
        ->and($epg->processing_started_at)->toBeNull()
        ->and($epg->processing_phase)->toBeNull();
});

// ──────────────────────────────────────────────────────────────────────────────
// Plex DVR Sync: event-driven dispatch
// ──────────────────────────────────────────────────────────────────────────────

it('dispatches SyncPlexDvrJob after a successful playlist sync', function () {
    event(new SyncCompleted($this->playlist));

    Bus::assertDispatched(
        SyncPlexDvrJob::class,
        fn (SyncPlexDvrJob $job): bool => $job->trigger === 'playlist_sync'
    );
});

it('does not dispatch SyncPlexDvrJob when playlist sync failed', function () {
    $this->playlist->update(['status' => Status::Failed]);

    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(SyncPlexDvrJob::class);
});

it('dispatches SyncPlexDvrJob after a successful EPG sync', function () {
    $epg = Epg::factory()->for($this->user)->createQuietly([
        'uuid' => (string) Str::orderedUuid(),
        'status' => Status::Completed,
    ]);

    event(new SyncCompleted($epg));

    Bus::assertDispatched(
        SyncPlexDvrJob::class,
        fn (SyncPlexDvrJob $job): bool => $job->trigger === 'epg_sync'
    );
});

it('does not dispatch SyncPlexDvrJob when EPG sync failed', function () {
    $epg = Epg::factory()->for($this->user)->createQuietly([
        'uuid' => (string) Str::orderedUuid(),
        'status' => Status::Failed,
    ]);

    event(new SyncCompleted($epg));

    Bus::assertNotDispatched(SyncPlexDvrJob::class);
});
