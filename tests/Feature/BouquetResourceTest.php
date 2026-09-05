<?php

use App\Filament\Resources\Bouquets\Pages\ListBouquets;
use App\Models\Bouquet;
use App\Models\Playlist;
use App\Models\SourceGroup;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('lists only the current user\'s bouquets', function () {
    $mine = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $theirs = Bouquet::factory()->create(['user_id' => $otherUser->id, 'playlist_id' => $otherPlaylist->id]);

    Livewire::test(ListBouquets::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('creates a standard-target bouquet persisting NAMES from picker IDs', function () {
    $sports = SourceGroup::create([
        'name' => 'Sports', 'playlist_id' => $this->playlist->id, 'source_group_id' => 1, 'type' => 'live',
    ]);

    Livewire::test(ListBouquets::class)
        ->callAction('create', data: [
            'name' => 'My Bouquet',
            'target_type' => 'playlist',
            'target_id' => $this->playlist->id,
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => null,
            'group_selections' => ['selected_groups' => [$sports->id]],
        ])
        ->assertHasNoActionErrors();

    $bouquet = Bouquet::where('name', 'My Bouquet')->firstOrFail();
    expect($bouquet->playlist_id)->toBe($this->playlist->id)
        ->and($bouquet->getSelectedLiveGroupNames())->toBe(['Sports']);
});

it('rejects a duplicate name on the same playlist', function () {
    Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'name' => 'Dup']);

    Livewire::test(ListBouquets::class)
        ->callAction('create', data: [
            'name' => 'Dup',
            'target_type' => 'playlist',
            'target_id' => $this->playlist->id,
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => null,
        ])
        ->assertHasActionErrors(['name']);
});

it('preserves a stale stored name across an edit save (never-silently-shrink)', function () {
    SourceGroup::create([
        'name' => 'Alive', 'playlist_id' => $this->playlist->id, 'source_group_id' => 1, 'type' => 'live',
    ]);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Alive', 'Vanished']],
    ]);

    Livewire::test(ListBouquets::class)
        ->callTableAction('edit', $bouquet, data: [
            'name' => $bouquet->name,
        ])
        ->assertHasNoTableActionErrors();

    expect($bouquet->refresh()->getSelectedLiveGroupNames())
        ->toEqualCanonicalizing(['Alive', 'Vanished']);
});

it('cleans up stale names via the table action', function () {
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Vanished']],
    ]);

    Livewire::test(ListBouquets::class)
        ->callTableAction('clean_up_missing', $bouquet);

    expect($bouquet->refresh()->getSelectedLiveGroupNames())->toBe([]);
});

it('leaves group_selections unchanged when cleaning up a bouquet with no stale names', function () {
    SourceGroup::create([
        'name' => 'Alive', 'playlist_id' => $this->playlist->id, 'source_group_id' => 1, 'type' => 'live',
    ]);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Alive']],
    ]);

    Livewire::test(ListBouquets::class)
        ->callTableAction('clean_up_missing', $bouquet);

    expect($bouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Alive']);
});
