<?php

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->primaryPlaylist = Playlist::factory()->createQuietly([
        'user_id' => $this->user->id,
        'name' => 'Primary',
    ]);

    $this->fallbackPlaylist = Playlist::factory()->createQuietly([
        'user_id' => $this->user->id,
        'name' => 'Fallback',
    ]);

    $this->group = Group::factory()->createQuietly([
        'user_id' => $this->user->id,
        'playlist_id' => $this->primaryPlaylist->id,
    ]);
});

function runAliasFallbackMerge(object $test, array $config, ?int $preferredPlaylistId = null): void
{
    MergeChannels::dispatchSync(
        $test->user,
        collect([
            ['playlist_failover_id' => $test->primaryPlaylist->id],
            ['playlist_failover_id' => $test->fallbackPlaylist->id],
        ]),
        $preferredPlaylistId ?? $test->primaryPlaylist->id,
        forceCompleteRemerge: true,
        fallbackMergeConfig: $config,
    );
}

function makeMergeChannel(object $test, array $attributes): Channel
{
    return Channel::factory()->create(array_merge([
        'user_id' => $test->user->id,
        'playlist_id' => $test->primaryPlaylist->id,
        'group_id' => $test->group->id,
        'title' => 'Channel',
        'name' => 'Channel',
        'stream_id' => null,
        'stream_id_custom' => null,
        'can_merge' => true,
        'enabled' => true,
    ], $attributes));
}

it('merges channels without stream ids by normalized name fallback', function () {
    $master = makeMergeChannel($this, [
        'playlist_id' => $this->primaryPlaylist->id,
        'title' => 'Das Erste',
        'name' => 'Das Erste',
    ]);

    $fallback = makeMergeChannel($this, [
        'playlist_id' => $this->fallbackPlaylist->id,
        'title' => 'DASERSTE',
        'name' => 'DASERSTE',
    ]);

    runAliasFallbackMerge($this, [
        'enabled' => true,
        'mode' => 'normalized_name',
    ]);

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $fallback->id,
    ]);
});

it('does not strip quality labels during normalized fallback matching', function () {
    makeMergeChannel($this, [
        'playlist_id' => $this->primaryPlaylist->id,
        'title' => '3sat',
        'name' => '3sat',
    ]);

    makeMergeChannel($this, [
        'playlist_id' => $this->fallbackPlaylist->id,
        'title' => '3sat HD',
        'name' => '3sat HD',
    ]);

    runAliasFallbackMerge($this, [
        'enabled' => true,
        'mode' => 'normalized_name',
    ]);

    expect(ChannelFailover::count())->toBe(0);
});

it('merges channels without stream ids by explicit alias rules', function () {
    $master = makeMergeChannel($this, [
        'playlist_id' => $this->primaryPlaylist->id,
        'title' => 'Das Erste HD',
        'name' => 'Das Erste HD',
    ]);

    $fallback = makeMergeChannel($this, [
        'playlist_id' => $this->fallbackPlaylist->id,
        'title' => 'DASERSTE',
        'name' => 'DASERSTE',
    ]);

    runAliasFallbackMerge($this, [
        'enabled' => true,
        'mode' => 'alias_rules',
        'alias_rules' => [
            [
                'label' => 'Das Erste HD',
                'aliases' => ['Das Erste HD', 'DasErsteHD', 'DASERSTE', 'Das Erste'],
            ],
        ],
    ]);

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $fallback->id,
    ]);
});

it('ignores duplicate aliases instead of bridging two alias groups', function () {
    $dasErste = makeMergeChannel($this, [
        'playlist_id' => $this->primaryPlaylist->id,
        'title' => 'Das Erste HD',
        'name' => 'Das Erste HD',
    ]);

    $zdf = makeMergeChannel($this, [
        'playlist_id' => $this->primaryPlaylist->id,
        'title' => 'ZDF HD',
        'name' => 'ZDF HD',
    ]);

    $fallback = makeMergeChannel($this, [
        'playlist_id' => $this->fallbackPlaylist->id,
        'title' => 'DASERSTE',
        'name' => 'DASERSTE',
    ]);

    runAliasFallbackMerge($this, [
        'enabled' => true,
        'mode' => 'alias_rules',
        'alias_rules' => [
            [
                'label' => 'Das Erste HD',
                'aliases' => ['Das Erste HD', 'DASERSTE'],
            ],
            [
                'label' => 'ZDF HD',
                'aliases' => ['ZDF HD', 'DASERSTE'],
            ],
        ],
    ]);

    expect(ChannelFailover::count())->toBe(0);
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $dasErste->id,
        'channel_failover_id' => $fallback->id,
    ]);
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $zdf->id,
        'channel_failover_id' => $fallback->id,
    ]);
});

it('keeps exact stream id matching separate from fallback matching', function () {
    $withIdA = makeMergeChannel($this, [
        'playlist_id' => $this->primaryPlaylist->id,
        'title' => 'Das Erste',
        'name' => 'Das Erste',
        'stream_id' => 'main-id',
    ]);

    $withIdB = makeMergeChannel($this, [
        'playlist_id' => $this->fallbackPlaylist->id,
        'title' => 'DASERSTE',
        'name' => 'DASERSTE',
        'stream_id' => 'other-id',
    ]);

    runAliasFallbackMerge($this, [
        'enabled' => true,
        'mode' => 'normalized_name_and_alias_rules',
        'alias_rules' => [
            [
                'label' => 'Das Erste HD',
                'aliases' => ['Das Erste', 'DASERSTE'],
            ],
        ],
    ]);

    expect(ChannelFailover::count())->toBe(0);
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $withIdA->id,
        'channel_failover_id' => $withIdB->id,
    ]);
});
