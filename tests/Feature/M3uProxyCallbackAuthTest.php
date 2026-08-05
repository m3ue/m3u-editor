<?php

namespace Tests\Feature;

use App\Models\DvrRecording;
use App\Models\Network;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M3uProxyCallbackAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config(['proxy.allow_unauthenticated_callbacks' => false]);

        parent::tearDown();
    }

    public function test_failover_resolver_rejects_request_with_no_token_configured()
    {
        config(['proxy.m3u_proxy_token' => null]);

        $response = $this->postJson('/api/m3u-proxy/failover-resolver', [
            'current_url' => 'https://example.com/stream',
            'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
        ]);

        $response->assertStatus(401);
    }

    public function test_failover_resolver_rejects_wrong_token()
    {
        config(['proxy.m3u_proxy_token' => 'correct-token']);

        $response = $this->postJson('/api/m3u-proxy/failover-resolver?api_token=wrong-token', [
            'current_url' => 'https://example.com/stream',
            'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
        ]);

        $response->assertStatus(401);
    }

    public function test_failover_resolver_accepts_valid_token_via_query_param()
    {
        config(['proxy.m3u_proxy_token' => 'correct-token']);

        $response = $this->postJson('/api/m3u-proxy/failover-resolver?api_token=correct-token', [
            'current_url' => 'https://example.com/stream',
            'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
        ]);

        // Reaches the controller (not blocked at 401); the resolver itself may
        // still fail to find the channel, which isn't what this test verifies.
        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_failover_resolver_accepts_valid_token_via_header()
    {
        config(['proxy.m3u_proxy_token' => 'correct-token']);

        $response = $this->postJson('/api/m3u-proxy/failover-resolver', [
            'current_url' => 'https://example.com/stream',
            'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
        ], ['X-API-Token' => 'correct-token']);

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_local_bypass_flag_allows_unconfigured_token()
    {
        config([
            'proxy.m3u_proxy_token' => null,
            'proxy.allow_unauthenticated_callbacks' => true,
        ]);

        $response = $this->postJson('/api/m3u-proxy/failover-resolver', [
            'current_url' => 'https://example.com/stream',
            'metadata' => ['id' => 1, 'type' => 'channel', 'playlist_uuid' => 'test-uuid'],
        ]);

        $this->assertNotEquals(401, $response->getStatusCode());
    }

    public function test_webhook_route_requires_token()
    {
        config(['proxy.m3u_proxy_token' => 'correct-token']);

        $response = $this->postJson('/api/m3u-proxy/webhooks', ['event_type' => 'client_connected']);

        $response->assertStatus(401);
    }

    public function test_broadcast_callback_route_requires_token()
    {
        config(['proxy.m3u_proxy_token' => 'correct-token']);

        $network = Network::factory()->create();

        $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
            'network_id' => $network->uuid,
            'event' => 'programme_ended',
        ]);

        $response->assertStatus(401);
    }

    public function test_dvr_callback_route_requires_token()
    {
        config(['proxy.m3u_proxy_token' => 'correct-token']);

        $recording = DvrRecording::factory()->create();

        $response = $this->postJson('/api/dvr/callback', [
            'network_id' => $recording->uuid,
            'event' => 'programme_ended',
        ]);

        $response->assertStatus(401);
    }

    public function test_dvr_callback_route_accepts_valid_token()
    {
        config(['proxy.m3u_proxy_token' => 'correct-token']);

        $recording = DvrRecording::factory()->create();

        $response = $this->postJson('/api/dvr/callback?api_token=correct-token', [
            'network_id' => $recording->uuid,
            'event' => 'programme_ended',
        ]);

        $response->assertSuccessful();
    }
}
