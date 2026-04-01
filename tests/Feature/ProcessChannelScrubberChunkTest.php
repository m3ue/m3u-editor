<?php

use App\Enums\Status;
use App\Jobs\ProcessChannelScrubberChunk;
use App\Models\Channel;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();

    $this->scrubber = ChannelScrubber::create([
        'name' => 'Test Scrubber',
        'uuid' => 'test-batch-uuid',
        'status' => Status::Processing,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'progress' => 0,
    ]);

    $this->log = ChannelScrubberLog::create([
        'channel_scrubber_id' => $this->scrubber->id,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'status' => 'processing',
    ]);
});

// ──────────────────────────────────────────────────────────────────────────────
// ffprobe check method — uses ensureStreamStats()
// ──────────────────────────────────────────────────────────────────────────────

it('marks a channel dead via ffprobe when ensureStreamStats returns empty', function () {
    // No URL and no stream_stats → ensureStreamStats() returns [] → dead
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => null,
        'url_custom' => null,
        'stream_stats' => null,
    ]);

    (new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
    ))->handle();

    $channel->refresh();
    expect($channel->enabled)->toBeFalse();

    $this->assertDatabaseHas('channel_scrubber_log_channels', [
        'channel_scrubber_log_id' => $this->log->id,
        'channel_id' => $channel->id,
    ]);
});

it('does not mark a channel dead via ffprobe when ensureStreamStats returns stats', function () {
    // Pre-populated stream_stats → ensureStreamStats() returns them immediately → alive
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]],
        ],
    ]);

    (new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
    ))->handle();

    $channel->refresh();
    expect($channel->enabled)->toBeTrue();

    $this->assertDatabaseMissing('channel_scrubber_log_channels', [
        'channel_scrubber_log_id' => $this->log->id,
        'channel_id' => $channel->id,
    ]);
});

it('increments dead_count on the scrubber for each dead channel', function () {
    $dead1 = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'url' => null,
        'url_custom' => null,
        'stream_stats' => null,
    ]);
    $dead2 = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'url' => null,
        'url_custom' => null,
        'stream_stats' => null,
    ]);

    (new ProcessChannelScrubberChunk(
        channelIds: [$dead1->id, $dead2->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: $this->scrubber->uuid,
        totalChannels: 2,
    ))->handle();

    expect($this->scrubber->fresh()->dead_count)->toBe(2);
});

// ──────────────────────────────────────────────────────────────────────────────
// Scrubber lifecycle guards
// ──────────────────────────────────────────────────────────────────────────────

it('skips processing when the batch uuid does not match', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => null,
        'stream_stats' => null,
    ]);

    (new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: 'stale-uuid',
        totalChannels: 1,
    ))->handle();

    $channel->refresh();
    expect($channel->enabled)->toBeTrue();
});

it('skips processing when the scrubber is cancelled', function () {
    $this->scrubber->update(['status' => Status::Cancelled]);

    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => null,
        'stream_stats' => null,
    ]);

    (new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
    ))->handle();

    $channel->refresh();
    expect($channel->enabled)->toBeTrue();
});
