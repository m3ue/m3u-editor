<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;

it('includes a dedicated hls cast url for floating channel players', function () {
    $user = User::factory()->create(['name' => 'Harry']);
    $playlist = Playlist::factory()->for($user)->create();
    $playlist->refresh(); // UUID is set by Playlist::creating listener

    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test/live/stream.m3u8',
        'is_vod' => false,
    ]);

    $attributes = $channel->getFloatingPlayerAttributes();

    expect($attributes['url'])->toContain('/live/Harry/'.$playlist->uuid.'/'.$channel->id.'.m3u8?proxy=true');
    expect($attributes['format'])->toBe('m3u8');
    expect($attributes['cast_url'])->toContain('/cast/live/Harry/'.$playlist->uuid.'/'.$channel->id.'.m3u8');
});

it('returns no cast url when playlist context is missing', function () {
    $user = User::factory()->create(['name' => 'Harry']);

    $channel = Channel::factory()->for($user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => null,
        'url' => 'http://provider.test/live/stream.ts',
        'is_vod' => false,
    ]);

    $attributes = $channel->getFloatingPlayerAttributes();

    expect($attributes['cast_url'])->toBeNull();
    expect($attributes['cast_format'])->toBeNull();
});

it('includes a dedicated hls cast url for floating episode players', function () {
    $user = User::factory()->create(['name' => 'Harry']);
    $playlist = Playlist::factory()->for($user)->create();
    $playlist->refresh(); // UUID is set by Playlist::creating listener

    $episode = Episode::factory()->for($user)->for($playlist)->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test/series/stream.m3u8',
        'container_extension' => 'm3u8',
    ]);

    $attributes = $episode->getFloatingPlayerAttributes();

    expect($attributes['url'])->toContain('/series/Harry/'.$playlist->uuid.'/'.$episode->id.'.m3u8?proxy=true');
    expect($attributes['format'])->toBe('m3u8');
    expect($attributes['cast_url'])->toContain('/cast/series/Harry/'.$playlist->uuid.'/'.$episode->id.'.m3u8');
    expect($attributes['cast_format'])->toBe('m3u8');
});
