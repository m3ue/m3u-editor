<?php

use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Models\User;

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
