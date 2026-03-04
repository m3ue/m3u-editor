<?php

use App\Models\Network;
use App\Services\NetworkBroadcastService;
use App\Services\NetworkScheduleService;
use Illuminate\Support\Carbon;

it('enables network, generates schedule and starts broadcast via ensure command', function () {
    $network = Network::factory()->create(['broadcast_enabled' => false]);

    // Ensure there are no programmes
    $network->programmes()->delete();

    // Mock schedule service and broadcast service
    $this->mock(NetworkScheduleService::class, function ($mock) {
        $mock->shouldReceive('generateSchedule')->once()->with(Mockery::type(Network::class))->andReturnUsing(function ($n) {
            // create a simple programme
            $n->programmes()->create([
                'title' => 'Test Programme',
                'contentable_type' => \App\Models\Channel::class,
                'contentable_id' => 1,
                'start_time' => Carbon::now()->subMinutes(1),
                'end_time' => Carbon::now()->addMinutes(30),
                'duration_seconds' => 31 * 60,
            ]);

            return 1; // Return the count of programmes generated
        });
    });

    $this->mock(NetworkBroadcastService::class, function ($mock) {
        $mock->shouldReceive('restart')->andReturnUsing(function ($nw) {
            $nw->update(['broadcast_pid' => 22222, 'broadcast_started_at' => Carbon::now()]);

            return true;
        });
    });

    $this->artisan('network:broadcast:ensure', ['network' => $network->id])->assertExitCode(0);

    $network->refresh();

    expect($network->broadcast_enabled)->toBeTrue();
    expect($network->broadcast_pid)->toBe(22222);
    expect($network->programmes()->count())->toBeGreaterThan(0);
});
