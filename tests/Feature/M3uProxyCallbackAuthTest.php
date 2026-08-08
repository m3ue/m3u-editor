<?php

use App\Models\DvrRecording;
use App\Models\Network;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    config(['proxy.allow_unauthenticated_callbacks' => false]);
});

it('rejects failover resolver request with no token configured', function () {
    config(['proxy.m3u_proxy_token' => null]);

    $response = $this->postJson('/api/m3u-proxy/failover-resolver', [
        'current_url' => 'https://example.com/stream',
        'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
    ]);

    $response->assertStatus(401);
});

it('rejects failover resolver with wrong token', function () {
    config(['proxy.m3u_proxy_token' => 'correct-token']);

    $response = $this->postJson('/api/m3u-proxy/failover-resolver?api_token=wrong-token', [
        'current_url' => 'https://example.com/stream',
        'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
    ]);

    $response->assertStatus(401);
});

it('accepts a valid token via query param for failover resolver', function () {
    config(['proxy.m3u_proxy_token' => 'correct-token']);

    $response = $this->postJson('/api/m3u-proxy/failover-resolver?api_token=correct-token', [
        'current_url' => 'https://example.com/stream',
        'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
    ]);

    // Reaches the controller (not blocked at 401); the resolver itself may
    // still fail to find the channel, which isn't what this test verifies.
    $this->assertNotEquals(401, $response->getStatusCode());
});

it('accepts a valid token via header for failover resolver', function () {
    config(['proxy.m3u_proxy_token' => 'correct-token']);

    $response = $this->postJson('/api/m3u-proxy/failover-resolver', [
        'current_url' => 'https://example.com/stream',
        'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
    ], ['X-API-Token' => 'correct-token']);

    $this->assertNotEquals(401, $response->getStatusCode());
});

it('allows an unconfigured token via the local bypass flag', function () {
    config([
        'proxy.m3u_proxy_token' => null,
        'proxy.allow_unauthenticated_callbacks' => true,
    ]);

    $response = $this->postJson('/api/m3u-proxy/failover-resolver', [
        'current_url' => 'https://example.com/stream',
        'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
    ]);

    $this->assertNotEquals(401, $response->getStatusCode());
});

it('requires a token for the webhook route', function () {
    config(['proxy.m3u_proxy_token' => 'correct-token']);

    $response = $this->postJson('/api/m3u-proxy/webhooks', ['event_type' => 'client_connected']);

    $response->assertStatus(401);
});

it('requires a token for the broadcast callback route', function () {
    config(['proxy.m3u_proxy_token' => 'correct-token']);

    $network = Network::factory()->create();

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
    ]);

    $response->assertStatus(401);
});

it('requires a token for the dvr callback route', function () {
    config(['proxy.m3u_proxy_token' => 'correct-token']);

    $recording = DvrRecording::factory()->create();

    $response = $this->postJson('/api/dvr/callback', [
        'network_id' => $recording->uuid,
        'event' => 'programme_ended',
    ]);

    $response->assertStatus(401);
});

it('accepts a valid token for the dvr callback route', function () {
    config(['proxy.m3u_proxy_token' => 'correct-token']);

    $recording = DvrRecording::factory()->create();

    $response = $this->postJson('/api/dvr/callback?api_token=correct-token', [
        'network_id' => $recording->uuid,
        'event' => 'programme_ended',
    ]);

    $response->assertSuccessful();
});
