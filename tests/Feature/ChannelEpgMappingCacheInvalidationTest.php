<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use Illuminate\Support\Facades\Storage;

/**
 * A manual EPG reassignment on a Channel (through the Channel edit form or the EPG viewer's
 * edit action, both of which save via Channel::update()) must invalidate the playlist's
 * generated XMLTV/gzip output files. Otherwise the next download keeps serving the previous
 * mapping's programmes until the output cache's own TTL expires.
 */
beforeEach(function () {
    Storage::fake('local');
});

it('invalidates a standard playlist output cache when a channel epg mapping changes', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannelA = EpgChannel::factory()->for($user)->for($epg)->create();
    $epgChannelB = EpgChannel::factory()->for($user)->for($epg)->create();

    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannelA->id,
    ]);

    $xmlPath = EpgCacheService::getPlaylistEpgCachePath($playlist, false);
    $gzPath = EpgCacheService::getPlaylistEpgCachePath($playlist, true);
    Storage::disk('local')->put($xmlPath, '<tv>stale A</tv>');
    Storage::disk('local')->put($gzPath, gzencode('<tv>stale A</tv>'));

    $channel->update(['epg_channel_id' => $epgChannelB->id]);

    expect(Storage::disk('local')->exists($xmlPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($gzPath))->toBeFalse();
});

it('invalidates a custom playlist output cache when a member channel epg mapping changes', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannelA = EpgChannel::factory()->for($user)->for($epg)->create();
    $epgChannelB = EpgChannel::factory()->for($user)->for($epg)->create();

    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannelA->id,
    ]);
    $customPlaylist->channels()->attach($channel->id);
    expect($customPlaylist->channels()->count())->toBe(1);

    $xmlPath = EpgCacheService::getPlaylistEpgCachePath($customPlaylist, false);
    Storage::disk('local')->put($xmlPath, '<tv>stale A</tv>');

    $channel->update(['epg_channel_id' => $epgChannelB->id]);

    expect($channel->customPlaylists()->count())->toBe(1);
    expect(Storage::disk('local')->exists($xmlPath))->toBeFalse();
});

it('does not touch the output cache when an unrelated channel field changes', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create();

    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
    ]);

    $xmlPath = EpgCacheService::getPlaylistEpgCachePath($playlist, false);
    Storage::disk('local')->put($xmlPath, '<tv>still valid</tv>');

    $channel->update(['name_custom' => 'Renamed Channel']);

    expect(Storage::disk('local')->exists($xmlPath))->toBeTrue();
});
