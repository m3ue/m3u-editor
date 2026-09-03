<?php

use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\QueryException;

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
