<?php

/**
 * Regression tests for PlaylistAliasPolicy against aliases of a custom playlist.
 *
 * Every method resolved the owner with $playlistAlias->playlist->user_id. That relation
 * is the belongsTo for a *standard* playlist and is null for an alias of a custom one, so
 * reading ->user_id on it raised a PHP warning that Laravel promotes to an ErrorException.
 * Admins never reached it because isAdmin() short-circuits, and canAccessPanel() lets
 * non-admins into the panel, so a non-admin owner could not open their own alias at all.
 */

use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

function makePolicyAlias(User $user, array $attributes): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Policy Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'xtream_config' => null,
    ], $attributes));
}

dataset('policy abilities', ['view', 'update', 'delete', 'restore', 'forceDelete']);

it('grants a non-admin owner every ability on an alias of a custom playlist', function (string $ability) {
    $user = User::factory()->create();
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();
    $alias = makePolicyAlias($user, ['custom_playlist_id' => $customPlaylist->id]);

    expect($user->isAdmin())->toBeFalse()
        ->and($alias->playlist)->toBeNull()
        ->and(Gate::forUser($user)->allows($ability, $alias))->toBeTrue();
})->with('policy abilities');

it('grants a non-admin owner every ability on an alias of a standard playlist', function (string $ability) {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $alias = makePolicyAlias($user, ['playlist_id' => $playlist->id]);

    expect(Gate::forUser($user)->allows($ability, $alias))->toBeTrue();
})->with('policy abilities');

it('denies a non-admin who does not own the alias', function (string $ability) {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $customPlaylist = CustomPlaylist::factory()->for($owner)->create();
    $alias = makePolicyAlias($owner, ['custom_playlist_id' => $customPlaylist->id]);

    expect(Gate::forUser($stranger)->allows($ability, $alias))->toBeFalse();
})->with('policy abilities');

it('still grants an admin every ability regardless of the alias target', function (string $ability) {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $customPlaylist = CustomPlaylist::factory()->for($owner)->create();
    $alias = makePolicyAlias($owner, ['custom_playlist_id' => $customPlaylist->id]);

    expect(Gate::forUser($admin)->allows($ability, $alias))->toBeTrue();
})->with('policy abilities');

it('lets a non-admin owner open the edit page for an alias of a custom playlist', function () {
    $user = User::factory()->create();
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();
    $alias = makePolicyAlias($user, ['custom_playlist_id' => $customPlaylist->id]);

    $this->actingAs($user);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful();
});
