<?php

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'auto_channel_increment' => true,
        'channel_start' => 6000,
    ]);
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 1]);
});

it('Xtream API get_live_streams uses auto channel increment before imported provider numbers', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'channel' => 12,
        'title' => 'Provider Number 12',
        'url' => 'http://example.com/live/12.ts',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'channel' => 987,
        'title' => 'Provider Number 987',
        'url' => 'http://example.com/live/987.ts',
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(2)
        ->and(array_column($streams, 'num'))->toBe([6000, 6001]);
});

it('Xtream API get_vod_streams uses auto channel increment before imported provider numbers', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 1,
        'channel' => 55,
        'title' => 'Provider VOD 55',
        'url' => 'http://example.com/movie/55.mkv',
        'container_extension' => 'mkv',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 2,
        'channel' => 101,
        'title' => 'Provider VOD 101',
        'url' => 'http://example.com/movie/101.mkv',
        'container_extension' => 'mkv',
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_vod_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(2)
        ->and(array_column($streams, 'num'))->toBe([6000, 6001]);
});
