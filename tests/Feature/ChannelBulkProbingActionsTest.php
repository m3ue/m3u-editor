<?php

use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('has enable-probing and disable-probing bulk actions on channels list', function () {
    Livewire::test(ListChannels::class)
        ->assertTableBulkActionExists('enable-probing')
        ->assertTableBulkActionExists('disable-probing');
});

it('enables probing for selected channels via bulk action', function () {
    $channels = Channel::factory()
        ->count(3)
        ->for($this->playlist)
        ->create(['probe_enabled' => false]);

    Livewire::test(ListChannels::class)
        ->callTableBulkAction('enable-probing', $channels);

    foreach ($channels as $channel) {
        expect($channel->fresh()->probe_enabled)->toBeTrue();
    }
});

it('disables probing for selected channels via bulk action', function () {
    $channels = Channel::factory()
        ->count(3)
        ->for($this->playlist)
        ->create(['probe_enabled' => true]);

    Livewire::test(ListChannels::class)
        ->callTableBulkAction('disable-probing', $channels);

    foreach ($channels as $channel) {
        expect($channel->fresh()->probe_enabled)->toBeFalse();
    }
});
