<?php

use App\Enums\Status;
use App\Jobs\ProcessChannelScrubberChunk;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Config::set('cache.default', 'array');
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());

    $this->scrubber = ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
        'name' => 'Test Scrubber',
        'uuid' => '00000000-0000-4000-8000-000000000001',
        'status' => Status::Processing,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'progress' => 0,
    ]));

    $this->log = ChannelScrubberLog::create([
        'channel_scrubber_id' => $this->scrubber->id,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'status' => 'processing',
    ]);

    $this->channel = function (array $attributes = []): Channel {
        return Channel::withoutEvents(fn () => Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => null,
            ...$attributes,
        ]));
    };

    $this->handleChunk = fn (ProcessChannelScrubberChunk $chunk): mixed => Channel::withoutEvents(fn () => $chunk->handle());
});

// ──────────────────────────────────────────────────────────────────────────────
// ffprobe check method — uses ensureStreamStats()
// ──────────────────────────────────────────────────────────────────────────────

it('marks a channel dead via ffprobe when ensureStreamStats returns empty', function () {
    // No URL and no stream_stats → ensureStreamStats() returns [] → dead
    $channel = ($this->channel)([
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => null,
        'url_custom' => null,
        'stream_stats' => null,
    ]);

    ($this->handleChunk)(new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
    ));

    $channel->refresh();
    expect($channel->enabled)->toBeFalse();
    expect($channel->last_scrubber_live)->toBeFalse();
    expect($channel->last_scrubbed_at)->not->toBeNull();

    $this->assertDatabaseHas('channel_scrubber_log_channels', [
        'channel_scrubber_log_id' => $this->log->id,
        'channel_id' => $channel->id,
    ]);
});

it('marks a channel dead via ffprobe even when stream_stats are cached', function () {
    // Previously, cached stream_stats caused ensureStreamStats() to skip the network check,
    // producing false negatives for dead streams that had been probed before.
    // The new lightweight ffprobe probe always makes a fresh network call — cached stats
    // are irrelevant. A null URL with cached stats is still dead.
    $channel = ($this->channel)([
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => null,
        'url_custom' => null,
        'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]],
        ],
    ]);

    ($this->handleChunk)(new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
    ));

    $channel->refresh();
    expect($channel->enabled)->toBeFalse();
    expect($channel->last_scrubber_live)->toBeFalse();
    expect($channel->last_scrubbed_at)->not->toBeNull();

    $this->assertDatabaseHas('channel_scrubber_log_channels', [
        'channel_scrubber_log_id' => $this->log->id,
        'channel_id' => $channel->id,
    ]);
});

it('increments dead_count on the scrubber for each dead channel', function () {
    $dead1 = ($this->channel)([
        'user_id' => $this->user->id,
        'url' => null,
        'url_custom' => null,
        'stream_stats' => null,
    ]);
    $dead2 = ($this->channel)([
        'user_id' => $this->user->id,
        'url' => null,
        'url_custom' => null,
        'stream_stats' => null,
    ]);

    ($this->handleChunk)(new ProcessChannelScrubberChunk(
        channelIds: [$dead1->id, $dead2->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: $this->scrubber->uuid,
        totalChannels: 2,
    ));

    expect($this->scrubber->fresh()->dead_count)->toBe(2);
    expect($this->log->fresh()->live_count)->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// Scrubber lifecycle guards
// ──────────────────────────────────────────────────────────────────────────────

it('skips processing when the batch uuid does not match', function () {
    $channel = ($this->channel)([
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => null,
        'stream_stats' => null,
    ]);

    ($this->handleChunk)(new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: 'stale-uuid',
        totalChannels: 1,
    ));

    $channel->refresh();
    expect($channel->enabled)->toBeTrue();
});

it('skips processing when the scrubber is cancelled', function () {
    $this->scrubber->update(['status' => Status::Cancelled]);

    $channel = ($this->channel)([
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => null,
        'stream_stats' => null,
    ]);

    ($this->handleChunk)(new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
    ));

    $channel->refresh();
    expect($channel->enabled)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// disableDead = false — dead channels are logged but not disabled
// ──────────────────────────────────────────────────────────────────────────────

it('does not disable dead channels when disableDead is false', function () {
    $channel = ($this->channel)([
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => null,
        'url_custom' => null,
        'stream_stats' => null,
    ]);

    ($this->handleChunk)(new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'ffprobe',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
        disableDead: false,
    ));

    $channel->refresh();
    expect($channel->enabled)->toBeTrue();
    expect($channel->last_scrubber_live)->toBeFalse();
    expect($channel->last_scrubbed_at)->not->toBeNull();

    $this->assertDatabaseHas('channel_scrubber_log_channels', [
        'channel_scrubber_log_id' => $this->log->id,
        'channel_id' => $channel->id,
    ]);

    expect($this->log->fresh()->disabled_count)->toBe(0);
    expect($this->scrubber->fresh()->dead_count)->toBe(1);
});

// ──────────────────────────────────────────────────────────────────────────────
// enableLive = true — live channels that were disabled get re-enabled
// ──────────────────────────────────────────────────────────────────────────────

it('re-enables a previously disabled live channel when enableLive is true', function () {
    $channel = ($this->channel)([
        'user_id' => $this->user->id,
        'enabled' => false,
        'url' => 'http://example.invalid/stream',
        'url_custom' => null,
        'stream_stats' => null,
    ]);

    // Fake a successful HTTP HEAD response so the channel is classified as live.
    Http::fake([
        'http://example.invalid/stream' => Http::response('', 200),
    ]);

    ($this->handleChunk)(new ProcessChannelScrubberChunk(
        channelIds: [$channel->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'http',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
        enableLive: true,
    ));

    $channel->refresh();
    expect($channel->enabled)->toBeTrue();
    expect($channel->last_scrubber_live)->toBeTrue();
    expect($channel->last_scrubbed_at)->not->toBeNull();

    expect($this->log->fresh()->live_count)->toBe(1);
});

it('keeps a disabled live failover channel hidden when failover protection is enabled', function () {
    $master = ($this->channel)([
        'enabled' => true,
    ]);

    $failover = ($this->channel)([
        'enabled' => false,
        'url' => 'http://example.invalid/failover',
        'url_custom' => null,
        'stream_stats' => null,
    ]);

    ChannelFailover::create([
        'user_id' => $this->user->id,
        'channel_id' => $master->id,
        'channel_failover_id' => $failover->id,
    ]);

    Http::fake([
        'http://example.invalid/failover' => Http::response('', 200),
    ]);

    ($this->handleChunk)(new ProcessChannelScrubberChunk(
        channelIds: [$failover->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'http',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
        enableLive: true,
        protectFailoverChannels: true,
    ));

    $failover->refresh();
    expect($failover->enabled)->toBeFalse();
    expect($failover->last_scrubber_live)->toBeTrue();
    expect($failover->last_scrubbed_at)->not->toBeNull();
    expect($this->log->fresh()->live_count)->toBe(1);
});

it('re-enables a disabled live failover channel when failover protection is disabled', function () {
    $master = ($this->channel)([
        'enabled' => true,
    ]);

    $failover = ($this->channel)([
        'enabled' => false,
        'url' => 'http://example.invalid/failover',
        'url_custom' => null,
        'stream_stats' => null,
    ]);

    ChannelFailover::create([
        'user_id' => $this->user->id,
        'channel_id' => $master->id,
        'channel_failover_id' => $failover->id,
    ]);

    Http::fake([
        'http://example.invalid/failover' => Http::response('', 200),
    ]);

    ($this->handleChunk)(new ProcessChannelScrubberChunk(
        channelIds: [$failover->id],
        scrubberId: $this->scrubber->id,
        logId: $this->log->id,
        checkMethod: 'http',
        batchNo: $this->scrubber->uuid,
        totalChannels: 1,
        enableLive: true,
        protectFailoverChannels: false,
    ));

    $failover->refresh();
    expect($failover->enabled)->toBeTrue();
    expect($failover->last_scrubber_live)->toBeTrue();
    expect($failover->last_scrubbed_at)->not->toBeNull();
    expect($this->log->fresh()->live_count)->toBe(1);
});
