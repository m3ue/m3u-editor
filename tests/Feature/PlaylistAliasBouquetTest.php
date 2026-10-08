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
use App\Filament\Resources\PlaylistAliases\Pages\ListPlaylistAliases;
use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SourceGroup;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
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

it('loads assigned bouquets in the order they were assigned', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $first = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'name' => 'Alpha']);
    $second = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'name' => 'Beta']);
    $alias->bouquets()->attach($second->id);
    $alias->bouquets()->attach($first->id);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful()
        ->assertSchemaStateSet(['bouquets' => [(string) $second->id, (string) $first->id]]);
});

it('saves newly assigned bouquets in the order they were picked', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $bouquets = collect(['France', 'Belgium', 'Africa'])->map(fn (string $name) => Bouquet::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'name' => $name,
    ]));
    $picked = [(string) $bouquets[2]->id, (string) $bouquets[0]->id, (string) $bouquets[1]->id];

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->fillForm([
            'xtream_config' => [['url' => 'http://example.com:8080', 'username' => 'alias-user', 'password' => 'alias-pass']],
            'bouquets' => $picked,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($alias->bouquets()->orderByPivot('id')->pluck('bouquets.id')->map(fn ($id) => (string) $id)->all())->toBe($picked);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSchemaStateSet(['bouquets' => $picked]);
});

it('clears all assigned bouquets from the hint action', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $alias->bouquets()->attach($bouquet->id);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->callAction(TestAction::make('clear_bouquets')->schemaComponent('bouquets'))
        ->assertSchemaStateSet(['bouquets' => []]);
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

it('detaches the previous playlist\'s bouquets when the alias is saved against a different playlist', function () {
    // Regression: the form cleared the field on a playlist switch, but Filament's
    // relationship save only detaches within the new target's options, so the old
    // playlist's bouquets stayed attached (and kept filtering) after the save.
    $alias = makeFormAlias($this->user, $this->playlist);
    $oldBouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Sports']],
    ]);
    $alias->bouquets()->sync([$oldBouquet->id]);

    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $newBouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $otherPlaylist->id,
        'group_selections' => ['selected_groups' => ['News']],
    ]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->fillForm(['source_id' => $otherPlaylist->id])
        // The switch seeds a blank provider credentials row; fill it so the save validates.
        ->fillForm([
            'xtream_config' => [['url' => 'http://example.com:8080', 'username' => 'alias-user', 'password' => 'alias-pass']],
            'bouquets' => [(string) $newBouquet->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $alias->refresh();

    expect($alias->playlist_id)->toBe($otherPlaylist->id)
        ->and($alias->bouquets()->pluck('bouquets.id')->all())->toBe([$newBouquet->id])
        ->and($alias->getAllowedLiveGroupNames())->toBe(['News']);
});

it('detaches bouquets of the previous target when an alias is repointed outside the form', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $alias->bouquets()->sync([$bouquet->id]);

    $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
    $alias->update(['playlist_id' => null, 'custom_playlist_id' => $custom->id]);

    expect($alias->bouquets()->count())->toBe(0);

    // Saving without a target change leaves matching bouquets alone.
    $customBouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => null, 'custom_playlist_id' => $custom->id,
    ]);
    $alias->bouquets()->sync([$customBouquet->id]);
    $alias->update(['name' => 'Renamed Alias']);

    expect($alias->bouquets()->pluck('bouquets.id')->all())->toBe([$customBouquet->id]);
});

it('removes bouquets left attached from an alias\'s previous playlist (data fix migration)', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $matching = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $alias->bouquets()->sync([$matching->id]);

    // Simulate a pivot row left behind by the old form behaviour: the bouquet
    // targets a different playlist than the alias. Inserted raw because the
    // pivot's attach guard rejects it.
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $leftover = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $otherPlaylist->id]);
    DB::table('bouquet_playlist_alias')->insert(['bouquet_id' => $leftover->id, 'playlist_alias_id' => $alias->id]);

    $migration = require database_path('migrations/2026_10_06_152330_detach_mismatched_alias_bouquets.php');
    $migration->up();

    expect($alias->bouquets()->pluck('bouquets.id')->all())->toBe([$matching->id]);
});

it('does not leak another user\'s bouquet contribution when the bouquets state is tampered', function () {
    // Simulates a forged Livewire update setting data.bouquets to an id the
    // relationship Select would never offer (the Select's own modifyQueryUsing
    // scopes options to this user + target). bouquetContributedNames() and the
    // contribution callout must independently scope their read, or a tampered
    // request could leak another user's group/category names via the picker
    // badges or the callout's counts.
    $alias = makeFormAlias($this->user, $this->playlist);

    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $foreignBouquet = Bouquet::factory()->create([
        'user_id' => $otherUser->id,
        'playlist_id' => $otherPlaylist->id,
        'group_selections' => [
            'selected_groups' => ['Secret Live Group'],
            'selected_vod_groups' => ['Secret VOD Group'],
            'selected_categories' => ['Secret Category'],
        ],
    ]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful()
        ->set('data.bouquets', [$foreignBouquet->id])
        ->assertSuccessful()
        ->assertSee('Assigned bouquets contribute 0 live groups, 0 VOD groups, and 0 series categories in addition to your manual selections.')
        ->assertDontSee('Secret Live Group')
        ->assertDontSee('Secret VOD Group')
        ->assertDontSee('Secret Category');
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

it('copies attached bouquets when duplicating an alias', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $bouquets = Bouquet::factory()->count(2)->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $alias->bouquets()->sync($bouquets->pluck('id'));

    Livewire::test(ListPlaylistAliases::class)
        ->callTableAction('duplicate', $alias, data: ['name' => 'Form Alias Copy'])
        ->assertHasNoTableActionErrors();

    $copy = PlaylistAlias::where('name', 'Form Alias Copy')->sole();

    expect($copy->bouquets()->pluck('bouquets.id')->all())->toEqualCanonicalizing($bouquets->pluck('id')->all())
        ->and($alias->bouquets()->count())->toBe(2);
});
