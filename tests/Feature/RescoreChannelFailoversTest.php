<?php

use App\Jobs\RescoreChannelFailovers;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use App\Models\User;
use App\Services\PlaylistUrlService;
use Illuminate\Queue\Middleware\WithoutOverlapping;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'failover_rescore_staleness_days' => 7,
    ]);
});

/**
 * Build a channel with a known set of stream stats for deterministic scoring.
 */
function rescoreChannel(User $user, Playlist $playlist, array $overrides, ?array $stats = null): Channel
{
    return Channel::factory()->for($user)->for($playlist)->create(array_merge([
        'enabled' => true,
        'can_merge' => true,
        'probe_enabled' => true,
        'stream_stats' => $stats ?? [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => '5000000',
                'avg_frame_rate' => '25/1',
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels' => 2,
                'bit_rate' => '128000',
            ]],
        ],
        'stream_stats_probed_at' => now(),
    ], $overrides));
}

it('reorders failovers so the highest-scoring channel sits at sort=0', function () {
    $master = rescoreChannel($this->user, $this->playlist, [
        'name' => 'Virtual Primary',
        'is_custom' => true,
        'url' => null,
    ]);

    $hd = rescoreChannel($this->user, $this->playlist, ['name' => 'HD Source']);
    $sd = rescoreChannel($this->user, $this->playlist, ['name' => 'SD Source'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    // Attach in the "wrong" order — SD at sort=0, HD at sort=1.
    ChannelFailover::create([
        'user_id' => $this->user->id,
        'channel_id' => $master->id,
        'channel_failover_id' => $sd->id,
        'sort' => 0,
    ]);
    ChannelFailover::create([
        'user_id' => $this->user->id,
        'channel_id' => $master->id,
        'channel_failover_id' => $hd->id,
        'sort' => 1,
    ]);

    (new RescoreChannelFailovers($this->playlist->id))->handle();

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $hd->id,
        'sort' => 0,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $sd->id,
        'sort' => 1,
    ]);
});

it('flips the effective smart-channel URL when the top failover changes', function () {
    $master = rescoreChannel($this->user, $this->playlist, [
        'name' => 'Virtual Primary',
        'is_custom' => true,
        'url' => null,
    ]);

    $hd = rescoreChannel($this->user, $this->playlist, ['name' => 'HD Source', 'url' => 'http://hd.example/stream']);
    $sd = rescoreChannel($this->user, $this->playlist, ['name' => 'SD Source', 'url' => 'http://sd.example/stream'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    ChannelFailover::create([
        'user_id' => $this->user->id, 'channel_id' => $master->id, 'channel_failover_id' => $sd->id, 'sort' => 0,
    ]);
    ChannelFailover::create([
        'user_id' => $this->user->id, 'channel_id' => $master->id, 'channel_failover_id' => $hd->id, 'sort' => 1,
    ]);

    expect((new PlaylistUrlService)->getChannelUrl($master->fresh(), 'http://m3u.test'))->toContain('sd.example');

    (new RescoreChannelFailovers($this->playlist->id))->handle();

    expect((new PlaylistUrlService)->getChannelUrl($master->fresh(), 'http://m3u.test'))->toContain('hd.example');
});

it('never modifies the master channel itself', function () {
    $master = rescoreChannel($this->user, $this->playlist, [
        'name' => 'Master',
        'enabled' => true,
        'is_custom' => false,
    ]);

    $failover = rescoreChannel($this->user, $this->playlist, ['name' => 'Failover']);

    ChannelFailover::create([
        'user_id' => $this->user->id, 'channel_id' => $master->id, 'channel_failover_id' => $failover->id, 'sort' => 0,
    ]);

    $beforeMasterAttrs = $master->only(['id', 'name', 'enabled', 'is_custom', 'url']);

    (new RescoreChannelFailovers($this->playlist->id))->handle();

    $master->refresh();
    expect($master->only(['id', 'name', 'enabled', 'is_custom', 'url']))->toBe($beforeMasterAttrs);
});

it('updates last_failover_rescore_at when the job completes', function () {
    expect($this->playlist->fresh()->last_failover_rescore_at)->toBeNull();

    $master = rescoreChannel($this->user, $this->playlist, ['name' => 'Master']);
    $failover = rescoreChannel($this->user, $this->playlist, ['name' => 'Failover']);
    ChannelFailover::create([
        'user_id' => $this->user->id, 'channel_id' => $master->id, 'channel_failover_id' => $failover->id, 'sort' => 0,
    ]);

    (new RescoreChannelFailovers($this->playlist->id))->handle();

    expect($this->playlist->fresh()->last_failover_rescore_at)->not->toBeNull();
});

it('updates last_failover_rescore_at even when the playlist has no failover groups', function () {
    rescoreChannel($this->user, $this->playlist, ['name' => 'Lonely']);

    (new RescoreChannelFailovers($this->playlist->id))->handle();

    expect($this->playlist->fresh()->last_failover_rescore_at)->not->toBeNull();
});

it('does not re-probe channels with fresh stream_stats', function () {
    $master = rescoreChannel($this->user, $this->playlist, ['name' => 'Master']);
    $failover = rescoreChannel($this->user, $this->playlist, [
        'name' => 'Failover',
        'stream_stats_probed_at' => now()->subDay(),
    ]);
    ChannelFailover::create([
        'user_id' => $this->user->id, 'channel_id' => $master->id, 'channel_failover_id' => $failover->id, 'sort' => 0,
    ]);

    $probedAt = $failover->fresh()->stream_stats_probed_at;

    (new RescoreChannelFailovers($this->playlist->id))->handle();

    // Within the staleness window — probed_at must remain unchanged.
    expect($failover->fresh()->stream_stats_probed_at?->timestamp)->toBe($probedAt?->timestamp);
});

it('skips rescoring when the master has no failovers attached', function () {
    rescoreChannel($this->user, $this->playlist, ['name' => 'Lonely']);

    (new RescoreChannelFailovers($this->playlist->id))->handle();

    expect(ChannelFailover::count())->toBe(0);
});

it('persists score breakdown on each failover row after rescoring', function () {
    $master = rescoreChannel($this->user, $this->playlist, [
        'name' => 'Virtual Primary',
        'is_custom' => true,
        'url' => null,
    ]);

    $hd = rescoreChannel($this->user, $this->playlist, ['name' => 'HD Source']);
    $sd = rescoreChannel($this->user, $this->playlist, ['name' => 'SD Source'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    ChannelFailover::create([
        'user_id' => $this->user->id, 'channel_id' => $master->id, 'channel_failover_id' => $sd->id, 'sort' => 0,
    ]);
    ChannelFailover::create([
        'user_id' => $this->user->id, 'channel_id' => $master->id, 'channel_failover_id' => $hd->id, 'sort' => 1,
    ]);

    (new RescoreChannelFailovers($this->playlist->id))->handle();

    $hdRow = ChannelFailover::where('channel_id', $master->id)
        ->where('channel_failover_id', $hd->id)
        ->first();

    expect($hdRow->metadata)
        ->toHaveKey('score')
        ->and($hdRow->metadata['priority_order'])->toBe(['resolution', 'fps', 'bitrate', 'codec'])
        ->and($hdRow->metadata['attribute_scores'])->toHaveKeys(['resolution', 'fps', 'bitrate', 'codec']);
});

it('registers a WithoutOverlapping middleware keyed on the playlist id', function () {
    $job = new RescoreChannelFailovers(playlistId: 42);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('42');
});

it('uses different overlap keys for different playlists so they do not block each other', function () {
    $jobA = new RescoreChannelFailovers(playlistId: 1);
    $jobB = new RescoreChannelFailovers(playlistId: 2);

    expect($jobA->middleware()[0]->key)->not->toBe($jobB->middleware()[0]->key);
});

it('honors the channelIds filter to scope rescoring to specific masters', function () {
    $masterA = rescoreChannel($this->user, $this->playlist, ['name' => 'Master A']);
    $masterB = rescoreChannel($this->user, $this->playlist, ['name' => 'Master B']);

    $aHigh = rescoreChannel($this->user, $this->playlist, ['name' => 'A High']);
    $aLow = rescoreChannel($this->user, $this->playlist, ['name' => 'A Low'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    $bHigh = rescoreChannel($this->user, $this->playlist, ['name' => 'B High']);
    $bLow = rescoreChannel($this->user, $this->playlist, ['name' => 'B Low'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    ChannelFailover::create(['user_id' => $this->user->id, 'channel_id' => $masterA->id, 'channel_failover_id' => $aLow->id, 'sort' => 0]);
    ChannelFailover::create(['user_id' => $this->user->id, 'channel_id' => $masterA->id, 'channel_failover_id' => $aHigh->id, 'sort' => 1]);

    ChannelFailover::create(['user_id' => $this->user->id, 'channel_id' => $masterB->id, 'channel_failover_id' => $bLow->id, 'sort' => 0]);
    ChannelFailover::create(['user_id' => $this->user->id, 'channel_id' => $masterB->id, 'channel_failover_id' => $bHigh->id, 'sort' => 1]);

    (new RescoreChannelFailovers($this->playlist->id, channelIds: [$masterA->id]))->handle();

    // Master A reordered, Master B left alone.
    $this->assertDatabaseHas('channel_failovers', ['channel_id' => $masterA->id, 'channel_failover_id' => $aHigh->id, 'sort' => 0]);
    $this->assertDatabaseHas('channel_failovers', ['channel_id' => $masterB->id, 'channel_failover_id' => $bLow->id, 'sort' => 0]);
});
