<?php

use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->epg = Epg::factory()->for($this->user)->create(['name' => 'Sky EPG']);
    $this->playlist = Playlist::withoutEvents(
        fn () => Playlist::factory()->for($this->user)->create(['name' => 'Home'])
    );
});

function makeMap(array $overrides = []): EpgMap
{
    return EpgMap::factory()->create(array_merge([
        'name' => EpgMap::buildName('Sky EPG', 'Home'),
        'epg_id' => test()->epg->id,
        'playlist_id' => test()->playlist->id,
        'user_id' => test()->user->id,
        'processing' => false,
    ], $overrides));
}

it('renames maps when their EPG is renamed', function () {
    $map = makeMap();

    $this->epg->update(['name' => 'Sky UK EPG']);

    expect($map->refresh()->name)->toBe('Sky UK EPG -> Home mapping');
});

it('renames maps when their playlist is renamed', function () {
    $map = makeMap();

    $this->playlist->update(['name' => 'Living Room']);

    expect($map->refresh()->name)->toBe('Sky EPG -> Living Room mapping');
});

it('renames custom (playlist-less) maps when their EPG is renamed', function () {
    $map = makeMap([
        'playlist_id' => null,
        'name' => EpgMap::buildName('Sky EPG', null),
    ]);

    $this->epg->update(['name' => 'Sky UK EPG']);

    expect($map->refresh()->name)->toBe('Sky UK EPG custom channel mapping');
});

it('leaves manually customised map names untouched', function () {
    $map = makeMap(['name' => 'My favourite mapping']);

    $this->epg->update(['name' => 'Sky UK EPG']);
    $this->playlist->update(['name' => 'Living Room']);

    expect($map->refresh()->name)->toBe('My favourite mapping');
});

it('re-derives the name when a map is re-pointed to a different EPG', function () {
    $otherEpg = Epg::factory()->for($this->user)->create(['name' => 'Freeview EPG']);
    $map = makeMap();

    $map->update(['epg_id' => $otherEpg->id]);

    expect($map->refresh()->name)->toBe('Freeview EPG -> Home mapping');
});

it('re-derives the name when a map is re-pointed to a different playlist', function () {
    $otherPlaylist = Playlist::withoutEvents(
        fn () => Playlist::factory()->for($this->user)->create(['name' => 'Bedroom'])
    );
    $map = makeMap();

    $map->update(['playlist_id' => $otherPlaylist->id]);

    expect($map->refresh()->name)->toBe('Sky EPG -> Bedroom mapping');
});

it('does not touch maps belonging to a different EPG', function () {
    $map = makeMap();
    $otherEpg = Epg::factory()->for($this->user)->create(['name' => 'Freeview EPG']);

    $otherEpg->update(['name' => 'Freeview HD EPG']);

    expect($map->refresh()->name)->toBe('Sky EPG -> Home mapping');
});
