<?php

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\Channels\SmartChannelCreator;
use App\Services\PlaylistUrlService;
use Filament\Actions\BulkAction;
use Filament\Schemas\Components\Component;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

function vpChannel(User $user, Playlist $playlist, array $overrides, ?array $stats = null): Channel
{
    return Channel::factory()->for($user)->for($playlist)->create(array_merge([
        'enabled' => true,
        'can_merge' => true,
        'probe_enabled' => true,
        'is_custom' => false,
        'stream_stats' => $stats ?? [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => '5000000',
                'avg_frame_rate' => '25/1',
            ]],
        ],
        'stream_stats_probed_at' => now(),
    ], $overrides));
}

it('registers make_smart_channel in the channel BulkModalActionGroup', function () {
    $bulkActions = ChannelResource::getTableBulkActions();
    $group = $bulkActions[0];

    $schemaProp = new ReflectionProperty($group, 'schema');
    $outerSchema = $schemaProp->getValue($group);

    $childProp = new ReflectionProperty(Component::class, 'childComponents');
    $names = [];

    foreach ($outerSchema as $component) {
        $children = $childProp->getValue($component)['default'] ?? [];
        foreach ($children as $child) {
            if ($child instanceof BulkAction) {
                $names[] = $child->getName();
            }
        }
    }

    expect($names)->toContain('make_smart_channel');
});

it('creates a custom channel with copied identity from the highest-scoring source', function () {
    $epg = EpgChannel::factory()->create();

    $high = vpChannel($this->user, $this->playlist, [
        'title' => 'BBC One HD',
        'name' => 'BBC One HD',
        'logo' => 'http://logos.example/bbc-hd.png',
        'epg_channel_id' => $epg->id,
    ]);

    $low = vpChannel($this->user, $this->playlist, [
        'title' => 'BBC One SD',
        'logo' => 'http://logos.example/bbc-sd.png',
    ], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    $virtual = SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$low, $high]));

    expect($virtual->is_custom)->toBeTrue()
        ->and($virtual->url)->toBeNull()
        ->and($virtual->title)->toBe('BBC One HD')
        ->and($virtual->logo)->toBe('http://logos.example/bbc-hd.png')
        ->and($virtual->epg_channel_id)->toBe($epg->id);
});

it('attaches all selected channels as failovers in score order', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'HD']);
    $sd = vpChannel($this->user, $this->playlist, ['title' => 'SD'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);
    $uhd = vpChannel($this->user, $this->playlist, ['title' => '4K'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 3840, 'height' => 2160]],
    ]);

    $virtual = SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$sd, $hd, $uhd]));

    $sorted = ChannelFailover::where('channel_id', $virtual->id)->orderBy('sort')->get();

    expect($sorted)->toHaveCount(3)
        ->and($sorted[0]->channel_failover_id)->toBe($uhd->id)
        ->and($sorted[1]->channel_failover_id)->toBe($hd->id)
        ->and($sorted[2]->channel_failover_id)->toBe($sd->id);
});

it('streams the highest-ranked source URL via PlaylistUrlService fallback', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'HD', 'url' => 'http://hd.example/stream']);
    $sd = vpChannel($this->user, $this->playlist, ['title' => 'SD', 'url' => 'http://sd.example/stream'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    $virtual = SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$sd, $hd]));

    expect((new PlaylistUrlService)->getChannelUrl($virtual->fresh(), 'http://m3u.test'))->toContain('hd.example');
});

it('disables source channels when the disableSources flag is set', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'HD']);
    $sd = vpChannel($this->user, $this->playlist, ['title' => 'SD'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    SmartChannelCreator::fromPlaylist($this->playlist)->create(
        channels: collect([$hd, $sd]),
        disableSources: true,
    );

    expect($hd->fresh()->enabled)->toBeFalse()
        ->and($sd->fresh()->enabled)->toBeFalse();
});

it('leaves source channels enabled when disableSources is false', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'HD']);
    $sd = vpChannel($this->user, $this->playlist, ['title' => 'SD'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$hd, $sd]));

    expect($hd->fresh()->enabled)->toBeTrue()
        ->and($sd->fresh()->enabled)->toBeTrue();
});

it('uses the provided title when one is supplied', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'BBC One HD']);

    $virtual = SmartChannelCreator::fromPlaylist($this->playlist)->create(
        channels: collect([$hd]),
        title: 'BBC One',
    );

    expect($virtual->title)->toBe('BBC One')
        ->and($virtual->name)->toBe('BBC One');
});

it('throws when called with an empty channel collection', function () {
    SmartChannelCreator::fromPlaylist($this->playlist)->create(collect());
})->throws(InvalidArgumentException::class);

it('rejects sources spanning multiple playlists', function () {
    $other = Playlist::factory()->for($this->user)->createQuietly();

    $a = vpChannel($this->user, $this->playlist, ['title' => 'A']);
    $b = vpChannel($this->user, $other, ['title' => 'B']);

    SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$a, $b]));
})->throws(InvalidArgumentException::class, 'same playlist');

it('rejects existing smart channels as sources', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'HD']);
    $sd = vpChannel($this->user, $this->playlist, ['title' => 'SD']);

    $existingSmart = SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$hd, $sd]));

    $thirdSource = vpChannel($this->user, $this->playlist, ['title' => 'Other']);

    SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$existingSmart, $thirdSource]));
})->throws(InvalidArgumentException::class, 'cannot be used as sources');

it('persists score and per-attribute breakdown on each channel_failovers row', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'HD']);
    $sd = vpChannel($this->user, $this->playlist, ['title' => 'SD'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    $virtual = SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$sd, $hd]));

    $hdFailover = ChannelFailover::where('channel_id', $virtual->id)
        ->where('channel_failover_id', $hd->id)
        ->first();

    expect($hdFailover->metadata)
        ->toHaveKey('score')
        ->and($hdFailover->metadata['priority_order'])->toBe(['resolution', 'fps', 'bitrate', 'codec'])
        ->and($hdFailover->metadata['attribute_scores'])->toHaveKeys(['resolution', 'fps', 'bitrate', 'codec'])
        ->and($hdFailover->metadata['attribute_scores']['resolution'])->toBe(25); // 1920x1080 / 82944 ≈ 25
});

it('flags the created channel with is_smart_channel = true', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'HD']);
    $sd = vpChannel($this->user, $this->playlist, ['title' => 'SD'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    $smartChannel = SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$sd, $hd]));

    expect($smartChannel->is_smart_channel)->toBeTrue()
        ->and($smartChannel->isSmartChannel())->toBeTrue()
        ->and($smartChannel->is_custom)->toBeTrue()
        ->and($smartChannel->url)->toBeNull();
});

it('smartChannels query scope filters to flagged channels only', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'HD']);
    $sd = vpChannel($this->user, $this->playlist, ['title' => 'SD']);

    SmartChannelCreator::fromPlaylist($this->playlist)->create(collect([$hd, $sd]));

    $smartChannels = Channel::smartChannels()->get();

    expect($smartChannels)->toHaveCount(1)
        ->and($smartChannels->first()->is_smart_channel)->toBeTrue()
        ->and($smartChannels->first()->id)->not->toBe($hd->id)
        ->and($smartChannels->first()->id)->not->toBe($sd->id);
});

it('rank() returns channels sorted by score with breakdowns', function () {
    $hd = vpChannel($this->user, $this->playlist, ['title' => 'HD']);
    $sd = vpChannel($this->user, $this->playlist, ['title' => 'SD'], stats: [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 720, 'height' => 480]],
    ]);

    $ranking = SmartChannelCreator::fromPlaylist($this->playlist)->rank(collect([$sd, $hd]));

    expect($ranking)->toHaveCount(2)
        ->and($ranking[0]['channel']->id)->toBe($hd->id)
        ->and($ranking[1]['channel']->id)->toBe($sd->id)
        ->and($ranking[0]['score'])->toBeGreaterThan($ranking[1]['score'])
        ->and($ranking[0]['breakdown'])->toHaveKeys(['resolution', 'fps', 'bitrate', 'codec']);
});
