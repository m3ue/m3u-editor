<?php

use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Spatie\Tags\Tag;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
});

it('rewrites bouquet group selections (live and vod keys) when a group tag is renamed', function () {
    $tag = Tag::findOrCreate('Old Group', $this->custom->uuid);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $this->custom->id,
        'group_selections' => [
            'selected_groups' => ['Old Group', 'Other'],
            'selected_vod_groups' => ['Old Group'],
            'selected_categories' => ['Old Group'],  // same string, different namespace — must NOT change
        ],
    ]);

    $tag->setTranslation('name', 'en', 'New Group');
    $tag->save();

    $bouquet->refresh();
    expect($bouquet->getSelectedLiveGroupNames())->toBe(['New Group', 'Other'])
        ->and($bouquet->getSelectedVodGroupNames())->toBe(['New Group'])
        ->and($bouquet->getSelectedCategoryNames())->toBe(['Old Group']);
});

it('rewrites bouquet category selections when a category tag is renamed', function () {
    $tag = Tag::findOrCreate('Old Cat', $this->custom->uuid.'-category');
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $this->custom->id,
        'group_selections' => ['selected_categories' => ['Old Cat'], 'selected_groups' => ['Old Cat']],
    ]);

    $tag->setTranslation('name', 'en', 'New Cat');
    $tag->save();

    $bouquet->refresh();
    expect($bouquet->getSelectedCategoryNames())->toBe(['New Cat'])
        ->and($bouquet->getSelectedLiveGroupNames())->toBe(['Old Cat']);
});

it('rewrites alias manual group_filter for the same custom playlist', function () {
    $tag = Tag::findOrCreate('Old Group', $this->custom->uuid);
    $alias = PlaylistAlias::create([
        'name' => 'A', 'uuid' => fake()->uuid(), 'user_id' => $this->user->id,
        'playlist_id' => null, 'custom_playlist_id' => $this->custom->id,
        'xtream_config' => null,
        'group_filter' => ['selected_groups' => ['Old Group']],
    ]);

    $tag->setTranslation('name', 'en', 'New Group');
    $tag->save();

    expect($alias->refresh()->group_filter['selected_groups'])->toBe(['New Group']);
});

it('leaves other playlists and standard-target bouquets alone', function () {
    $otherCustom = CustomPlaylist::create(['name' => 'Other CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
    $tag = Tag::findOrCreate('Shared Name', $this->custom->uuid);

    $otherBouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $otherCustom->id,
        'group_selections' => ['selected_groups' => ['Shared Name']],
    ]);
    $standardBouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Shared Name']],
    ]);

    $tag->setTranslation('name', 'en', 'Renamed');
    $tag->save();

    expect($otherBouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Shared Name'])
        ->and($standardBouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Shared Name']);
});

it('ignores tags whose type is not a custom playlist uuid', function () {
    $tag = Tag::findOrCreate('Loose Tag', 'unrelated-type');
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $this->custom->id,
        'group_selections' => ['selected_groups' => ['Loose Tag']],
    ]);

    $tag->setTranslation('name', 'en', 'Renamed Loose');
    $tag->save();

    expect($bouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Loose Tag']);
});
