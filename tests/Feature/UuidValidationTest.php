<?php

use App\Filament\Resources\MergedPlaylists\Pages\EditMergedPlaylist;
use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('Playlist uuid validation', function () {
    it('rejects special characters in uuid', function () {
        $playlist = Playlist::factory()->for($this->user)->create();

        Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
            ->fillForm([
                'edit_uuid' => true,
                'uuid' => 'invalid uuid!@#',
            ])
            ->call('save')
            ->assertHasFormErrors(['uuid' => 'regex']);
    });

    it('accepts url-safe characters in uuid', function () {
        $playlist = Playlist::factory()->for($this->user)->create();

        Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
            ->fillForm([
                'edit_uuid' => true,
                'uuid' => 'my-custom_playlist123',
            ])
            ->call('save')
            ->assertHasNoFormErrors(['uuid']);
    });
});

describe('PlaylistAlias uuid validation', function () {
    it('rejects special characters in uuid', function () {
        $playlist = Playlist::factory()->for($this->user)->create();
        $alias = PlaylistAlias::create([
            'name' => 'Test Alias',
            'uuid' => fake()->uuid(),
            'user_id' => $this->user->id,
            'playlist_id' => $playlist->id,
        ]);

        Livewire::test(EditPlaylistAlias::class, ['record' => $alias->id])
            ->fillForm([
                'edit_uuid' => true,
                'uuid' => 'bad uuid?here',
            ])
            ->call('save')
            ->assertHasFormErrors(['uuid' => 'regex']);
    });

    it('accepts url-safe characters in uuid', function () {
        $playlist = Playlist::factory()->for($this->user)->create();
        $alias = PlaylistAlias::create([
            'name' => 'Test Alias',
            'uuid' => fake()->uuid(),
            'user_id' => $this->user->id,
            'playlist_id' => $playlist->id,
        ]);

        Livewire::test(EditPlaylistAlias::class, ['record' => $alias->id])
            ->fillForm([
                'edit_uuid' => true,
                'uuid' => 'alias-safe_uuid99',
            ])
            ->call('save')
            ->assertHasNoFormErrors(['uuid']);
    });
});

describe('MergedPlaylist uuid validation', function () {
    it('rejects special characters in uuid', function () {
        $merged = MergedPlaylist::factory()->for($this->user)->create();

        Livewire::test(EditMergedPlaylist::class, ['record' => $merged->id])
            ->fillForm([
                'edit_uuid' => true,
                'uuid' => 'bad/uuid space',
            ])
            ->call('save')
            ->assertHasFormErrors(['uuid' => 'regex']);
    });

    it('accepts url-safe characters in uuid', function () {
        $merged = MergedPlaylist::factory()->for($this->user)->create();

        Livewire::test(EditMergedPlaylist::class, ['record' => $merged->id])
            ->fillForm([
                'edit_uuid' => true,
                'uuid' => 'merged-safe_123',
            ])
            ->call('save')
            ->assertHasNoFormErrors(['uuid']);
    });
});
