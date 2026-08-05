<?php

use App\Enums\Status;
use App\Filament\Resources\EpgMaps\Pages\ListEpgMaps;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Playlist;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
});

it('clears a stuck processing flag when restarting a hung map, so the re-fired job is not skipped', function () {
    $this->actingAs($this->user);

    // Simulate a worker that died mid-run (e.g. container restart): the
    // `processing` claim flag never got reset back to false by the job's
    // own completion/failure handlers.
    $map = EpgMap::factory()->create([
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'status' => Status::Processing,
        'progress' => 0,
        'processing' => true,
    ]);

    Livewire::test(ListEpgMaps::class)
        ->callAction(TestAction::make('restart')->table($map));

    expect($map->refresh()->processing)->toBeFalse();

    // With the flag cleared, a freshly re-fired job must actually claim
    // and run instead of being silently skipped as "already in progress".
    (new MapPlaylistChannelsToEpg(
        epg: $this->epg->id,
        playlist: $this->playlist->id,
        epgMapId: $map->id,
    ))->handle();

    expect($map->refresh()->processing)->toBeTrue();
});
