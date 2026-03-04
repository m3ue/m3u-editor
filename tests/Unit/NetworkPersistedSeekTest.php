<?php

use App\Models\Network;
use App\Models\NetworkProgramme;
use Illuminate\Support\Carbon;

it('calculates persisted broadcast seek correctly', function () {
    $network = Network::factory()->create();

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => Carbon::now()->subMinutes(2), // started 2 minutes ago
        'end_time' => Carbon::now()->addMinutes(10),
        'duration_seconds' => 12 * 60,
    ]);

    // Simulate broadcast started 30s ago at offset 120s
    $network->update([
        'broadcast_programme_id' => $programme->id,
        'broadcast_initial_offset_seconds' => 120,
        'broadcast_started_at' => Carbon::now()->subSeconds(30),
    ]);

    $seek = $network->getPersistedBroadcastSeekForNow();

    // Expect initial 120 + 30 elapsed = 150 seconds
    expect($seek)->toBeInt();
    expect($seek)->toBe(150);
});
