<?php

/**
 * Issue #1748: the Group API's move-channels action must reject a merged group as a
 * target, the same way direct model assignment is already guarded - a merged group
 * owns no channels directly, so a successful move would create ownership invisible
 * to the merged parent's own enable/disable actions.
 *
 * Issue #1749: the Group API's type-change action must reject changing a merged
 * group's type while it still has child groups, the same invariant
 * Group::assertMergedGroupRulesHold() already enforces when a child is saved.
 */

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test', ['view', 'create', 'update', 'delete'])->plainTextToken;
    $this->playlist = Playlist::factory()->for($this->user)->create(['dummy_epg' => false]);
});

it('rejects moving channels into a merged group', function () {
    $merged = Group::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
        'is_merged' => true,
    ]);
    $child = Group::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
        'parent_id' => $merged->id,
    ]);
    $source = Group::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
    ]);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($source)->create([
        'enabled' => true,
        'is_vod' => false,
    ]);

    $this->withToken($this->token)
        ->postJson("/group/{$source->id}/move-channels", ['target_group_id' => $merged->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Target group cannot be a merged group');

    expect($channel->fresh()->group_id)->toBe($source->id);
    expect($child->fresh()->id)->toBe($child->id);
});

it('still allows moving channels into an ordinary non-merged group', function () {
    $target = Group::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
    ]);
    $source = Group::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
    ]);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($source)->create([
        'enabled' => true,
        'is_vod' => false,
    ]);

    $this->withToken($this->token)
        ->postJson("/group/{$source->id}/move-channels", ['target_group_id' => $target->id])
        ->assertOk()
        ->assertJsonPath('data.moved_channels', 1);

    expect($channel->fresh()->group_id)->toBe($target->id);
});

it('rejects changing a merged group\'s type while it has child groups', function () {
    $merged = Group::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
        'is_merged' => true,
    ]);
    Group::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
        'parent_id' => $merged->id,
    ]);

    $this->withToken($this->token)
        ->patchJson("/group/{$merged->id}", ['type' => 'vod'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot change a merged group\'s type while it has child groups');

    expect($merged->fresh()->type)->toBe('live');
});

it('still allows changing an empty merged group\'s type', function () {
    $merged = Group::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
        'is_merged' => true,
    ]);

    $this->withToken($this->token)
        ->patchJson("/group/{$merged->id}", ['type' => 'vod'])
        ->assertOk();

    expect($merged->fresh()->type)->toBe('vod');
});
