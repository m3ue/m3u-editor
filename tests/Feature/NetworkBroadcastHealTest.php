<?php

use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Carbon;

it('heals stale broadcast and restarts it using persisted reference', function () {
    // Create network + programme
    $network = Network::factory()->create(['broadcast_enabled' => true]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => Carbon::now()->subMinutes(5),
        'end_time' => Carbon::now()->addMinutes(60),
        'duration_seconds' => 65 * 60,
    ]);

    // Simulate stale pid
    $network->update([
        'broadcast_pid' => 999999, // non-existent pid
        'broadcast_programme_id' => $programme->id,
        'broadcast_initial_offset_seconds' => 120,
        'broadcast_started_at' => Carbon::now()->subSeconds(30),
    ]);

    // Mock the broadcast service to simulate a successful restart
    $this->mock(NetworkBroadcastService::class, function ($mock) {
        $mock->shouldReceive('isProcessRunning')->andReturn(false);
        $mock->shouldReceive('start')->andReturnUsing(function ($nw) {
            // Simulate the start updating the model as executeCommand would
            $nw->update(['broadcast_pid' => 12345, 'broadcast_started_at' => Carbon::now()]);

            return true;
        });
    });

    // Run the heal command
    $this->artisan('network:broadcast:heal')->assertExitCode(0);

    $network->refresh();

    expect($network->broadcast_pid)->toBe(12345);
    expect($network->broadcast_started_at)->not->toBeNull();
});
