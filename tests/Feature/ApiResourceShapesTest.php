<?php

/**
 * Pins the JSON shape of the API Resources behind the Scramble schemas, so a change to a
 * resource can't silently change what API clients receive. Key order is asserted too,
 * since the resources replaced inline arrays and must serialize identically.
 */

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test', ['view', 'create', 'update', 'delete'])->plainTextToken;
    $this->playlist = Playlist::factory()->for($this->user)->create(['enable_proxy' => false]);
    $this->group = Group::factory()->for($this->user)->create(['playlist_id' => $this->playlist->id, 'name' => 'Sports']);
    $this->epgChannel = EpgChannel::factory()->for($this->user)->create(['channel_id' => 'news.us']);
    $this->channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'title' => 'ESPN',
        'title_custom' => 'ESPN HD',
        'enabled' => true,
        'epg_channel_id' => $this->epgChannel->id,
    ]);
});

it('serializes the channel list items', function () {
    $item = $this->withToken($this->token)->getJson('/channel/get')
        ->assertOk()
        ->json('data.0');

    expect(array_keys($item))->toBe([
        'id', 'title', 'name', 'logo', 'url', 'stream_id', 'enabled', 'is_vod',
        'channel_number', 'group', 'proxy_url', 'playlist',
    ])
        ->and($item['title'])->toBe('ESPN HD')
        ->and($item['group'])->toBe(['id' => $this->group->id, 'name' => 'Sports'])
        ->and($item['playlist'])->toBe([
            'id' => $this->playlist->id,
            'name' => $this->playlist->name,
            'uuid' => $this->playlist->uuid,
            'proxy_enabled' => false,
        ]);
});

it('serializes a single channel with its epg mapping and failovers', function () {
    $failover = Channel::factory()->for($this->user)->for($this->playlist)->create(['title' => 'ESPN backup']);
    ChannelFailover::create([
        'user_id' => $this->user->id,
        'channel_id' => $this->channel->id,
        'channel_failover_id' => $failover->id,
        'sort' => 0,
    ]);

    $data = $this->withToken($this->token)->getJson("/channel/{$this->channel->id}")
        ->assertOk()
        ->json('data');

    expect(array_keys($data))->toBe([
        'id', 'title', 'title_original', 'name', 'name_original', 'logo', 'logo_internal',
        'url', 'url_original', 'stream_id', 'stream_id_original', 'enabled', 'is_vod',
        'channel_number', 'sort_order', 'catchup', 'shift', 'tvg_shift', 'logo_type',
        'use_epg_logo', 'epg_map_enabled', 'proxy_url', 'epg', 'epg_channel_id', 'group_title',
        'group', 'playlist', 'failovers', 'metadata', 'created_at', 'updated_at',
    ])
        ->and($data['title_original'])->toBe('ESPN')
        ->and($data['epg'])->toBe(['channel_id' => $this->epgChannel->id, 'epg_id' => 'news.us', 'name' => $this->epgChannel->name])
        ->and($data['failovers'])->toBe([['id' => $failover->id, 'title' => 'ESPN backup', 'priority' => 0]])
        ->and(array_keys($data['metadata']))->toBe(['year', 'rating', 'rating_5based', 'has_info']);
});

it('only includes group channel counts when requested', function () {
    $withCounts = $this->withToken($this->token)->getJson('/group/get')->assertOk()->json('data.0');
    $withoutCounts = $this->withToken($this->token)->getJson('/group/get?with_channels=0')->assertOk()->json('data.0');

    expect(array_keys($withCounts))->toBe(['id', 'name', 'sort_order', 'type', 'total_channels', 'enabled_channels', 'playlist'])
        ->and($withCounts['total_channels'])->toBe(1)
        ->and($withCounts['enabled_channels'])->toBe(1)
        ->and(array_keys($withoutCounts))->toBe(['id', 'name', 'sort_order', 'type', 'playlist']);
});

it('serializes a single group with all channel counts', function () {
    $data = $this->withToken($this->token)->getJson("/group/{$this->group->id}")
        ->assertOk()
        ->json('data');

    expect(array_keys($data))->toBe([
        'id', 'name', 'sort_order', 'type', 'enabled', 'custom', 'total_channels',
        'enabled_channels', 'live_channels', 'vod_channels', 'playlist',
    ])
        ->and($data['total_channels'])->toBe(1)
        ->and($data['live_channels'])->toBe(1)
        ->and($data['vod_channels'])->toBe(0);
});

it('serializes the user epgs', function () {
    Epg::factory()->for($this->user)->create(['name' => 'Guide']);

    $item = $this->withToken($this->token)->getJson('/user/epgs')->assertOk()->json('0');

    expect(array_keys($item))->toBe(['name', 'uuid', 'channel_count', 'last_sync', 'status', 'source_type', 'is_processing'])
        ->and($item['name'])->toBe('Guide');
});

it('serializes custom playlist groups the same from every endpoint', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $created = $this->withToken($this->token)->postJson("/custom-playlist/{$customPlaylist->uuid}/groups", ['name' => 'Kids'])
        ->assertCreated()
        ->json('data');
    $listed = $this->withToken($this->token)->getJson("/custom-playlist/{$customPlaylist->uuid}/groups")
        ->assertOk()
        ->json('data.0');

    expect(array_keys($created))->toBe(['id', 'name', 'order_column'])
        ->and($listed)->toBe($created);
});

it('omits channel_id from series rules that match any channel', function () {
    $dvrSetting = DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();
    DvrRecordingRule::factory()->create([
        'user_id' => $this->user->id,
        'dvr_setting_id' => $dvrSetting->id,
        'type' => 'series',
        'series_title' => 'Evening News',
        'channel_id' => null,
    ]);

    $rule = $this->withHeaders(['X-API-Key' => $this->token])->getJson('/series-rules/')
        ->assertOk()
        ->json('rules.0');

    expect(array_keys($rule))->toBe(['id', 'tvg_id', 'mode', 'title', 'title_mode', 'description', 'description_mode', 'enabled'])
        ->and($rule['tvg_id'])->toBe('');
});
