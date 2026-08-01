<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression: Channel::getProxyUrl() previously defaulted a VOD channel's format
 * to 'ts' whenever the URL didn't end in .m3u8/.ts and container_extension was
 * unset — inherited from the live-channel branch's default rather than the VOD
 * branch's own 'mkv' fallback. A 'ts' extension makes players (correctly) treat
 * the stream as live and disable seeking, which is wrong for VOD content.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
});

it('defaults a VOD channel with an opaque URL and no container_extension to mkv, not ts', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'container_extension' => null,
        'url' => 'https://debrid.example.com/dl/ab12cd34ef56',
    ]);

    [, $format] = $channel->getProxyUrl(withFormat: true);

    expect($format)->toBe('mkv');
});

it('uses the explicit container_extension for a VOD channel when set', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'container_extension' => 'mp4',
        'url' => 'https://debrid.example.com/dl/ab12cd34ef56',
    ]);

    [, $format] = $channel->getProxyUrl(withFormat: true);

    expect($format)->toBe('mp4');
});

it('still defaults a live channel with an opaque URL to ts, unaffected by the VOD fix', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => false,
        'container_extension' => null,
        'url' => 'https://example.com/live/stream123',
    ]);

    [, $format] = $channel->getProxyUrl(withFormat: true);

    expect($format)->toBe('ts');
});
