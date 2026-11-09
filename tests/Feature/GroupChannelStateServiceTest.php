<?php

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\GroupChannelStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->service = app(GroupChannelStateService::class);
});

it('enables a live group\'s channels and renumbers them after the playlist\'s other enabled channels', function () {
    $otherGroup = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live']);
    Channel::factory()->for($this->user)->for($this->playlist)->create([
        'group_id' => $otherGroup->id, 'is_vod' => false, 'enabled' => true, 'channel' => 5,
    ]);

    $group = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live']);
    $a = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'group_id' => $group->id, 'is_vod' => false, 'enabled' => false, 'channel' => 0, 'sort' => 1,
    ]);
    $b = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'group_id' => $group->id, 'is_vod' => false, 'enabled' => false, 'channel' => 0, 'sort' => 2,
    ]);

    $this->service->enable($group);

    expect($a->fresh()->enabled)->toBeTrue()
        ->and($b->fresh()->enabled)->toBeTrue()
        ->and((int) $a->fresh()->channel)->toBe(6)
        ->and((int) $b->fresh()->channel)->toBe(7);
});

it('disables a live group\'s channels without touching channel numbers', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live']);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'group_id' => $group->id, 'is_vod' => false, 'enabled' => true, 'channel' => 12,
    ]);

    $this->service->disable($group);

    expect($channel->fresh()->enabled)->toBeFalse()
        ->and((int) $channel->fresh()->channel)->toBe(12);
});

it('enables and disables a merged group\'s descendant channels (issue #1703)', function () {
    $merged = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live', 'is_merged' => true]);
    $childA = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live', 'parent_id' => $merged->id]);
    $childB = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live', 'parent_id' => $merged->id]);

    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'group_id' => $childA->id, 'is_vod' => false, 'enabled' => true,
    ]);
    $channelB = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'group_id' => $childB->id, 'is_vod' => false, 'enabled' => true,
    ]);

    $this->service->disable($merged);

    expect($channelA->fresh()->enabled)->toBeFalse()
        ->and($channelB->fresh()->enabled)->toBeFalse();

    $this->service->enable($merged);

    expect($channelA->fresh()->enabled)->toBeTrue()
        ->and($channelB->fresh()->enabled)->toBeTrue();
});

it('enables a vod group\'s channels without renumbering', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod']);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'group_id' => $group->id, 'is_vod' => true, 'enabled' => false, 'channel' => 0,
    ]);

    $this->service->enable($group);

    expect($channel->fresh()->enabled)->toBeTrue()
        ->and((int) $channel->fresh()->channel)->toBe(0);
});
