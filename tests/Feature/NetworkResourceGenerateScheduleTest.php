<?php

use App\Filament\Resources\Networks\Pages\ListNetworks;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\User;
use App\Services\NetworkBroadcastService;
use App\Services\NetworkScheduleService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create([
        'permissions' => ['use_integrations'],
    ]);

    $this->actingAs($this->user);
});

function makeGeneratableNetwork(User $user, array $overrides = []): Network
{
    $playlist = Playlist::factory()->create([
        'user_id' => $user->id,
        'is_network_playlist' => true,
    ]);

    return Network::factory()->create(array_merge([
        'user_id' => $user->id,
        'network_playlist_id' => $playlist->id,
        'schedule_type' => 'sequential',
        'loop_content' => true,
        'auto_regenerate_schedule' => false,
    ], $overrides));
}

it('restarts broadcast when generate schedule is called with force reset and network is broadcasting', function () {
    $network = makeGeneratableNetwork($this->user, [
        'broadcast_enabled' => true,
        'broadcast_started_at' => now(),
        'broadcast_pid' => 12345,
    ]);

    $scheduleService = Mockery::mock(NetworkScheduleService::class);
    $scheduleService->shouldReceive('generateSchedule')
        ->once()
        ->with(Mockery::on(fn ($n) => $n instanceof Network && $n->id === $network->id), null, true)
        ->andReturn(1);
    app()->instance(NetworkScheduleService::class, $scheduleService);

    $broadcastService = Mockery::mock(NetworkBroadcastService::class);
    $broadcastService->shouldReceive('restart')
        ->once()
        ->with(Mockery::on(fn ($n) => $n instanceof Network && $n->id === $network->id))
        ->andReturn(true);
    app()->instance(NetworkBroadcastService::class, $broadcastService);

    Livewire::test(ListNetworks::class)
        ->callAction(TestAction::make('generateSchedule')->table($network), data: [
            'mode' => 'reset',
        ])
        ->assertNotified('Schedule Generated');
});

it('does not restart broadcast when generate schedule is called with continue mode even if network is broadcasting', function () {
    $network = makeGeneratableNetwork($this->user, [
        'broadcast_enabled' => true,
        'broadcast_started_at' => now(),
        'broadcast_pid' => 12345,
    ]);

    $scheduleService = Mockery::mock(NetworkScheduleService::class);
    $scheduleService->shouldReceive('generateSchedule')
        ->once()
        ->with(Mockery::on(fn ($n) => $n instanceof Network && $n->id === $network->id), null, false)
        ->andReturn(1);
    app()->instance(NetworkScheduleService::class, $scheduleService);

    $broadcastService = Mockery::mock(NetworkBroadcastService::class);
    $broadcastService->shouldNotReceive('restart');
    app()->instance(NetworkBroadcastService::class, $broadcastService);

    Livewire::test(ListNetworks::class)
        ->callAction(TestAction::make('generateSchedule')->table($network), data: [
            'mode' => 'continue',
        ])
        ->assertNotified('Schedule Generated');
});

it('does not restart broadcast when force reset is requested but network is not broadcasting', function () {
    $network = makeGeneratableNetwork($this->user, [
        'broadcast_enabled' => true,
        'broadcast_started_at' => null,
        'broadcast_pid' => null,
    ]);

    $scheduleService = Mockery::mock(NetworkScheduleService::class);
    $scheduleService->shouldReceive('generateSchedule')
        ->once()
        ->andReturn(1);
    app()->instance(NetworkScheduleService::class, $scheduleService);

    $broadcastService = Mockery::mock(NetworkBroadcastService::class);
    $broadcastService->shouldNotReceive('restart');
    app()->instance(NetworkBroadcastService::class, $broadcastService);

    Livewire::test(ListNetworks::class)
        ->callAction(TestAction::make('generateSchedule')->table($network), data: [
            'mode' => 'reset',
        ])
        ->assertNotified('Schedule Generated');
});
