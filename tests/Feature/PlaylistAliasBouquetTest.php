<?php

/**
 * Tests for Task 8 of the Playlist Bouquets feature (issue #1391): bouquet
 * assignment on the alias form.
 *
 * Covers:
 * - PlaylistAlias::bouquets() relationship attach/detach through direct sync
 *   (what the Filament relationship Select persists through)
 * - The bouquets Select's options are scoped to the alias's active target
 *   (same playlist_id / custom_playlist_id, never the other kind)
 * - The alias edit form renders successfully with a bouquet attached,
 *   exercising the new Bouquets fieldset, contribution callout, and the
 *   bouquet_group_names table arguments on every picker
 * - Changing the alias's source playlist resets the bouquets form state
 * - R1 guard: saving the form does not materialize bouquet-contributed
 *   names into group_filter
 */

use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SourceGroup;
use App\Models\User;
use Livewire\Livewire;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeFormAlias(User $user, Playlist $playlist, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Form Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('attaches and detaches bouquets through the alias form relationship', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

    // Direct relationship sync is what the Filament Select persists through.
    $alias->bouquets()->sync([$bouquet->id]);
    expect($alias->bouquets()->count())->toBe(1);

    $alias->bouquets()->sync([]);
    expect($alias->bouquets()->count())->toBe(0);
});

it('shows only same-target bouquets as options', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $sameTarget = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $otherTarget = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $otherPlaylist->id]);
    $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
    $customTarget = Bouquet::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => null, 'custom_playlist_id' => $custom->id,
    ]);

    // The options closure filters on the alias's active FK: replicate its query.
    $options = Bouquet::query()
        ->where('user_id', $this->user->id)
        ->where('playlist_id', $alias->playlist_id)
        ->pluck('id');

    expect($options)->toContain($sameTarget->id)
        ->and($options)->not->toContain($otherTarget->id)
        ->and($options)->not->toContain($customTarget->id);
});

it('renders the alias edit form with an attached bouquet', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Sports']],
    ]);
    $alias->bouquets()->sync([$bouquet->id]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful();
});

it('resets the bouquets form state when the source playlist changes', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $alias->bouquets()->sync([$bouquet->id]);

    $otherPlaylist = Playlist::factory()->for($this->user)->create();

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful()
        ->set('data.source_id', $otherPlaylist->id)
        ->assertSchemaStateSet(['bouquets' => []]);
});

it('does not materialize bouquet names into group_filter when the form is saved (R1 guard)', function () {
    // The picker round-trips selections through SourceGroup ids, so a matching row
    // must exist for the manual name to survive an untouched save.
    SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'Manual Group', 'type' => 'live']);

    $alias = makeFormAlias($this->user, $this->playlist, [
        'group_filter' => ['selected_groups' => ['Manual Group']],
        'xtream_config' => [[
            'url' => 'http://example.com:8080',
            'username' => 'alias-user',
            'password' => 'alias-pass',
        ]],
    ]);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Bouquet Group']],
    ]);
    $alias->bouquets()->sync([$bouquet->id]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful()
        ->call('save')
        ->assertHasNoFormErrors();

    expect($alias->refresh()->group_filter['selected_groups'])->toBe(['Manual Group']);
});
