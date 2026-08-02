<?php

use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);
});

it('disables merge/probe and hides the failover repeater on the Channel edit form for AIOStreams-added channels', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'aio_integration_id' => $this->integration->id,
    ]);

    Livewire::test(EditChannel::class, ['record' => $channel->id])
        ->assertFormFieldIsDisabled('can_merge')
        ->assertFormFieldIsDisabled('probe_enabled')
        ->assertFormFieldIsHidden('failovers');
});

it('leaves merge/probe enabled and the failover repeater visible on the Channel edit form for normal channels', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'aio_integration_id' => null,
    ]);

    Livewire::test(EditChannel::class, ['record' => $channel->id])
        ->assertFormFieldEnabled('can_merge')
        ->assertFormFieldEnabled('probe_enabled')
        ->assertFormFieldVisible('failovers');
});
