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

it('Xtream API get_live_streams does not use auto channel increment before imported provider numbers', function () {
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
        ->and(array_column($streams, 'num'))->toBe([12, 987]);
});

it('Xtream API get_vod_streams does not use auto channel increment before imported provider numbers', function () {
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
        ->and(array_column($streams, 'num'))->toBe([55, 101]);
});

it('Xtream API get_live_streams uses auto channel increment before imported provider numbers', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'channel' => null,
        'title' => 'Provider Number Not Provided',
        'url' => 'http://example.com/live/12.ts',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'channel' => null,
        'title' => 'Provider Number Not Provided',
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
        'channel' => null,
        'title' => 'Provider VOD Not Provided',
        'url' => 'http://example.com/movie/55.mkv',
        'container_extension' => 'mkv',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 2,
        'channel' => null,
        'title' => 'Provider VOD Not Provided',
        'url' => 'http://example.com/movie/101.mkv',
        'container_extension' => 'mkv',
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_vod_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(2)
        ->and(array_column($streams, 'num'))->toBe([6000, 6001]);
});

it('Xtream API get_live_streams overrides imported provider numbers when force channel numbering is enabled', function () {
    $this->playlist->update([
        'force_channel_numbering' => true,
        'channel_start' => 7000,
    ]);

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
        ->and(array_column($streams, 'num'))->toBe([7000, 7001]);
});

it('Xtream API get_vod_streams overrides imported provider numbers when force channel numbering is enabled', function () {
    $this->playlist->update([
        'force_channel_numbering' => true,
        'channel_start' => 7000,
    ]);

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
        ->and(array_column($streams, 'num'))->toBe([7000, 7001]);
});

it('M3U output overrides imported provider numbers when force channel numbering is enabled', function () {
    $this->playlist->update([
        'force_channel_numbering' => true,
        'channel_start' => 7000,
    ]);

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

    $response = $this->get("/{$this->playlist->uuid}/playlist.m3u");

    $response->assertStatus(200);

    expect($response->streamedContent())
        ->toContain('tvg-chno="7000"')
        ->toContain('tvg-chno="7001"')
        ->not->toContain('tvg-chno="12"')
        ->not->toContain('tvg-chno="987"');
});

it('Xtream API get_live_streams numbers match the M3U/XMLTV numbers when VOD is interleaved and numbering is forced (issue #1683)', function () {
    $this->playlist->update([
        'force_channel_numbering' => true,
        'channel_start' => 100,
        'include_vod_in_m3u' => true,
    ]);

    $vodGroup = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 0]);

    // The VOD entry is numbered in the shared M3U/XMLTV output (it sorts before
    // the live group), but get_live_streams must never see it: its own query
    // asks for live channels only, so this consumes a number from the shared
    // sequence that get_live_streams has to account for without ever selecting it.
    Channel::factory()->for($this->user)->for($this->playlist)->for($vodGroup)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 1,
        'title' => 'Movie First',
        'url' => 'http://example.com/movie/1.mkv',
        'container_extension' => 'mkv',
    ]);
    $liveA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'title' => 'Live A',
        'url' => 'http://example.com/live/a.ts',
    ]);
    $liveB = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'title' => 'Live B',
        'url' => 'http://example.com/live/b.ts',
    ]);

    $liveStreamsResponse = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams');
    $liveStreamsResponse->assertStatus(200);
    $streamsById = collect($liveStreamsResponse->json())->keyBy('stream_id');

    $m3uResponse = $this->get("/{$this->playlist->uuid}/playlist.m3u");
    $m3uResponse->assertStatus(200);

    expect($streamsById[$liveA->id]['num'])->toBe(101)
        ->and($streamsById[$liveB->id]['num'])->toBe(102)
        ->and($m3uResponse->streamedContent())
        ->toContain('tvg-chno="101"')
        ->toContain('tvg-chno="102"');
});

it('Xtream API get_vod_streams keeps numbering VOD when it is left out of the M3U and numbering is forced', function () {
    $this->playlist->update([
        'force_channel_numbering' => true,
        'channel_start' => 100,
        'include_vod_in_m3u' => false,
    ]);

    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'title' => 'Live A',
        'url' => 'http://example.com/live/a.ts',
    ]);
    $movies = collect([1, 2])->map(fn (int $sort) => Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => $sort,
        'channel' => null,
        'title' => "Movie {$sort}",
        'url' => "http://example.com/movie/{$sort}.mkv",
        'container_extension' => 'mkv',
    ]));

    $baseUrl = '/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_vod_streams';
    $all = collect($this->getJson($baseUrl)->assertStatus(200)->json())->pluck('num', 'stream_id');
    $category = collect($this->getJson($baseUrl.'&category_id='.$this->group->id)->assertStatus(200)->json())->pluck('num', 'stream_id');

    expect($all[$movies[0]->id])->toBe(100)
        ->and($all[$movies[1]->id])->toBe(101)
        ->and($category->all())->toBe($all->all());
});
