<?php

use App\Filament\Resources\Channels\Pages\ViewChannel;
use App\Jobs\ProbeChannelStreams;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

function channelWithProbedStats(Playlist $playlist): Channel
{
    return Channel::factory()->for($playlist)->create([
        'user_id' => $playlist->user_id,
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'codec_long_name' => 'H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10',
                'profile' => 'High',
                'level' => 40,
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => '4200000',
                'avg_frame_rate' => '25/1',
                'display_aspect_ratio' => '16:9',
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels' => 2,
                'sample_rate' => '48000',
                'bit_rate' => '192000',
                'tags' => ['language' => 'eng'],
            ]],
        ],
        'stream_stats_probed_at' => now()->subHour(),
        'probe_enabled' => true,
        'is_vod' => false,
    ]);
}

it('renders the Technical Details section on the view page', function () {
    $channel = channelWithProbedStats($this->playlist);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->assertSee('Technical Details');
});

it('renders compact stream details for a probed channel', function () {
    $channel = channelWithProbedStats($this->playlist);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->assertSee('1920x1080')
        ->assertSee('h264 (High)')
        ->assertSee('aac')
        ->assertSee('stereo');
});

it('renders advanced fields when probed stats include codec long name and DAR', function () {
    $channel = channelWithProbedStats($this->playlist);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->assertSee('Advanced')
        ->assertSee('H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10')
        ->assertSee('16:9')
        ->assertSee('48000');
});

it('does not render the Advanced section when the channel has never been probed', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->playlist->user_id,
        'probe_enabled' => true,
        'stream_stats_probed_at' => null,
        'stream_stats' => [],
    ]);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->assertDontSee(__('Advanced'))
        ->assertDontSee(__('Display aspect ratio'))
        ->assertDontSee(__('Codec long name'));
});

it('renders the all-streams sub-list when more than two streams are present', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->playlist->user_id,
        'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]],
            ['stream' => ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'tags' => ['language' => 'eng']]],
            ['stream' => ['codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6, 'tags' => ['language' => 'fra']]],
        ],
        'stream_stats_probed_at' => now()->subHour(),
        'probe_enabled' => true,
        'is_vod' => false,
    ]);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->assertSee('All streams')
        ->assertSee('ac3')
        ->assertSee('fra');
});

it('shows the "never probed" placeholder when stream_stats_probed_at is null', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->playlist->user_id,
        'stream_stats' => null,
        'stream_stats_probed_at' => null,
        'probe_enabled' => true,
        'is_vod' => false,
    ]);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->assertSee("This channel hasn't been probed yet")
        ->assertDontSee('1920x1080');
});

it('shows the "probe failed" placeholder when probed_at is set but stream_stats is empty', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->playlist->user_id,
        'stream_stats' => [],
        'stream_stats_probed_at' => now()->subHour(),
        'probe_enabled' => true,
        'is_vod' => false,
    ]);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->assertSee('Last probe returned no data')
        ->assertDontSee("hasn't been probed");
});

it('shows the "probe disabled" placeholder when probe_enabled is false', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->playlist->user_id,
        'stream_stats' => null,
        'stream_stats_probed_at' => null,
        'probe_enabled' => false,
        'is_vod' => false,
    ]);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->assertSee('Probing is disabled for this channel')
        ->assertDontSee("hasn't been probed");
});

it('dispatches ProbeChannelStreams with the channel id when Probe now is invoked (State B)', function () {
    Bus::fake();

    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->playlist->user_id,
        'stream_stats' => null,
        'stream_stats_probed_at' => null,
        'probe_enabled' => true,
        'is_vod' => false,
    ]);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->callInfolistAction('technical-details::section', 'probe');

    Bus::assertDispatched(
        ProbeChannelStreams::class,
        fn (ProbeChannelStreams $job) => $job->channelIds === [$channel->id],
    );
});

it('hides the probe action when probing is disabled for the channel (State D)', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->playlist->user_id,
        'stream_stats' => null,
        'stream_stats_probed_at' => null,
        'probe_enabled' => false,
        'is_vod' => false,
    ]);

    Livewire::test(ViewChannel::class, ['record' => $channel->getRouteKey()])
        ->assertDontSee(__('Probe now'))
        ->assertDontSee(__('Re-probe'))
        ->assertDontSee(__('Retry probe'));
});
