<?php

namespace Tests\Unit;

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MergeChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function runMergeChannels(...$arguments): void
    {
        Channel::withoutEvents(fn () => (new MergeChannels(...$arguments))->handle());
    }

    #[Test]
    public function it_does_not_merge_channels_with_empty_stream_ids()
    {
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
        $this->runMergeChannels($user, $playlists, $playlist->id);

        // Assert that only the channels with the same non-empty stream_id were merged
        // channel1 and channel2 have same stream_id, so there should be 1 failover entry
        $this->assertDatabaseCount('channel_failovers', 1);
    }

    #[Test]
    public function it_merges_vod_channels_by_tmdb_id_when_requested()
    {
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

        $this->runMergeChannels(
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
    }

    #[Test]
    public function vod_merge_keeps_stream_id_matching_by_default()
    {
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

        $this->runMergeChannels(
            user: $user,
            playlists: collect([
                ['playlist_failover_id' => $playlist1->id],
                ['playlist_failover_id' => $playlist2->id],
            ]),
            playlistId: $playlist1->id,
            contentType: 'vod',
        );

        $this->assertDatabaseCount('channel_failovers', 0);
    }

    #[Test]
    public function hidden_failover_can_be_promoted_to_master_and_old_master_is_deactivated()
    {
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
        $this->runMergeChannels($user, $playlists, $playlist2->id, false, true);

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
    }

    #[Test]
    public function disabled_preferred_channel_that_is_not_an_existing_failover_is_not_promoted_but_can_be_failover()
    {
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
        $this->runMergeChannels($user, $playlists, $playlist2->id, false, true, scrubberAwareMasterSelection: true);

        $this->assertTrue($enabledMaster->refresh()->enabled);
        $this->assertFalse($disabledPreferred->refresh()->enabled);
        $this->assertDatabaseHas('channel_failovers', [
            'channel_id' => $enabledMaster->id,
            'channel_failover_id' => $disabledPreferred->id,
        ]);
    }

    #[Test]
    public function scrubber_dead_channel_that_is_not_existing_topology_is_added_after_healthier_failovers()
    {
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

        $this->runMergeChannels($user, $playlists, $playlist3->id, false, true, scrubberAwareMasterSelection: true);

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
    }

    #[Test]
    public function scrubber_dead_hidden_failover_is_not_promoted_but_mapping_is_preserved()
    {
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
        $this->runMergeChannels($user, $playlists, $playlist2->id, false, true, scrubberAwareMasterSelection: true);

        $this->assertTrue($currentMaster->refresh()->enabled);
        $this->assertFalse($deadHiddenFailover->refresh()->enabled);
        $this->assertDatabaseHas('channel_failovers', [
            'channel_id' => $currentMaster->id,
            'channel_failover_id' => $deadHiddenFailover->id,
        ]);
    }

    #[Test]
    public function scrubber_dead_master_rotates_to_live_failover_and_restores_when_live_again()
    {
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

        $this->runMergeChannels($user, $playlists, $playlist1->id, false, true, scrubberAwareMasterSelection: true);

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

        $this->runMergeChannels($user, $playlists, $playlist1->id, false, true, scrubberAwareMasterSelection: true);

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
    }
}
