<?php

use App\Enums\Status;
use App\Jobs\MergeChannels;
use App\Jobs\ProcessChannelScrubberComplete;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Bus::fake();
    Notification::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->createQuietly([
        'auto_merge_channels_enabled' => false,
    ]));

    $this->scrubber = function (array $attributes = []): ChannelScrubber {
        return ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'name' => 'Post Scan Scrubber',
            'uuid' => '00000000-0000-4000-8000-000000000101',
            'status' => Status::Processing,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'channel_count' => 12,
            'dead_count' => 2,
            'progress' => 95,
            'processing' => true,
            ...$attributes,
        ]));
    };

    $this->logFor = function (ChannelScrubber $scrubber): ChannelScrubberLog {
        return ChannelScrubberLog::create([
            'channel_scrubber_id' => $scrubber->id,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'status' => 'processing',
            'channel_count' => 12,
        ]);
    };
});

it('does not dispatch native merge after scan when the scrubber setting is off', function () {
    $this->playlist->update(['auto_merge_channels_enabled' => true]);
    $scrubber = ($this->scrubber)(['rebuild_failovers_after_scan' => false]);
    $log = ($this->logFor)($scrubber);

    (new ProcessChannelScrubberComplete(
        scrubberId: $scrubber->id,
        logId: $log->id,
        batchNo: $scrubber->uuid,
        start: now()->subSeconds(3),
    ))->handle();

    Bus::assertNotDispatched(MergeChannels::class);
    expect($scrubber->fresh()->status)->toBe(Status::Completed);
    expect($log->fresh()->status)->toBe('completed');
});

it('does not dispatch native merge after scan when playlist auto merge is disabled', function () {
    $scrubber = ($this->scrubber)(['rebuild_failovers_after_scan' => true]);
    $log = ($this->logFor)($scrubber);

    (new ProcessChannelScrubberComplete(
        scrubberId: $scrubber->id,
        logId: $log->id,
        batchNo: $scrubber->uuid,
        start: now()->subSeconds(3),
    ))->handle();

    Bus::assertNotDispatched(MergeChannels::class);
    expect($scrubber->fresh()->status)->toBe(Status::Completed);
});

it('dispatches native merge after a successful scan when enabled', function () {
    $this->playlist->update([
        'auto_merge_channels_enabled' => true,
        'auto_merge_config' => [
            'merge_key' => 'stream_id',
        ],
    ]);
    $scrubber = ($this->scrubber)(['rebuild_failovers_after_scan' => true]);
    $log = ($this->logFor)($scrubber);

    (new ProcessChannelScrubberComplete(
        scrubberId: $scrubber->id,
        logId: $log->id,
        batchNo: $scrubber->uuid,
        start: now()->subSeconds(3),
    ))->handle();

    Bus::assertDispatched(MergeChannels::class, function (MergeChannels $job): bool {
        return $job->playlistId === $this->playlist->id
            && $job->mergeKey === 'stream_id'
            && $job->newChannelsOnly === false
            && $job->forceCompleteRemerge === true;
    });
    expect($scrubber->fresh()->status)->toBe(Status::Completed);
    expect($scrubber->fresh()->processing)->toBeFalse();
});

it('does not dispatch native merge when the scrubber completion is stale', function () {
    $this->playlist->update(['auto_merge_channels_enabled' => true]);
    $scrubber = ($this->scrubber)(['rebuild_failovers_after_scan' => true]);
    $log = ($this->logFor)($scrubber);

    (new ProcessChannelScrubberComplete(
        scrubberId: $scrubber->id,
        logId: $log->id,
        batchNo: 'stale-batch',
        start: now()->subSeconds(3),
    ))->handle();

    Bus::assertNotDispatched(MergeChannels::class);
    expect($log->fresh()->status)->toBe('cancelled');
});

it('does not dispatch native merge when the scrubber was cancelled', function () {
    $this->playlist->update(['auto_merge_channels_enabled' => true]);
    $scrubber = ($this->scrubber)([
        'status' => Status::Cancelled,
        'rebuild_failovers_after_scan' => true,
    ]);
    $log = ($this->logFor)($scrubber);

    (new ProcessChannelScrubberComplete(
        scrubberId: $scrubber->id,
        logId: $log->id,
        batchNo: $scrubber->uuid,
        start: now()->subSeconds(3),
    ))->handle();

    Bus::assertNotDispatched(MergeChannels::class);
    expect($log->fresh()->status)->toBe('cancelled');
});
