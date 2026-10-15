<?php

use App\Models\AedProfile;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->profile = AedProfile::factory()->for($this->user)->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->otherPlaylist = Playlist::factory()->for($this->user)->create();
});

function seedEpgCacheFiles(...$playlists): void
{
    foreach ($playlists as $playlist) {
        Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist, false), '<tv/>');
        Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist, true), 'gz');
    }
}

function epgCacheExists($playlist): bool
{
    return Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist, false))
        || Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist, true));
}

it('clears the EPG cache of playlists using the profile on a channel when the profile is updated', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create([
        'aed_profile_id' => $this->profile->id,
    ]);
    $custom = CustomPlaylist::factory()->for($this->user)->create();
    $custom->channels()->attach($channel->id);
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($this->playlist->id);

    seedEpgCacheFiles($this->playlist, $this->otherPlaylist, $custom, $merged);

    $this->profile->update(['event_duration_minutes' => 90]);

    expect(epgCacheExists($this->playlist))->toBeFalse()
        ->and(epgCacheExists($custom))->toBeFalse()
        ->and(epgCacheExists($merged))->toBeFalse()
        ->and(epgCacheExists($this->otherPlaylist))->toBeTrue();
});

it('clears the EPG cache of playlists using the profile through a group when the profile is deleted', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create([
        'aed_profile_id' => $this->profile->id,
    ]);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create();
    $custom = CustomPlaylist::factory()->for($this->user)->create();
    $custom->channels()->attach($channel->id);

    seedEpgCacheFiles($this->playlist, $this->otherPlaylist, $custom);

    $this->profile->delete();

    expect($group->fresh()->aed_profile_id)->toBeNull()
        ->and(epgCacheExists($this->playlist))->toBeFalse()
        ->and(epgCacheExists($custom))->toBeFalse()
        ->and(epgCacheExists($this->otherPlaylist))->toBeTrue();
});

it('leaves EPG caches alone when an unassigned profile changes', function () {
    seedEpgCacheFiles($this->playlist);

    $this->profile->update(['event_duration_minutes' => 90]);
    $this->profile->delete();

    expect(epgCacheExists($this->playlist))->toBeTrue();
});
