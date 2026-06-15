<?php

use App\Events\PlaylistCreated;
use App\Events\PlaylistUpdated;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Models\Channel;
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

it('probed filter excludes channels where probing is disabled', function () {
    $probedEnabled = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'probe_enabled' => true,
        'stream_stats_probed_at' => now()->subHour(),
        'stream_stats' => [['stream' => ['codec_type' => 'video', 'codec_name' => 'h264']]],
    ]);

    $probedDisabled = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'probe_enabled' => false,
        'stream_stats_probed_at' => now()->subHour(),
        'stream_stats' => [['stream' => ['codec_type' => 'video', 'codec_name' => 'h264']]],
    ]);

    Livewire::test(ListChannels::class)
        ->assertOk()
        ->loadTable()
        ->filterTable('probed')
        ->assertCanSeeTableRecords([$probedEnabled])
        ->assertCanNotSeeTableRecords([$probedDisabled]);
});

it('not_probed filter excludes channels where probing is disabled', function () {
    $notProbedEnabled = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'probe_enabled' => true,
        'stream_stats_probed_at' => null,
    ]);

    $notProbedDisabled = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'probe_enabled' => false,
        'stream_stats_probed_at' => null,
    ]);

    Livewire::test(ListChannels::class)
        ->assertOk()
        ->loadTable()
        ->filterTable('not_probed')
        ->assertCanSeeTableRecords([$notProbedEnabled])
        ->assertCanNotSeeTableRecords([$notProbedDisabled]);
});

it('probe_failed filter excludes channels where probing is disabled', function () {
    $probeFailedEnabled = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'probe_enabled' => true,
        'stream_stats_probed_at' => now()->subHour(),
        'stream_stats' => [],
    ]);

    $probeFailedDisabled = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'probe_enabled' => false,
        'stream_stats_probed_at' => now()->subHour(),
        'stream_stats' => [],
    ]);

    Livewire::test(ListChannels::class)
        ->assertOk()
        ->loadTable()
        ->filterTable('probe_failed')
        ->assertCanSeeTableRecords([$probeFailedEnabled])
        ->assertCanNotSeeTableRecords([$probeFailedDisabled]);
});
