<?php

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create(['dummy_epg' => false]);
    $this->username = 'testuser_'.Str::random(5);
    $this->password = 'testpass';

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($playlistAuth);
});

/**
 * Build a merged group with two child groups, each holding one enabled live channel.
 *
 * @return array{merged: Group, denmark: Group, norway: Group, dkChannel: Channel, noChannel: Channel}
 */
function makeNordicsMerge(User $user, Playlist $playlist): array
{
    $merged = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Nordics',
        'name_internal' => 'Nordics',
        'type' => 'live',
        'custom' => true,
        'is_merged' => true,
        'sort_order' => 1,
    ]);

    $denmark = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Denmark', 'name_internal' => 'Denmark', 'type' => 'live', 'sort_order' => 10, 'parent_id' => $merged->id,
    ]);
    $norway = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Norway', 'name_internal' => 'Norway', 'type' => 'live', 'sort_order' => 11, 'parent_id' => $merged->id,
    ]);

    $dkChannel = Channel::factory()->for($playlist)->for($denmark)->create([
        'user_id' => $user->id, 'enabled' => true, 'is_vod' => false, 'title_custom' => 'DR1', 'group' => 'Denmark', 'group_internal' => 'Denmark',
    ]);
    $noChannel = Channel::factory()->for($playlist)->for($norway)->create([
        'user_id' => $user->id, 'enabled' => true, 'is_vod' => false, 'title_custom' => 'NRK1', 'group' => 'Norway', 'group_internal' => 'Norway',
    ]);

    return compact('merged', 'denmark', 'norway', 'dkChannel', 'noChannel');
}

function mgXtreamUrl(string $username, string $password, string $action, array $params = []): string
{
    return route('xtream.api.player').'?'.http_build_query(array_merge([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ], $params));
}

it('exposes the effective name and id of a folded group', function () {
    ['merged' => $merged, 'denmark' => $denmark] = makeNordicsMerge($this->user, $this->playlist);

    expect($denmark->effective_name)->toBe('Nordics')
        ->and($denmark->effective_id)->toBe($merged->id)
        ->and($denmark->fresh()->name)->toBe('Denmark');
});

it('emits the merged group name as group-title in M3U output', function () {
    makeNordicsMerge($this->user, $this->playlist);

    $response = $this->get(route('playlist.generate', [
        'uuid' => $this->playlist->uuid,
        'username' => $this->username,
        'password' => $this->password,
    ]));
    $response->assertSuccessful();
    $content = $response->streamedContent();

    expect($content)->toContain('group-title="Nordics"')
        ->and($content)->not->toContain('group-title="Denmark"')
        ->and($content)->not->toContain('group-title="Norway"');
});

it('lists a merged group once and hides its children in get_live_categories', function () {
    ['merged' => $merged] = makeNordicsMerge($this->user, $this->playlist);

    $categories = $this->getJson(mgXtreamUrl($this->username, $this->password, 'get_live_categories'))
        ->assertOk()
        ->json();

    $names = collect($categories)->pluck('category_name');
    expect($names)->toContain('Nordics')
        ->and($names)->not->toContain('Denmark')
        ->and($names)->not->toContain('Norway');

    $nordics = collect($categories)->firstWhere('category_name', 'Nordics');
    expect($nordics['category_id'])->toBe((string) $merged->id);
});

it('reports the merged group id as each folded channel category in get_live_streams', function () {
    ['merged' => $merged] = makeNordicsMerge($this->user, $this->playlist);

    $streams = $this->getJson(mgXtreamUrl($this->username, $this->password, 'get_live_streams'))
        ->assertOk()
        ->json();

    expect(collect($streams)->pluck('category_id')->unique()->all())->toBe([(string) $merged->id]);
});

it('returns every folded child channel when filtering streams by the merged category id', function () {
    ['merged' => $merged, 'dkChannel' => $dkChannel, 'noChannel' => $noChannel] = makeNordicsMerge($this->user, $this->playlist);

    $streams = $this->getJson(mgXtreamUrl($this->username, $this->password, 'get_live_streams', [
        'category_id' => $merged->id,
    ]))->assertOk()->json();

    expect(collect($streams)->pluck('stream_id')->sort()->values()->all())
        ->toBe(collect([$dkChannel->id, $noChannel->id])->sort()->values()->all());
});

it('rejects assigning a channel to a merged group', function () {
    ['merged' => $merged, 'dkChannel' => $dkChannel] = makeNordicsMerge($this->user, $this->playlist);

    expect(fn () => $dkChannel->update(['group_id' => $merged->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects nesting a merged group under another merged group', function () {
    ['merged' => $merged] = makeNordicsMerge($this->user, $this->playlist);

    $other = Group::factory()->for($this->user)->for($this->playlist)->make([
        'name' => 'Region', 'name_internal' => 'Region', 'type' => 'live', 'custom' => true, 'is_merged' => true, 'parent_id' => $merged->id,
    ]);

    expect(fn () => $other->save())->toThrow(InvalidArgumentException::class);
});

it('rejects folding a group into a non-merged group', function () {
    $plain = Group::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Plain', 'name_internal' => 'Plain', 'type' => 'live',
    ]);
    $child = Group::factory()->for($this->user)->for($this->playlist)->make([
        'name' => 'Child', 'name_internal' => 'Child', 'type' => 'live', 'parent_id' => $plain->id,
    ]);

    expect(fn () => $child->save())->toThrow(InvalidArgumentException::class);
});

it('keeps parent_id when a provider-style group update touches other columns', function () {
    ['denmark' => $denmark, 'merged' => $merged] = makeNordicsMerge($this->user, $this->playlist);

    // Mirrors the import chain updating an existing group row (name_internal / new / batch).
    $denmark->update(['import_batch_no' => 'batch-2', 'new' => false]);

    expect($denmark->fresh()->parent_id)->toBe($merged->id);
});

it('excludes merged groups from the assignable target scope', function () {
    ['merged' => $merged, 'denmark' => $denmark] = makeNordicsMerge($this->user, $this->playlist);

    $ids = Group::query()->assignableTarget()->pluck('id');

    expect($ids)->toContain($denmark->id)
        ->and($ids)->not->toContain($merged->id);
});
