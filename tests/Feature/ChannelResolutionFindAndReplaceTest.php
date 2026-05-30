<?php

use App\Jobs\ChannelFindAndReplace;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;

it('only renames channels whose probed resolution matches the requested resolution', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();

    $matchingChannel = Channel::factory()
        ->for($user)
        ->for($playlist)
        ->createQuietly([
            'title' => 'Example 4K',
            'title_custom' => null,
            'stream_stats' => ['resolution' => '1920x1080'],
            'stream_stats_probed_at' => now(),
        ]);

    $wrongResolutionChannel = Channel::factory()
        ->for($user)
        ->for($playlist)
        ->createQuietly([
            'title' => 'Other 4K',
            'title_custom' => null,
            'stream_stats' => ['resolution' => '3840x2160'],
            'stream_stats_probed_at' => now(),
        ]);

    $unprobedChannel = Channel::factory()
        ->for($user)
        ->for($playlist)
        ->createQuietly([
            'title' => 'Unprobed 4K',
            'title_custom' => null,
            'stream_stats' => null,
            'stream_stats_probed_at' => null,
        ]);

    (new ChannelFindAndReplace(
        user_id: $user->id,
        use_regex: false,
        column: 'title',
        find_replace: '4K',
        replace_with: 'HD',
        all_playlists: true,
        silent: true,
        resolution_filter_enabled: true,
        required_resolution: '1920x1080',
    ))->handle();

    expect($matchingChannel->fresh()->title_custom)->toBe('Example HD')
        ->and($wrongResolutionChannel->fresh()->title_custom)->toBeNull()
        ->and($unprobedChannel->fresh()->title_custom)->toBeNull();
});

it('keeps find and replace unchanged when the resolution filter is disabled', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();

    $unprobedChannel = Channel::factory()
        ->for($user)
        ->for($playlist)
        ->createQuietly([
            'title' => 'Unprobed 4K',
            'title_custom' => null,
            'stream_stats' => null,
            'stream_stats_probed_at' => null,
        ]);

    (new ChannelFindAndReplace(
        user_id: $user->id,
        use_regex: false,
        column: 'title',
        find_replace: '4K',
        replace_with: 'HD',
        all_playlists: true,
        silent: true,
        resolution_filter_enabled: false,
        required_resolution: '1920x1080',
    ))->handle();

    expect($unprobedChannel->fresh()->title_custom)->toBe('Unprobed HD');
});
