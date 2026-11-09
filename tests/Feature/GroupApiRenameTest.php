<?php

/**
 * Issue #1704: a Group API rename must produce a consistent effective name across the
 * PATCH response, detail GET, list GET, the channel's group field and generated M3U
 * output - not just in the detail resource while list/channel/M3U keep the old name.
 */

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test', ['view', 'create', 'update', 'delete'])->plainTextToken;
    $this->playlist = Playlist::factory()->for($this->user)->create(['dummy_epg' => false]);
    $this->group = Group::factory()->for($this->user)->create([
        'playlist_id' => $this->playlist->id,
        'name' => 'News',
        'name_internal' => 'News',
        'type' => 'live',
    ]);
    $this->channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'title' => 'Channel One',
        'enabled' => true,
        'is_vod' => false,
        'group' => 'News',
        'group_internal' => 'News',
    ]);
});

it('reflects a PATCH rename consistently across PATCH, detail GET and list GET', function () {
    $patchResponse = $this->withToken($this->token)
        ->patchJson("/group/{$this->group->id}", ['name' => 'Headlines'])
        ->assertOk()
        ->json();

    expect($patchResponse['data']['name'])->toBe('Headlines');

    $detail = $this->withToken($this->token)
        ->getJson("/group/{$this->group->id}")
        ->assertOk()
        ->json();

    expect($detail['data']['name'])->toBe('Headlines');

    $list = $this->withToken($this->token)
        ->getJson('/group/get?playlist_uuid='.$this->playlist->uuid)
        ->assertOk()
        ->json();

    $renamed = collect($list['data'])->firstWhere('id', $this->group->id);
    expect($renamed['name'])->toBe('Headlines');

    expect($this->group->fresh()->name)->toBe('Headlines');
});

it('propagates a PATCH rename into the channel group field and generated M3U', function () {
    $this->withToken($this->token)
        ->patchJson("/group/{$this->group->id}", ['name' => 'Headlines'])
        ->assertOk();

    expect($this->channel->fresh()->group)->toBe('Headlines');

    $response = $this->get(route('playlist.generate', [
        'uuid' => $this->playlist->uuid,
    ]));
    $response->assertSuccessful();
    $content = $response->streamedContent();

    expect($content)->toContain('group-title="Headlines"')
        ->and($content)->not->toContain('group-title="News"');
});
