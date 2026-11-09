<?php

/**
 * A Custom Playlist's Xtream category_id for a member with a custom tag is the
 * tag's own primary key, while a member without one falls back to its original
 * provider group/category's primary key (see XtreamApiController's
 * get_live_categories/get_vod_categories/get_series_categories and the matching
 * stream-filter branches). `tags` and `groups`/`categories` are independent
 * auto-increment sequences, so those raw ids can collide and make the two
 * logical groups indistinguishable - this suite reproduces that collision and
 * asserts XtreamCategoryService's reserved fallback-id range (issue #1670)
 * keeps them apart.
 */

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    // The fallback provider group and the custom tag are rows in independent
    // auto-increment sequences. On a fresh database they'd naturally land on the
    // same primary key, but RefreshDatabase doesn't reset sequences between tests
    // in the same worker process, and padding one sequence up to meet the other
    // proved unreliable under a real full-suite/parallel run (something keeps
    // advancing one side between our read and our inserts). Forcing both rows
    // onto the same explicit, unused id sidesteps that race entirely - model
    // factories bypass mass-assignment guarding (Model::unguarded()), so 'id'
    // can be set directly regardless of either model's $guarded/$fillable.
    $collisionId = (int) max(
        DB::table('groups')->max('id') ?? 0,
        DB::table('tags')->max('id') ?? 0,
    ) + 1;

    $this->fallbackGroup = Group::factory()->for($this->user)->for($this->playlist)->create([
        'id' => $collisionId,
        'type' => 'live',
        'name' => 'Provider fallback',
    ]);

    $this->taggedChannel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true, 'is_vod' => false, 'title' => 'Tagged Channel',
    ]);
    $this->fallbackChannel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->fallbackGroup)->create([
        'enabled' => true, 'is_vod' => false, 'title' => 'Fallback Channel',
    ]);

    $this->customPlaylist->channels()->attach($this->taggedChannel->id);
    $this->customPlaylist->channels()->attach($this->fallbackChannel->id);

    $this->tag = Tag::create([
        'id' => $collisionId,
        'name' => [app()->getLocale() => 'My Sports'],
        'type' => $this->customPlaylist->uuid,
    ]);
    $this->customPlaylist->attachTag($this->tag);
    $this->taggedChannel->attachTag($this->tag);

    expect($this->tag->id)->toBe($this->fallbackGroup->id); // sanity check: the collision is real
});

function customPlaylistApiUrl(User $user, CustomPlaylist $playlist, string $action, array $params = []): string
{
    $params = array_merge([
        'username' => $user->name,
        'password' => $playlist->uuid,
        'action' => $action,
    ], $params);

    return '/player_api.php?'.http_build_query($params);
}

it('keeps a custom tag category and a same-id provider fallback category both listed and distinct', function () {
    $categories = $this->getJson(customPlaylistApiUrl($this->user, $this->customPlaylist, 'get_live_categories'))
        ->assertStatus(200)
        ->json();

    expect($categories)->toHaveCount(2);

    $ids = array_column($categories, 'category_id');
    expect($ids)->toEqual(array_unique($ids)); // no collision between the tag and fallback entries

    $names = array_combine($ids, array_column($categories, 'category_name'));
    expect($names)->toContain('My Sports')
        ->and($names)->toContain('Provider fallback');
});

it('returns only the tagged channel when the custom tag category is selected, not the unrelated fallback member', function () {
    $categories = $this->getJson(customPlaylistApiUrl($this->user, $this->customPlaylist, 'get_live_categories'))->json();
    $tagCategoryId = collect($categories)->firstWhere('category_name', 'My Sports')['category_id'];

    $streams = $this->getJson(customPlaylistApiUrl($this->user, $this->customPlaylist, 'get_live_streams', [
        'category_id' => $tagCategoryId,
    ]))->assertStatus(200)->json();

    expect($streams)->toHaveCount(1)
        ->and($streams[0]['name'])->toBe('Tagged Channel');
});

it('returns only the fallback channel when the provider fallback category is selected', function () {
    $categories = $this->getJson(customPlaylistApiUrl($this->user, $this->customPlaylist, 'get_live_categories'))->json();
    $fallbackCategoryId = collect($categories)->firstWhere('category_name', 'Provider fallback')['category_id'];

    $streams = $this->getJson(customPlaylistApiUrl($this->user, $this->customPlaylist, 'get_live_streams', [
        'category_id' => $fallbackCategoryId,
    ]))->assertStatus(200)->json();

    expect($streams)->toHaveCount(1)
        ->and($streams[0]['name'])->toBe('Fallback Channel');
});
