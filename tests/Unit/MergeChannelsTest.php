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
        $channel1 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist->id, 'group_id' => null]);
        $channel2 = Channel::factory()->create(['stream_id' => 'stream1', 'user_id' => $user->id, 'playlist_id' => $playlist->id, 'group_id' => null]);
        // Channels with empty stream_ids should not be merged
        $channel3 = Channel::factory()->create(['stream_id' => '', 'user_id' => $user->id, 'playlist_id' => $playlist->id, 'group_id' => null]);
        $channel4 = Channel::factory()->create(['stream_id' => null, 'user_id' => $user->id, 'playlist_id' => $playlist->id, 'group_id' => null]);

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
        ]);

        $failover = Channel::factory()->create([
            'is_vod' => true,
            'tmdb_id' => 12345,
            'stream_id' => 'provider-b-999',
            'user_id' => $user->id,
            'playlist_id' => $playlist2->id,
            'group_id' => null,
        ]);

        $unrelatedLive = Channel::factory()->create([
            'is_vod' => false,
            'tmdb_id' => 12345,
            'stream_id' => 'live-provider-999',
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
    public function promoted_master_is_enabled_and_old_master_is_deactivated_when_failovers_are_deactivated()
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

        // Existing failover relationship (old master had a failover)
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
        // Ensure failover relationships exist for the new master
        $this->assertDatabaseHas('channel_failovers', [
            'channel_id' => $newMaster->id,
            'channel_failover_id' => $oldMaster->id,
        ]);
        $this->assertDatabaseHas('channel_failovers', [
            'channel_id' => $newMaster->id,
            'channel_failover_id' => $failover->id,
        ]);
    }
}
