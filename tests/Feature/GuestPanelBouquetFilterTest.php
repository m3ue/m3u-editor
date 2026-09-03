<?php

declare(strict_types=1);

/**
 * Tests for Task 10 of the Playlist Bouquets feature (issue #1391): downstream
 * propagation into the guest panel.
 *
 * The four PlaylistAlias accessors (getAllowedVodGroupNames(), getAllowedCategoryNames(),
 * etc.) union attached bouquets' selections into the manual group_filter, and every
 * consumer that already reads those accessors inherits bouquet filtering automatically.
 * VodResource and SeriesResource in the guest panel (App\Filament\GuestPanel\Resources)
 * already call getAllowedVodGroupNames()/getAllowedCategoryNames() on a resolved
 * PlaylistAlias — see VodResource::getEloquentQuery() around line 111 and the mirrored
 * branch in SeriesResource — so no guest-panel-specific code was needed for bouquets to
 * reach here; this file pins that it actually does.
 */

use App\Filament\GuestPanel\Resources\Series\SeriesResource;
use App\Filament\GuestPanel\Resources\Vods\VodResource;
use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\SourceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Mirrors GuestBrowseShowsTest's setupGuestDvrContext(): sets the request attribute and
 * session keys HasPlaylist::getCurrentUuid()/getCurrentAuth() read so a direct call to a
 * guest-panel Resource's static query method resolves as if it were serving a real guest
 * request — without going through Livewire::test(), which (per GuestBrowseShowsTest's own
 * skip note) creates a synthetic request that cannot carry request-attribute context.
 *
 * The one adaptation from GuestBrowseShowsTest's harness: this keys the session/attribute
 * off the ALIAS's uuid rather than a base Playlist's, since PlaylistFacade::resolvePlaylistByUuid()
 * must resolve a PlaylistAlias instance for VodResource/SeriesResource::getEloquentQuery()
 * to take the alias branch (and therefore apply the bouquet-unioned accessors) at all.
 */
function setupGuestAliasContext(PlaylistAlias $alias): void
{
    request()->attributes->set('playlist_uuid', $alias->uuid);

    $prefix = base64_encode($alias->uuid).'_';
    session()->put("{$prefix}guest_auth_username", $alias->username);
    session()->put("{$prefix}guest_auth_password", $alias->password);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->alias = PlaylistAlias::create([
        'name' => 'Guest Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'xtream_config' => null,
        'username' => 'guest_alias_user',
        'password' => 'guest_alias_pass',
    ]);

    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => [
            'selected_vod_groups' => ['Movies'],
            'selected_categories' => ['Drama'],
        ],
    ]);
    $this->alias->bouquets()->attach($bouquet);
    $this->alias->refresh();

    setupGuestAliasContext($this->alias);
});

it('lists only the bouquet-union-permitted VOD channels for a bouquet-attached alias', function () {
    $allowedMovie = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_internal' => 'Movies', 'is_vod' => true, 'enabled' => true,
    ]);
    $excludedDocumentary = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_internal' => 'Documentaries', 'is_vod' => true, 'enabled' => true,
    ]);

    $ids = VodResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($allowedMovie->id)
        ->and($ids)->not->toContain($excludedDocumentary->id);
});

it('lists only the bouquet-union-permitted series for a bouquet-attached alias', function () {
    SourceCategory::create([
        'playlist_id' => $this->playlist->id, 'name' => 'Drama', 'source_category_id' => 11,
    ]);
    SourceCategory::create([
        'playlist_id' => $this->playlist->id, 'name' => 'Comedy', 'source_category_id' => 22,
    ]);

    $allowedDrama = Series::factory()->for($this->playlist)->for($this->user)->create([
        'source_category_id' => 11, 'enabled' => true,
    ]);
    $excludedComedy = Series::factory()->for($this->playlist)->for($this->user)->create([
        'source_category_id' => 22, 'enabled' => true,
    ]);

    $ids = SeriesResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($allowedDrama->id)
        ->and($ids)->not->toContain($excludedComedy->id);
});
