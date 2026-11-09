<?php

use App\Jobs\ChannelFindAndReplace;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'vod']);
});

it('replaces a matched VOD genre with an empty string (issue #1696)', function () {
    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'is_vod' => true,
        'info' => ['genre' => 'Unknown', 'description' => 'Keep me'],
    ]);
    $channelB = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'is_vod' => true,
        'info' => ['genre' => 'Drama', 'description' => 'Control'],
    ]);

    (new ChannelFindAndReplace(
        user_id: $this->user->id,
        use_regex: false,
        column: 'info->genre',
        find_replace: 'Unknown',
        replace_with: '',
        all_playlists: false,
        playlist_id: $this->playlist->id,
        silent: true,
        is_vod: true,
    ))->handle();

    expect($channelA->fresh()->info['genre'])->toBe('')
        ->and($channelA->fresh()->info['description'])->toBe('Keep me')
        ->and($channelB->fresh()->info['genre'])->toBe('Drama');
});

it('replaces a matched VOD genre with the literal string "0" (issue #1696)', function () {
    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'is_vod' => true,
        'info' => ['genre' => 'Unknown', 'description' => 'Keep me'],
    ]);

    (new ChannelFindAndReplace(
        user_id: $this->user->id,
        use_regex: false,
        column: 'info->genre',
        find_replace: 'Unknown',
        replace_with: '0',
        all_playlists: false,
        playlist_id: $this->playlist->id,
        silent: true,
        is_vod: true,
    ))->handle();

    expect($channelA->fresh()->info['genre'])->toBe('0');
});

it('still persists a nonempty replacement (positive control)', function () {
    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'is_vod' => true,
        'info' => ['genre' => 'Unknown', 'description' => 'Keep me'],
    ]);

    (new ChannelFindAndReplace(
        user_id: $this->user->id,
        use_regex: false,
        column: 'info->genre',
        find_replace: 'Unknown',
        replace_with: 'Known',
        all_playlists: false,
        playlist_id: $this->playlist->id,
        silent: true,
        is_vod: true,
    ))->handle();

    expect($channelA->fresh()->info['genre'])->toBe('Known')
        ->and($channelA->fresh()->info['description'])->toBe('Keep me');
});
