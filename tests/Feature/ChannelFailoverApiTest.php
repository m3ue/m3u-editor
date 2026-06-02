<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->channel = Channel::factory()->for($this->user)->for($this->playlist)->createQuietly();
    $this->token = $this->user->createToken('test')->plainTextToken;
});

it('triggers failover for an active stream', function () {
    $streamId = 'stream-abc-123';

    $this->mock(M3uProxyService::class)
        ->shouldReceive('triggerFailoverForChannel')
        ->with($this->channel->id, true)
        ->once()
        ->andReturn([
            'success' => true,
            'triggered_count' => 1,
            'stream_ids' => [$streamId],
        ]);

    $this->withToken($this->token)
        ->postJson('/channel/trigger-failover', ['channel_id' => $this->channel->id])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('triggered_count', 1)
        ->assertJsonPath('stream_ids.0', $streamId);
});

it('returns triggered_count zero when channel has no active streams', function () {
    $this->mock(M3uProxyService::class)
        ->shouldReceive('triggerFailoverForChannel')
        ->with($this->channel->id, true)
        ->once()
        ->andReturn([
            'success' => true,
            'triggered_count' => 0,
            'stream_ids' => [],
        ]);

    $this->withToken($this->token)
        ->postJson('/channel/trigger-failover', ['channel_id' => $this->channel->id])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('triggered_count', 0)
        ->assertJsonPath('stream_ids', []);
});

it('returns 404 when channel belongs to another user', function () {
    $other = User::factory()->create();
    $otherToken = $other->createToken('test')->plainTextToken;

    $this->withToken($otherToken)
        ->postJson('/channel/trigger-failover', ['channel_id' => $this->channel->id])
        ->assertNotFound()
        ->assertJsonPath('success', false);
});

it('returns 401 when unauthenticated', function () {
    $this->postJson('/channel/trigger-failover', ['channel_id' => $this->channel->id])
        ->assertUnauthorized();
});

it('returns 422 when channel_id is missing', function () {
    $this->withToken($this->token)
        ->postJson('/channel/trigger-failover')
        ->assertUnprocessable();
});

it('returns 404 for a non-existent channel', function () {
    $this->withToken($this->token)
        ->postJson('/channel/trigger-failover', ['channel_id' => 99999])
        ->assertNotFound()
        ->assertJsonPath('success', false);
});

it('passes active_only=false to the service when requested', function () {
    $this->mock(M3uProxyService::class)
        ->shouldReceive('triggerFailoverForChannel')
        ->with($this->channel->id, false)
        ->once()
        ->andReturn(['success' => true, 'triggered_count' => 0, 'stream_ids' => []]);

    $this->withToken($this->token)
        ->postJson('/channel/trigger-failover', ['channel_id' => $this->channel->id, 'active_only' => false])
        ->assertOk();
});

it('returns 502 when proxy is not configured', function () {
    $this->mock(M3uProxyService::class)
        ->shouldReceive('triggerFailoverForChannel')
        ->with($this->channel->id, true)
        ->once()
        ->andReturn([
            'success' => false,
            'triggered_count' => 0,
            'stream_ids' => [],
            'error' => 'M3U Proxy base URL not configured',
        ]);

    $this->withToken($this->token)
        ->postJson('/channel/trigger-failover', ['channel_id' => $this->channel->id])
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'M3U Proxy base URL not configured');
});
