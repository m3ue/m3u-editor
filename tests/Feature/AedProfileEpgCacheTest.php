<?php

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\AedProfile;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use App\Services\EpgCacheService;
use Filament\Actions\BulkAction;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection;
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

/**
 * #1790: assigning a profile (not editing the profile itself) must also clear
 * the cached output, or a warmed gzip guide keeps the inherited profile's
 * programmes while the guide-data API already shows the new one.
 */
it('clears the EPG cache when a channel switches from its group profile to its own', function () {
    $groupProfile = AedProfile::factory()->for($this->user)->create();
    $group = Group::factory()->for($this->playlist)->for($this->user)->create([
        'aed_profile_id' => $groupProfile->id,
    ]);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create();
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($this->playlist->id);
    $alias = PlaylistAlias::create([
        'name' => 'Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    seedEpgCacheFiles($this->playlist, $this->otherPlaylist, $merged, $alias);

    $channel->update(['aed_profile_id' => $this->profile->id]);

    expect(epgCacheExists($this->playlist))->toBeFalse()
        ->and(epgCacheExists($merged))->toBeFalse()
        ->and(epgCacheExists($alias))->toBeFalse()
        ->and(epgCacheExists($this->otherPlaylist))->toBeTrue();
});

it('clears the EPG cache when a group profile changes', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create();
    $custom = CustomPlaylist::factory()->for($this->user)->create();
    $custom->channels()->attach($channel->id);

    seedEpgCacheFiles($this->playlist, $this->otherPlaylist, $custom);

    $group->update(['aed_profile_id' => $this->profile->id]);

    expect(epgCacheExists($this->playlist))->toBeFalse()
        ->and(epgCacheExists($custom))->toBeFalse()
        ->and(epgCacheExists($this->otherPlaylist))->toBeTrue();
});

it('leaves the EPG cache alone when an unrelated group or channel field changes', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create();

    seedEpgCacheFiles($this->playlist);

    $group->update(['sort_order' => 5]);
    $channel->update(['name_custom' => 'Renamed']);

    expect(epgCacheExists($this->playlist))->toBeTrue();
});

/**
 * The channel bulk actions live inside BulkModalActionGroup's sectioned
 * schema, which the table test helpers can't resolve, so find the real
 * action there and run its closure.
 */
function aedChannelBulkAction(string $name): BulkAction
{
    $group = ChannelResource::getTableBulkActions()[0];
    $outerSchema = (new ReflectionProperty($group, 'schema'))->getValue($group);
    $childProp = new ReflectionProperty(Component::class, 'childComponents');

    foreach ($outerSchema as $component) {
        foreach ($childProp->getValue($component)['default'] ?? [] as $child) {
            if ($child instanceof BulkAction && $child->getName() === $name) {
                return $child;
            }
        }
    }

    throw new RuntimeException("Bulk action [{$name}] not found");
}

it('clears the EPG cache when the bulk actions set or remove channel profiles', function (string $action) {
    $this->actingAs($this->user);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'aed_profile_id' => $action === 'remove-aed-profile' ? $this->profile->id : null,
    ]);

    seedEpgCacheFiles($this->playlist, $this->otherPlaylist);

    (aedChannelBulkAction($action)->getActionFunction())(
        new Collection([$channel]),
        ['aed_profile_id' => $this->profile->id],
    );

    expect($channel->fresh()->aed_profile_id)->toBe($action === 'set-aed-profile' ? $this->profile->id : null)
        ->and(epgCacheExists($this->playlist))->toBeFalse()
        ->and(epgCacheExists($this->otherPlaylist))->toBeTrue();
})->with(['set-aed-profile', 'remove-aed-profile']);
