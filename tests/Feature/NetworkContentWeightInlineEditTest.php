<?php

use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Filament\Resources\Networks\RelationManagers\NetworkContentRelationManager;
use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('updates weight inline from the network content table', function () {
    $network = Network::factory()->create(['user_id' => $this->user->id]);
    $channel = Channel::factory()->create();

    $content = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 1,
    ]));

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])
        ->assertTableColumnExists('weight')
        ->call('updateTableColumnState', 'weight', (string) $content->getKey(), '5');

    expect($content->refresh()->weight)->toBe(5);
});

it('rejects a weight below the minimum via inline edit', function () {
    $network = Network::factory()->create(['user_id' => $this->user->id]);
    $channel = Channel::factory()->create();

    $content = NetworkContent::withoutEvents(fn () => NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
        'sort_order' => 1,
        'weight' => 3,
    ]));

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])->call('updateTableColumnState', 'weight', (string) $content->getKey(), '0');

    expect($content->refresh()->weight)->toBe(3);
});
