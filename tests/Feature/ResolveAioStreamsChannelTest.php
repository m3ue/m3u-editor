<?php

use App\Jobs\ResolveAioStreamsChannel;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\AioStreamsQualityParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
    $this->channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'is_vod' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_item_id' => 'tt1234567',
        'aio_type' => 'movie',
        'aio_resolution_status' => 'pending',
        'url' => null,
    ]);
});

it('resolves the top candidates and creates a failover chain', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response([
            'streams' => [
                ['name' => 'Movie.720p', 'url' => 'https://example.com/720p.mkv'],
                ['name' => 'Movie.2160p.HDR10', 'url' => 'https://example.com/2160p.mkv'],
                ['name' => 'Movie.1080p', 'url' => 'https://example.com/1080p.mkv'],
                ['name' => 'Movie.480p', 'url' => 'https://example.com/480p.mkv'],
            ],
        ], 200),
    ]);

    (new ResolveAioStreamsChannel($this->channel->id))->handle(new AioStreamsQualityParser);

    $this->channel->refresh();

    // The resolved URL is never stored directly — it's hidden behind the media-server
    // proxy (see MediaServerProxyController::streamAioStreamsChannel()), stored on
    // the channel's own movie_data (internal-only) and referenced by the channel's
    // own ID in the generated URL.
    expect($this->channel->aio_resolution_status)->toBe('resolved')
        ->and($this->channel->url)->toContain("/aiostreams-media/{$this->integration->id}/channel/{$this->channel->id}/stream")
        ->and($this->channel->movie_data['aiostreams']['resolved_url'])->toBe('https://example.com/2160p.mkv')
        ->and($this->channel->container_extension)->toBe('mkv')
        ->and(ChannelFailover::where('channel_id', $this->channel->id)->count())->toBe(2);
});

it('creates failover candidates as hidden clones that do not appear in normal Channel queries', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response([
            'streams' => [
                ['name' => 'Movie.720p', 'url' => 'https://example.com/720p.mkv'],
                ['name' => 'Movie.2160p.HDR10', 'url' => 'https://example.com/2160p.mkv'],
                ['name' => 'Movie.1080p', 'url' => 'https://example.com/1080p.mkv'],
            ],
        ], 200),
    ]);

    (new ResolveAioStreamsChannel($this->channel->id))->handle(new AioStreamsQualityParser);

    $failoverChannelIds = ChannelFailover::where('channel_id', $this->channel->id)->pluck('channel_failover_id');
    expect($failoverChannelIds)->toHaveCount(2);

    // Hidden from plain Eloquent queries (VodResource, relation managers, Xtream API, etc.)...
    expect(Channel::whereIn('id', $failoverChannelIds)->count())->toBe(0)
        ->and(Channel::where('is_vod', true)->count())->toBe(1);

    // ...but still reachable via the failover relationship the playback path uses.
    expect($this->channel->failoverChannels()->count())->toBe(2)
        ->and($this->channel->failoverChannels()->pluck('channels.id')->sort()->values()->all())
        ->toBe($failoverChannelIds->sort()->values()->all());

    // Each failover clone gets its own resolved URL and a proxy URL keyed by its
    // own ID, not the primary channel's.
    $firstFailover = Channel::withoutGlobalScopes()->find($failoverChannelIds->first());
    expect($firstFailover->url)->toContain("/aiostreams-media/{$this->integration->id}/channel/{$firstFailover->id}/stream")
        ->and($firstFailover->movie_data['aiostreams']['resolved_url'])->not->toBeNull();
});

it('deletes orphaned failover clone channels when the primary channel is deleted', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response([
            'streams' => [
                ['name' => 'Movie.720p', 'url' => 'https://example.com/720p.mkv'],
                ['name' => 'Movie.2160p.HDR10', 'url' => 'https://example.com/2160p.mkv'],
            ],
        ], 200),
    ]);

    (new ResolveAioStreamsChannel($this->channel->id))->handle(new AioStreamsQualityParser);

    $failoverChannelIds = ChannelFailover::where('channel_id', $this->channel->id)->pluck('channel_failover_id');
    expect($failoverChannelIds)->toHaveCount(1);

    $this->channel->delete();

    expect(Channel::withoutGlobalScopes()->whereIn('id', $failoverChannelIds)->count())->toBe(0);
});

it('actually retries through the real queue pipeline and reaches failed, despite the job\'s own unique lock', function () {
    // Same rationale as the equivalent episode test: Bus::fake() never touches the
    // real ShouldBeUnique lock, so it can't catch a self-redispatch that silently
    // fails to enqueue because the currently-running job's own lock (same uniqueId)
    // is still held. Forcing the real sync connection (config/queue.php hardcodes
    // 'redis' as the default, ignoring QUEUE_CONNECTION) lets each dispatched job
    // run immediately, so attempts 1 through 3 should cascade all the way to
    // 'failed' with no retry silently dropped.
    config(['queue.default' => 'sync']);

    Http::fake([
        'aiostreams.test/*' => Http::response(['streams' => []], 200),
    ]);

    ResolveAioStreamsChannel::dispatch($this->channel->id);

    $this->channel->refresh();
    expect($this->channel->aio_resolution_status)->toBe('failed');
});

it('retries with a delay when AIOStreams returns no results, and eventually marks failed', function () {
    Bus::fake();

    Http::fake([
        'aiostreams.test/*' => Http::response(['streams' => []], 200),
    ]);

    (new ResolveAioStreamsChannel($this->channel->id, attempt: 1))->handle(new AioStreamsQualityParser);

    Bus::assertDispatched(ResolveAioStreamsChannel::class, function (ResolveAioStreamsChannel $job) {
        return $job->channelId === $this->channel->id && $job->attempt === 2;
    });

    $this->channel->refresh();
    expect($this->channel->aio_resolution_status)->toBe('pending');

    (new ResolveAioStreamsChannel($this->channel->id, attempt: 3))->handle(new AioStreamsQualityParser);

    $this->channel->refresh();
    expect($this->channel->aio_resolution_status)->toBe('failed');
});
