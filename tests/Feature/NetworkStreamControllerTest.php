<?php

use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns 503 when broadcast is not actively broadcasting', function () {
    $network = Network::factory()->for($this->user)->broadcasting()->create();

    // Create a programme for now
    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Test Programme',
        'start_time' => now(),
        'end_time' => now()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => Channel::class,
        'contentable_id' => Channel::factory()->create()->id,
    ]);

    $response = $this->get(url("/network/{$network->uuid}/stream.ts"));

    $response->assertStatus(503);
});

it('returns 404 when network has been deleted', function () {
    $network = Network::factory()->activeBroadcast()->create();

    $uuid = $network->uuid;

    // Delete the network
    $network->delete();

    $response = $this->get(url("/network/{$uuid}/stream.ts"));

    $response->assertNotFound();
});

it('registers the real media server URL with the m3u-proxy and redirects the client to it, instead of relaying bytes itself', function () {
    config()->set('proxy.m3u_proxy_host', 'http://test-proxy.example');
    config()->set('proxy.m3u_proxy_port', null);

    Http::fake([
        '*/streams' => Http::response(['stream_id' => 'abc123'], 200),
    ]);

    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'test-token',
    ]);

    $network = Network::factory()
        ->for($this->user)
        ->activeBroadcast()
        ->create(['media_server_integration_id' => $integration->id]);

    $channel = Channel::factory()->for($this->user)->create([
        'info' => ['media_server_id' => '97255'],
    ]);

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Test Programme',
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addHour(),
        'duration_seconds' => 3900,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
    ]);

    $response = $this->get(url("/network/{$network->uuid}/stream.mkv"));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('/m3u-proxy/stream/abc123');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/streams')
            && str_contains($request['url'], 'emby.local:8096/Videos/97255/stream.mkv')
            && ($request['headers']['X-Emby-Token'] ?? null) === 'test-token';
    });
});
