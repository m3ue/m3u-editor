<?php

use App\Jobs\DuplicateCustomPlaylist;
use App\Jobs\DuplicatePlaylist;
use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

it('DuplicateCustomPlaylist copies bouquets without alias attachments', function () {
    $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $custom->id,
        'group_selections' => ['selected_groups' => ['My Group']],
    ]);
    $alias = PlaylistAlias::create([
        'name' => 'A', 'uuid' => fake()->uuid(), 'user_id' => $this->user->id,
        'playlist_id' => null, 'custom_playlist_id' => $custom->id, 'xtream_config' => null,
    ]);
    $alias->bouquets()->attach($bouquet);

    (new DuplicateCustomPlaylist($custom, name: 'CP Copy'))->handle();

    $copy = CustomPlaylist::where('name', 'CP Copy')->firstOrFail();
    $copiedBouquet = Bouquet::where('custom_playlist_id', $copy->id)->firstOrFail();

    expect($copiedBouquet->group_selections)->toBe(['selected_groups' => ['My Group']])
        ->and($copiedBouquet->playlist_id)->toBeNull()
        ->and(DB::table('bouquet_playlist_alias')->where('bouquet_id', $copiedBouquet->id)->count())->toBe(0)
        // Source attachments and selections untouched.
        ->and($alias->bouquets()->count())->toBe(1)
        ->and($bouquet->refresh()->custom_playlist_id)->toBe($custom->id);
});

it('DuplicatePlaylist copies standard-target bouquets', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'group_selections' => ['selected_groups' => ['Sports'], 'selected_vod_groups' => ['Movies']],
        'auto_include_new_live' => true,
    ]);

    (new DuplicatePlaylist($playlist, name: 'P Copy'))->handle();

    $copy = Playlist::where('name', 'P Copy')->firstOrFail();
    $copiedBouquet = Bouquet::where('playlist_id', $copy->id)->firstOrFail();

    expect($copiedBouquet->getSelectedLiveGroupNames())->toBe(['Sports'])
        ->and($copiedBouquet->getSelectedVodGroupNames())->toBe(['Movies'])
        ->and($copiedBouquet->auto_include_new_live)->toBeTrue();
});
