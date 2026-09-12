<?php

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

function runMergeChannels(...$arguments): void
{
    Channel::withoutEvents(fn () => (new MergeChannels(...$arguments))->handle());
}

it('does not merge channels with empty stream ids', function () {
    // Create a user and playlist
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();

    // Create channels for the playlist with same stream_id
    $channel1 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist->id, 'group_id' => null, 'enabled' => true]);
    $channel2 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist->id, 'group_id' => null, 'enabled' => true]);
    // Channels with empty stream_ids should not be merged
    $channel3 = Channel::factory()->create(['stream_id' => '', 'user_id' => $user->id, 'playlist_id' => $playlist->id, 'group_id' => null, 'enabled' => true]);
    $channel4 = Channel::factory()->create(['stream_id' => null, 'user_id' => $user->id, 'playlist_id' => $playlist->id, 'group_id' => null, 'enabled' => true]);

    // Create playlists collection as expected by MergeChannels constructor
    $playlists = collect([['playlist_failover_id' => $playlist->id]]);

    // Run the job synchronously (dispatchSync instead of dispatch)
    runMergeChannels($user, $playlists, $playlist->id);

    // Assert that only the channels with the same non-empty stream_id were merged
    // channel1 and channel2 have same stream_id, so there should be 1 failover entry
    $this->assertDatabaseCount('channel_failovers', 1);
});

it('does not create a failover for a candidate with the exact same stream url as the master', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();

    $master = Channel::factory()->create([
        'stream_id' => 'streamDup',
        'url' => 'http://provider.test/stream/dup.m3u8',
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => null,
        'enabled' => true,
        'sort' => 1,
    ]);

    // Same stream_id, identical URL - a failover here is a no-op duplicate.
    $identicalUrlCandidate = Channel::factory()->create([
        'stream_id' => 'streamDup',
        'url' => 'http://provider.test/stream/dup.m3u8',
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => null,
        'enabled' => true,
        'sort' => 2,
    ]);

    // Same stream_id, different URL - this one still provides real redundancy.
    $distinctUrlCandidate = Channel::factory()->create([
        'stream_id' => 'streamDup',
        'url' => 'http://provider.test/stream/dup-alt.m3u8',
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => null,
        'enabled' => true,
        'sort' => 3,
    ]);

    $playlists = collect([['playlist_failover_id' => $playlist->id]]);

    runMergeChannels($user, $playlists, $playlist->id);

    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $identicalUrlCandidate->id,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $distinctUrlCandidate->id,
    ]);
});

it('merges vod channels by tmdb id when requested', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $master = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-a-100',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
    ]);

    $failover = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-b-999',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => true,
    ]);

    $unrelatedLive = Channel::factory()->create([
        'is_vod' => false,
        'tmdb_id' => 12345,
        'stream_id' => 'live-provider-999',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => true,
    ]);

    runMergeChannels(
        user: $user,
        playlists: collect([
            ['playlist_failover_id' => $playlist1->id],
            ['playlist_failover_id' => $playlist2->id],
        ]),
        playlistId: $playlist1->id,
        contentType: 'vod',
        mergeKey: 'tmdb_id',
    );

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $failover->id,
    ]);
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $unrelatedLive->id,
    ]);
});

it('keeps stream id matching for vod merge by default', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-a-100',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
    ]);

    Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-b-999',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
    ]);

    runMergeChannels(
        user: $user,
        playlists: collect([
            ['playlist_failover_id' => $playlist1->id],
            ['playlist_failover_id' => $playlist2->id],
        ]),
        playlistId: $playlist1->id,
        contentType: 'vod',
    );

    $this->assertDatabaseCount('channel_failovers', 0);
});

it('promotes a hidden failover to master and deactivates the old master', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    // Create channels with same stream id
    $oldMaster = Channel::factory()->create([
        'stream_id' => 'streamX',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
    ]);

    $newMaster = Channel::factory()->create([
        'stream_id' => 'streamX',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => false,
    ]);

    $failover = Channel::factory()->create([
        'stream_id' => 'streamX',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
    ]);

    // Existing failover relationships. The new preferred master is disabled
    // because it is currently hidden as a native auto-merge failover.
    ChannelFailover::create([
        'user_id' => $user->id,
        'channel_id' => $oldMaster->id,
        'channel_failover_id' => $newMaster->id,
    ]);
    ChannelFailover::create([
        'user_id' => $user->id,
        'channel_id' => $oldMaster->id,
        'channel_failover_id' => $failover->id,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    // Run job preferring playlist2 as primary and deactivating failovers
    runMergeChannels($user, $playlists, $playlist2->id, false, true);

    // Reload models from DB
    $oldMaster->refresh();
    $newMaster->refresh();
    $failover->refresh();

    $this->assertTrue($newMaster->enabled, 'Promoted master should be enabled');
    $this->assertFalse($oldMaster->enabled, 'Old master should be deactivated as a failover');
    $this->assertFalse($failover->enabled, 'Remaining failover should be deactivated');
    // Ensure failover relationships exist for the new master
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $newMaster->id,
        'channel_failover_id' => $oldMaster->id,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $newMaster->id,
        'channel_failover_id' => $failover->id,
    ]);
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $oldMaster->id,
        'channel_failover_id' => $newMaster->id,
    ]);
});

it('does not promote a disabled preferred channel that is not an existing failover but can still become a failover', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $enabledMaster = Channel::factory()->create([
        'stream_id' => 'streamY',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
    ]);

    $disabledPreferred = Channel::factory()->create([
        'stream_id' => 'streamY',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => false,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    // The preferred playlist contains the disabled channel, but it is not
    // an existing hidden failover. Merge should not promote/re-enable it
    // as master, but it can still be linked as a hidden failover.
    runMergeChannels($user, $playlists, $playlist2->id, false, true, scrubberAwareMasterSelection: true);

    $this->assertTrue($enabledMaster->refresh()->enabled);
    $this->assertFalse($disabledPreferred->refresh()->enabled);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $enabledMaster->id,
        'channel_failover_id' => $disabledPreferred->id,
    ]);
});

it('adds a scrubber dead channel that is not existing topology after healthier failovers', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();
    $playlist3 = Playlist::factory()->for($user)->createQuietly();

    $enabledMaster = Channel::factory()->create([
        'stream_id' => 'streamY-dead',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
        'last_scrubber_live' => true,
    ]);

    $healthyCandidate = Channel::factory()->create([
        'stream_id' => 'streamY-dead',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => false,
        'last_scrubber_live' => true,
    ]);

    $deadCandidate = Channel::factory()->create([
        'stream_id' => 'streamY-dead',
        'user_id' => $user->id,
        'playlist_id' => $playlist3->id,
        'group_id' => null,
        'enabled' => false,
        'last_scrubber_live' => false,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
        ['playlist_failover_id' => $playlist3->id],
    ]);

    runMergeChannels($user, $playlists, $playlist3->id, false, true, scrubberAwareMasterSelection: true);

    $this->assertTrue($enabledMaster->refresh()->enabled);
    $this->assertFalse($healthyCandidate->refresh()->enabled);
    $this->assertFalse($deadCandidate->refresh()->enabled);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $enabledMaster->id,
        'channel_failover_id' => $healthyCandidate->id,
        'sort' => 1,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $enabledMaster->id,
        'channel_failover_id' => $deadCandidate->id,
        'sort' => 2,
    ]);
});

it('does not promote a scrubber dead hidden failover but preserves the mapping', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $currentMaster = Channel::factory()->create([
        'stream_id' => 'streamZ',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
        'last_scrubber_live' => true,
    ]);

    $deadHiddenFailover = Channel::factory()->create([
        'stream_id' => 'streamZ',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => false,
        'last_scrubber_live' => false,
    ]);

    ChannelFailover::create([
        'user_id' => $user->id,
        'channel_id' => $currentMaster->id,
        'channel_failover_id' => $deadHiddenFailover->id,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    // Playlist 2 would normally be preferred, and the disabled channel is
    // an existing hidden failover. The scrubber-dead state makes it
    // unavailable as master, but the native failover mapping is topology
    // and should be preserved.
    runMergeChannels($user, $playlists, $playlist2->id, false, true, scrubberAwareMasterSelection: true);

    $this->assertTrue($currentMaster->refresh()->enabled);
    $this->assertFalse($deadHiddenFailover->refresh()->enabled);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $currentMaster->id,
        'channel_failover_id' => $deadHiddenFailover->id,
    ]);
});

it('rotates a scrubber dead master to a live failover and restores it when live again', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();
    $playlist3 = Playlist::factory()->for($user)->createQuietly();

    $oldMaster = Channel::factory()->create([
        'stream_id' => 'streamR',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'sort' => 1.0,
        'enabled' => false,
        'last_scrubber_live' => false,
    ]);

    $liveFailover = Channel::factory()->create([
        'stream_id' => 'streamR',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'sort' => 2.0,
        'enabled' => false,
        'last_scrubber_live' => true,
    ]);

    $secondaryFailover = Channel::factory()->create([
        'stream_id' => 'streamR',
        'user_id' => $user->id,
        'playlist_id' => $playlist3->id,
        'group_id' => null,
        'sort' => 3.0,
        'enabled' => false,
        'last_scrubber_live' => true,
    ]);

    ChannelFailover::create([
        'user_id' => $user->id,
        'channel_id' => $oldMaster->id,
        'channel_failover_id' => $liveFailover->id,
        'sort' => 1,
    ]);
    ChannelFailover::create([
        'user_id' => $user->id,
        'channel_id' => $oldMaster->id,
        'channel_failover_id' => $secondaryFailover->id,
        'sort' => 2,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
        ['playlist_failover_id' => $playlist3->id],
    ]);

    runMergeChannels($user, $playlists, $playlist1->id, false, true, scrubberAwareMasterSelection: true);

    $this->assertFalse($oldMaster->refresh()->enabled);
    $this->assertTrue($liveFailover->refresh()->enabled);
    $this->assertFalse($secondaryFailover->refresh()->enabled);
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $oldMaster->id,
        'channel_failover_id' => $liveFailover->id,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $liveFailover->id,
        'channel_failover_id' => $secondaryFailover->id,
        'sort' => 1,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $liveFailover->id,
        'channel_failover_id' => $oldMaster->id,
        'sort' => 2,
    ]);

    $oldMaster->update(['last_scrubber_live' => true]);

    runMergeChannels($user, $playlists, $playlist1->id, false, true, scrubberAwareMasterSelection: true);

    $this->assertTrue($oldMaster->refresh()->enabled);
    $this->assertFalse($liveFailover->refresh()->enabled);
    $this->assertFalse($secondaryFailover->refresh()->enabled);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $oldMaster->id,
        'channel_failover_id' => $liveFailover->id,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $oldMaster->id,
        'channel_failover_id' => $secondaryFailover->id,
    ]);
});

it('keeps merging unrelated titles when the similarity guard is off', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();

    // Same stream_id, wildly different channels. This is the pre-existing
    // behaviour and must not change unless the guard is explicitly turned on.
    foreach (['UK: BBC 3 / CBBC HD', 'UK: FOOD NETWORK +1'] as $index => $title) {
        Channel::factory()->create([
            'stream_id' => 'TS',
            'title' => $title,
            'url' => "http://provider.test/stream/{$index}.ts",
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'group_id' => null,
            'enabled' => true,
        ]);
    }

    $playlists = collect([['playlist_failover_id' => $playlist->id]]);

    runMergeChannels($user, $playlists, $playlist->id);

    $this->assertDatabaseCount('channel_failovers', 1);
});

it('does not create a failover when the titles describe different channels', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();

    // A mis-parsed stream id ("TS" is the container extension, not an id) is
    // enough to group unrelated channels. The guard should refuse the pairing.
    foreach (['UK: BBC 3 / CBBC HD', 'UK: FOOD NETWORK +1'] as $index => $title) {
        Channel::factory()->create([
            'stream_id' => 'TS',
            'title' => $title,
            'url' => "http://provider.test/stream/{$index}.ts",
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'group_id' => null,
            'enabled' => true,
        ]);
    }

    $playlists = collect([['playlist_failover_id' => $playlist->id]]);

    runMergeChannels($user, $playlists, $playlist->id, minTitleSimilarity: 0.4);

    $this->assertDatabaseCount('channel_failovers', 0);
});

it('still merges the same channel across quality variants when the guard is on', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();

    // Source prefixes and quality suffixes differ, the channel does not.
    // Comparing the raw titles would score these as unrelated.
    foreach (['DE| SYFY HEVC', 'JOYN| SYFY FHD ᴿᴬᵂ'] as $index => $title) {
        Channel::factory()->create([
            'stream_id' => 'syfy.de',
            'title' => $title,
            'url' => "http://provider.test/stream/syfy-{$index}.ts",
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'group_id' => null,
            'enabled' => true,
        ]);
    }

    $playlists = collect([['playlist_failover_id' => $playlist->id]]);

    runMergeChannels($user, $playlists, $playlist->id, minTitleSimilarity: 0.4);

    $this->assertDatabaseCount('channel_failovers', 1);
});

it('still merges an event feed against its base channel when the guard is on', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();

    // Providers append the fixture to the title of the channel carrying it.
    foreach (['BE| DAZN 7 - OH Leuven - Standard', 'BE - DAZN 7'] as $index => $title) {
        Channel::factory()->create([
            'stream_id' => 'dazn7.be',
            'title' => $title,
            'url' => "http://provider.test/stream/dazn-{$index}.ts",
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'group_id' => null,
            'enabled' => true,
        ]);
    }

    $playlists = collect([['playlist_failover_id' => $playlist->id]]);

    runMergeChannels($user, $playlists, $playlist->id, minTitleSimilarity: 0.4);

    $this->assertDatabaseCount('channel_failovers', 1);
});

it('still merges an abbreviated title against its expanded form when the guard is on', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();

    // "E!" reduces to a single character; scoring it would call these unrelated.
    foreach (['FR| E! FHD', 'FR - E! ENTERTAINMENT HD'] as $index => $title) {
        Channel::factory()->create([
            'stream_id' => 'e.fr',
            'title' => $title,
            'url' => "http://provider.test/stream/e-{$index}.ts",
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'group_id' => null,
            'enabled' => true,
        ]);
    }

    $playlists = collect([['playlist_failover_id' => $playlist->id]]);

    runMergeChannels($user, $playlists, $playlist->id, minTitleSimilarity: 0.4);

    $this->assertDatabaseCount('channel_failovers', 1);
});
