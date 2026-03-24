<?php

use App\Filament\Resources\Networks\Pages\ListNetworks;
use App\Models\Network;
use App\Models\User;
use App\Services\NetworkBroadcastService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create([
        'permissions' => ['use_integrations'],
    ]);

    $this->actingAs($this->user);
});

it('shows start and stop broadcast bulk actions on networks list', function () {
    Livewire::test(ListNetworks::class)
        ->assertTableBulkActionExists('startBroadcastSelected')
        ->assertTableBulkActionExists('stopBroadcastSelected');
});

it('starts broadcast for selected networks via bulk action', function () {
    $networks = Network::factory()
        ->count(2)
        ->create([
            'user_id' => $this->user->id,
            'broadcast_enabled' => true,
            'broadcast_requested' => false,
        ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('startNow')
        ->times(2)
        ->andReturn(true);

    app()->instance(NetworkBroadcastService::class, $service);

    Livewire::test(ListNetworks::class)
        ->callTableBulkAction('startBroadcastSelected', $networks);

    expect($networks[0]->fresh()->broadcast_requested)->toBeTrue();
    expect($networks[1]->fresh()->broadcast_requested)->toBeTrue();
});

it('stops broadcast for selected networks via bulk action', function () {
    $networks = Network::factory()
        ->count(2)
        ->activeBroadcast()
        ->create([
            'user_id' => $this->user->id,
            'broadcast_enabled' => true,
        ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('stop')
        ->times(2)
        ->andReturn(true);

    app()->instance(NetworkBroadcastService::class, $service);

    Livewire::test(ListNetworks::class)
        ->callTableBulkAction('stopBroadcastSelected', $networks);
});
