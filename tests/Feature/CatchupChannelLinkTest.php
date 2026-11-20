<?php

/**
 * A channel can borrow catchup from another live channel (#1661): live playback keeps the
 * channel's own stream, while catchup requests and the advertised archive window come from
 * the linked channel.
 */

use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Filament\Resources\Channels\Pages\ViewChannel;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create(['disable_catchup' => false]);
    $this->username = 'linktest_'.Str::random(5);
    $this->password = 'testpass';

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($playlistAuth);

    // An FHD channel without catchup, and an HD channel from another playlist that has it
    $this->fhdChannel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'title' => 'News FHD',
        'url' => 'https://fhd.provider/live/u/p/fhd-stream.ts',
        'catchup' => null,
        'shift' => 0,
    ]);

    $this->otherPlaylist = Playlist::factory()->for($this->user)->create(['name' => 'HD Provider']);
    $this->hdChannel = Channel::factory()->for($this->otherPlaylist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'title' => 'News HD',
        'url' => 'https://hd.provider/live/user/pass/hd-stream.ts',
        'catchup' => '1',
        'shift' => 72,
    ]);
});

function catchupLinkEditableChannel($test): Channel
{
    $test->actingAs($test->user);

    // The edit form validates the group against the channel's own playlist
    $group = Group::factory()->create([
        'user_id' => $test->user->id,
        'playlist_id' => $test->playlist->id,
        'type' => 'live',
    ]);
    $test->fhdChannel->update(['group_id' => $group->id]);

    return $test->fhdChannel;
}

function catchupLinkM3uUrl($test): string
{
    return route('playlist.generate', ['uuid' => $test->playlist->uuid]).'?username='.$test->username.'&password='.$test->password;
}

function catchupLinkLiveUrl($test, Channel $channel): string
{
    return route('xtream.stream.live.root', [
        'username' => $test->username,
        'password' => $test->password,
        'streamId' => $channel->id,
        'format' => 'ts',
    ]);
}

it('serves utc catchup requests from the linked channel while live playback keeps its own stream', function () {
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    $liveUrl = catchupLinkLiveUrl($this, $this->fhdChannel);

    expect($this->get($liveUrl.'?utc='.(time() - 1800).'&lutc='.time())->headers->get('Location'))
        ->toContain('hd.provider/timeshift/user/pass/30/')
        ->toContain('/hd-stream.ts');

    expect($this->get($liveUrl)->headers->get('Location'))->toContain('fhd-stream');
});

it('serves xtream timeshift requests from the linked channel', function () {
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    $response = $this->get(route('xtream.stream.timeshift.root', [
        'username' => $this->username,
        'password' => $this->password,
        'duration' => 30,
        'date' => '2024-12-01:15-30-00',
        'streamId' => $this->fhdChannel->id,
        'format' => 'ts',
    ]));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('hd.provider/timeshift/user/pass/30/')
        ->toContain('/hd-stream.ts')
        ->not->toContain('fhd-stream');
});

it('prefers the linked channel over a catchup failover', function () {
    $failover = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'url' => 'https://sd.provider/live/user/pass/sd-stream.ts',
        'catchup' => '1',
    ]);
    ChannelFailover::create([
        'user_id' => $this->user->id,
        'channel_id' => $this->fhdChannel->id,
        'channel_failover_id' => $failover->id,
        'sort' => 1,
        'metadata' => '{}',
    ]);

    $liveUrl = catchupLinkLiveUrl($this, $this->fhdChannel);
    $catchupQuery = '?utc='.(time() - 1800).'&lutc='.time();

    // No link: unchanged, the catchup failover serves it
    expect($this->get($liveUrl.$catchupQuery)->headers->get('Location'))->toContain('sd-stream');

    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    expect($this->get($liveUrl.$catchupQuery)->headers->get('Location'))->toContain('hd-stream');
});

it('ignores links that cannot be used', function (string $case) {
    $linked = match ($case) {
        'other user' => Channel::factory()->create([
            'user_id' => User::factory()->create()->id,
            'url' => 'https://hd.provider/live/user/pass/foreign.ts',
            'catchup' => '1',
        ]),
        'no catchup' => tap($this->hdChannel)->update(['catchup' => null]),
        'vod' => tap($this->hdChannel)->update(['is_vod' => true]),
    };
    $this->fhdChannel->update(['catchup_channel_id' => $linked->id]);

    expect($this->fhdChannel->fresh()->linkedCatchupChannel())->toBeNull()
        ->and($this->fhdChannel->fresh()->supportsCatchup())->toBeFalse();

    $location = $this->get(catchupLinkLiveUrl($this, $this->fhdChannel).'?utc='.(time() - 1800).'&lutc='.time())
        ->headers->get('Location');
    expect($location)->toContain('fhd-stream');
})->with(['other user', 'no catchup', 'vod']);

it('does not follow chained links', function () {
    $third = Channel::factory()->for($this->otherPlaylist)->create([
        'user_id' => $this->user->id,
        'url' => 'https://third.provider/live/user/pass/third.ts',
        'catchup' => '1',
    ]);
    $this->hdChannel->update(['catchup_channel_id' => $third->id]);
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    expect($this->fhdChannel->fresh()->linkedCatchupChannel()?->id)->toBe($this->hdChannel->id);
});

it('clears the link when the linked channel is deleted', function () {
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    $this->hdChannel->delete();

    expect($this->fhdChannel->fresh()->catchup_channel_id)->toBeNull();
});

it('points the m3u catchup-source at our endpoint even with raw provider urls', function () {
    $this->playlist->update(['disable_m3u_xtream_format' => true, 'enable_proxy' => false]);
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    $content = $this->get(catchupLinkM3uUrl($this))->streamedContent();

    // The live URL stays the raw FHD provider URL
    expect($content)
        ->toContain('https://fhd.provider/live/u/p/fhd-stream.ts')
        ->toContain('catchup="default"')
        ->toContain("/{$this->fhdChannel->id}.ts?utc={utc}&lutc={lutc}")
        // Inherited archive window: 72 hours = 3 days
        ->toContain('catchup-days="3" timeshift="3"')
        ->not->toContain('//hd.provider');
});

it('advertises the linked archive window over the channel own, since the link serves catchup', function () {
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id, 'shift' => 240]);

    $content = $this->get(catchupLinkM3uUrl($this))->streamedContent();

    expect($content)->toContain('catchup-days="3" timeshift="3"');
});

it('falls back to the channel own archive window when the linked one has none', function () {
    $this->hdChannel->update(['shift' => 0]);
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id, 'shift' => 24]);

    $content = $this->get(catchupLinkM3uUrl($this))->streamedContent();

    expect($content)->toContain('catchup-days="1" timeshift="1"');
});

it('does not advertise linked catchup when the playlist disables catchup', function () {
    $this->playlist->update(['disable_catchup' => true]);
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    $content = $this->get(catchupLinkM3uUrl($this))->streamedContent();

    expect($content)->not->toContain('catchup=');
});

it('leaves m3u output unchanged when no link is set', function () {
    $content = $this->get(catchupLinkM3uUrl($this))->streamedContent();

    expect($content)->not->toContain('catchup=');
});

it('advertises the linked archive in xtream get_live_streams', function () {
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    $streams = collect($this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams')
        ->assertOk()
        ->json());

    expect($streams->firstWhere('stream_id', $this->fhdChannel->id))
        ->toMatchArray(['tv_archive' => 1, 'tv_archive_duration' => 3]);
});

it('does not advertise an archive in xtream get_live_streams without a usable link', function () {
    $this->hdChannel->update(['catchup' => null]);
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    $streams = collect($this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams')
        ->assertOk()
        ->json());

    expect($streams->firstWhere('stream_id', $this->fhdChannel->id))
        ->toMatchArray(['tv_archive' => 0, 'tv_archive_duration' => 0]);
});

it('saves a catchup channel link from the channel edit form', function () {
    catchupLinkEditableChannel($this);

    Livewire::test(ListChannels::class)
        ->callAction(TestAction::make('edit')->table($this->fhdChannel), [
            'catchup_channel_id' => $this->hdChannel->id,
        ])
        ->assertHasNoFormErrors();

    expect($this->fhdChannel->fresh()->catchup_channel_id)->toBe($this->hdChannel->id);
});

it('rejects linking a channel without catchup from the edit form', function () {
    catchupLinkEditableChannel($this);
    $this->hdChannel->update(['catchup' => null]);

    Livewire::test(ListChannels::class)
        ->callAction(TestAction::make('edit')->table($this->fhdChannel), [
            'catchup_channel_id' => $this->hdChannel->id,
        ])
        ->assertHasFormErrors(['catchup_channel_id']);

    expect($this->fhdChannel->fresh()->catchup_channel_id)->toBeNull();
});

it('does not block saving other fields when an existing link has become unusable', function () {
    catchupLinkEditableChannel($this);
    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);
    $this->hdChannel->update(['catchup' => null]);

    Livewire::test(ListChannels::class)
        ->callAction(TestAction::make('edit')->table($this->fhdChannel), [
            'title_custom' => 'Renamed',
        ])
        ->assertHasNoFormErrors();

    expect($this->fhdChannel->fresh())
        ->title_custom->toBe('Renamed')
        ->catchup_channel_id->toBe($this->hdChannel->id);
});

it('accepts a channel that has its own link, since links are not chained', function () {
    catchupLinkEditableChannel($this);
    $third = Channel::factory()->for($this->otherPlaylist)->create([
        'user_id' => $this->user->id,
        'catchup' => '1',
    ]);
    $this->hdChannel->update(['catchup_channel_id' => $third->id]);

    Livewire::test(ListChannels::class)
        ->callAction(TestAction::make('edit')->table($this->fhdChannel), [
            'catchup_channel_id' => $this->hdChannel->id,
        ])
        ->assertHasNoFormErrors();

    expect($this->fhdChannel->fresh()->linkedCatchupChannel()?->id)->toBe($this->hdChannel->id);
});

it('does not label another user channel in the catchup channel field', function () {
    $foreign = Channel::factory()->create([
        'user_id' => User::factory()->create()->id,
        'title' => 'Someone Elses Channel',
        'catchup' => '1',
    ]);
    $this->actingAs($this->user);

    expect(ChannelResource::catchupChannelOptionLabel($foreign->id, $this->fhdChannel))->toBeNull()
        ->and(ChannelResource::catchupChannelOptionLabel($this->hdChannel->id, $this->fhdChannel))->toBe('News HD [HD Provider]');

    // A link that has since become unusable still shows its label
    $this->hdChannel->update(['catchup' => null]);
    expect(ChannelResource::catchupChannelOptionLabel($this->hdChannel->id, $this->fhdChannel))->toBe('News HD [HD Provider]');
});

it('shows catchup as supported on the channel info view when linked', function () {
    $this->actingAs($this->user);

    Livewire::test(ViewChannel::class, ['record' => $this->fhdChannel->id])
        ->assertDontSee('Catchup from');

    $this->fhdChannel->update(['catchup_channel_id' => $this->hdChannel->id]);

    Livewire::test(ViewChannel::class, ['record' => $this->fhdChannel->id])
        ->assertSee('Catchup from News HD [HD Provider]');
});
