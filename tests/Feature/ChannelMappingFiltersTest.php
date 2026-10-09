<?php

use App\Events\PlaylistCreated;
use App\Events\PlaylistUpdated;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Models\AedProfile;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([PlaylistCreated::class, PlaylistUpdated::class]);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('registers every channel table filter under a unique name', function () {
    $names = array_map(fn ($filter) => $filter->getName(), ChannelResource::getTableFilters());

    expect($names)->toBe(array_unique($names));
});

it('EPG is not mapped filter only shows channels without an EPG mapping', function () {
    $epg = Epg::factory()->for($this->user)->createQuietly();
    $epgChannel = EpgChannel::factory()->for($epg)->for($this->user)->create();

    $mapped = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
    ]);
    $unmapped = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'epg_channel_id' => null,
    ]);

    Livewire::test(ListChannels::class)
        ->assertOk()
        ->loadTable()
        ->filterTable('un_mapped')
        ->assertCanSeeTableRecords([$unmapped])
        ->assertCanNotSeeTableRecords([$mapped]);
});

it('AED Profile not applied filter only shows channels without an AED profile', function () {
    $aedProfile = AedProfile::factory()->for($this->user)->create();

    $withProfile = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'aed_profile_id' => $aedProfile->id,
    ]);
    $withoutProfile = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'aed_profile_id' => null,
    ]);

    Livewire::test(ListChannels::class)
        ->assertOk()
        ->loadTable()
        ->filterTable('aed_profile_not_applied')
        ->assertCanSeeTableRecords([$withoutProfile])
        ->assertCanNotSeeTableRecords([$withProfile]);
});
