<?php

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('rejects an invalid regex pattern in included_group_prefixes when use_regex is enabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm([
            'import_prefs' => [
                'preprocess' => true,
                'use_regex' => true,
                'included_group_prefixes' => ['^(?!VOD).*$', '^(?!*Kids).*$', '^(?!SRS).*$'],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['import_prefs.included_group_prefixes']);
});

it('accepts valid regex patterns in included_group_prefixes when use_regex is enabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm([
            'import_prefs' => [
                'preprocess' => true,
                'use_regex' => true,
                'included_group_prefixes' => ['^(?!VOD).*$', '^(?!Kids).*$', '^(?!SRS).*$'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors(['import_prefs.included_group_prefixes']);
});

it('does not validate included_group_prefixes as regex when use_regex is disabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->fillForm([
            'import_prefs' => [
                'preprocess' => true,
                'use_regex' => false,
                'included_group_prefixes' => ['^(?!*not a real regex intentionally broken'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors(['import_prefs.included_group_prefixes']);
});
