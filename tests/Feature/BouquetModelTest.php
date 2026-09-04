<?php

use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\SourceGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Spatie\Tags\Tag;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

describe('Bouquet target guard', function () {
    it('creates a standard-target bouquet', function () {
        $bouquet = Bouquet::create([
            'name' => 'Sports',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Sports HD']],
        ]);

        expect($bouquet->playlist_id)->toBe($this->playlist->id)
            ->and($bouquet->custom_playlist_id)->toBeNull()
            ->and($bouquet->getSelectedLiveGroupNames())->toBe(['Sports HD']);
    });

    it('creates a custom-target bouquet and normalizes auto-include to false', function () {
        $custom = CustomPlaylist::factory()->for($this->user)->create();

        $bouquet = Bouquet::create([
            'name' => 'Family',
            'user_id' => $this->user->id,
            'custom_playlist_id' => $custom->id,
            'auto_include_new_live' => true,
            'auto_include_new_vod' => true,
        ]);

        expect($bouquet->custom_playlist_id)->toBe($custom->id)
            ->and($bouquet->auto_include_new_live)->toBeFalse()
            ->and($bouquet->auto_include_new_vod)->toBeFalse();
    });

    it('rejects a bouquet with no target', function () {
        Bouquet::create(['name' => 'Orphan', 'user_id' => $this->user->id]);
    })->throws(InvalidArgumentException::class);

    it('rejects a bouquet with both targets', function () {
        $custom = CustomPlaylist::factory()->for($this->user)->create();

        Bouquet::create([
            'name' => 'Both',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => $custom->id,
        ]);
    })->throws(InvalidArgumentException::class);
});

describe('Bouquet target ownership guard', function () {
    it('rejects a bouquet whose playlist_id targets another user\'s playlist', function () {
        $otherUser = User::factory()->create();
        $othersPlaylist = Playlist::factory()->for($otherUser)->create();

        Bouquet::create([
            'name' => 'Forged',
            'user_id' => $this->user->id,
            'playlist_id' => $othersPlaylist->id,
        ]);
    })->throws(InvalidArgumentException::class);

    it('rejects a bouquet whose custom_playlist_id targets another user\'s custom playlist', function () {
        $otherUser = User::factory()->create();
        $othersCustom = CustomPlaylist::factory()->for($otherUser)->create();

        Bouquet::create([
            'name' => 'Forged',
            'user_id' => $this->user->id,
            'custom_playlist_id' => $othersCustom->id,
        ]);
    })->throws(InvalidArgumentException::class);
});

describe('Bouquet uniqueness and cascade', function () {
    it('enforces unique name per standard playlist but allows reuse across playlists', function () {
        Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

        $other = Playlist::factory()->for($this->user)->create();
        $reused = Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'playlist_id' => $other->id]);
        expect($reused)->not->toBeNull();

        Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    })->throws(QueryException::class);

    it('allows the same name on a standard and a custom target', function () {
        $custom = CustomPlaylist::factory()->for($this->user)->create();

        Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
        $customBouquet = Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'custom_playlist_id' => $custom->id]);

        expect($customBouquet->exists)->toBeTrue();
    });

    it('cascades bouquet deletion when the target playlist is deleted', function () {
        $bouquet = Bouquet::create(['name' => 'Doomed', 'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

        $this->playlist->delete();

        expect(Bouquet::find($bouquet->id))->toBeNull();
    });

    it('auto-assigns user_id from the authenticated user', function () {
        $this->actingAs($this->user);

        $bouquet = Bouquet::create(['name' => 'Mine', 'playlist_id' => $this->playlist->id]);

        expect($bouquet->user_id)->toBe($this->user->id);
    });
});

function makeBouquetTestAlias(User $user, Playlist $playlist, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Test Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ], $overrides));
}

describe('Bouquet attachment invariant', function () {
    it('attaches a standard-target bouquet to an alias of the same playlist', function () {
        $alias = makeBouquetTestAlias($this->user, $this->playlist);
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

        $alias->bouquets()->attach($bouquet);

        expect($alias->bouquets()->count())->toBe(1)
            ->and($bouquet->playlistAliases()->count())->toBe(1);
    });

    it('rejects attaching a bouquet of a different playlist', function () {
        $alias = makeBouquetTestAlias($this->user, $this->playlist);
        $other = Playlist::factory()->for($this->user)->create();
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $other->id]);

        $alias->bouquets()->attach($bouquet);
    })->throws(InvalidArgumentException::class);

    it('rejects attaching a standard-target bouquet to a custom-playlist alias', function () {
        $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
        $alias = makeBouquetTestAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
        ]);
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

        $alias->bouquets()->attach($bouquet);
    })->throws(InvalidArgumentException::class);

    it('attaches a custom-target bouquet to an alias of the same custom playlist', function () {
        $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
        $alias = makeBouquetTestAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
        ]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
        ]);

        $alias->bouquets()->attach($bouquet);

        expect($alias->bouquets()->count())->toBe(1);
    });

    it('rejects attaching anything to a merged-playlist alias', function () {
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
        $merged = MergedPlaylist::create(['name' => 'MP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
        $alias = makeBouquetTestAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'merged_playlist_id' => $merged->id,
        ]);

        $alias->bouquets()->attach($bouquet);
    })->throws(InvalidArgumentException::class);

    it('cascades pivot rows when the bouquet is deleted', function () {
        $alias = makeBouquetTestAlias($this->user, $this->playlist);
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($bouquet);

        $bouquet->delete();

        expect($alias->bouquets()->count())->toBe(0);
    });
});

describe('stale selection detection', function () {
    it('reports and removes names that no longer resolve for a standard target', function () {
        SourceGroup::create([
            'name' => 'Alive', 'playlist_id' => $this->playlist->id, 'source_group_id' => 1, 'type' => 'live',
        ]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => [
                'selected_groups' => ['Alive', 'Gone'],
                'selected_vod_groups' => ['Alive'],  // vod namespace: 'Alive' has no vod SourceGroup -> stale there
            ],
        ]);

        expect($bouquet->staleSelectionNames())->toEqualCanonicalizing(['Gone', 'Alive']);

        $bouquet->removeStaleSelectionNames();
        $bouquet->refresh();

        expect($bouquet->getSelectedLiveGroupNames())->toBe(['Alive'])
            ->and($bouquet->getSelectedVodGroupNames())->toBe([]);
    });

    it('reports and removes names that no longer resolve for a custom target', function () {
        $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);

        // Live tag: 'Live Tag' exists (attached to an enabled untagged-fallback-free
        // channel); 'Gone Tag' was a tag name that no longer exists (deleted/re-tagged).
        $taggedChannel = Channel::factory()->for($this->playlist)->for($this->user)->create([
            'is_vod' => false, 'enabled' => true, 'group' => null,
        ]);
        $custom->channels()->attach($taggedChannel->id);
        $liveTag = Tag::findOrCreate('Live Tag', $custom->uuid);
        $taggedChannel->attachTag($liveTag);

        // Fallback provider-group name: 'Fallback Alive' is carried by an untagged
        // enabled channel (resolvable); 'Fallback Gone' is carried by no channel (stale).
        $fallbackChannel = Channel::factory()->for($this->playlist)->for($this->user)->create([
            'is_vod' => false, 'enabled' => true, 'group' => 'Fallback Alive',
        ]);
        $custom->channels()->attach($fallbackChannel->id);

        // Category tag: 'Cat Alive' exists; 'Cat Gone' was deleted/re-tagged.
        $series = Series::factory()->for($this->user)->for($this->playlist)->create(['enabled' => true]);
        $custom->series()->attach($series->id);
        $catTag = Tag::findOrCreate('Cat Alive', $custom->uuid.'-category');
        $series->attachTag($catTag);

        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
            'group_selections' => [
                'selected_groups' => ['Live Tag', 'Gone Tag', 'Fallback Alive', 'Fallback Gone'],
                'selected_categories' => ['Cat Alive', 'Cat Gone'],
            ],
        ]);

        expect($bouquet->staleSelectionNames())->toEqualCanonicalizing(['Gone Tag', 'Fallback Gone', 'Cat Gone']);

        $bouquet->removeStaleSelectionNames();
        $bouquet->refresh();

        expect($bouquet->getSelectedLiveGroupNames())->toEqualCanonicalizing(['Live Tag', 'Fallback Alive'])
            ->and($bouquet->getSelectedCategoryNames())->toBe(['Cat Alive']);
    });
});
