<?php

use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Release the ShouldBeUnique lock from any prior test — the lock lives
    // in Redis (hardcoded cache driver) and survives between tests.
    Cache::lock('laravel_unique_job:App\Jobs\SyncPlexDvrJob:sync-plex-dvr-all')->forceRelease();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id]);
    $this->group = Group::factory()->createQuietly([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
});

it('dispatches SyncPlexDvrJob when channel enabled status changes', function () {
    Bus::fake();

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'enabled' => false,
    ]);

    $channel->update(['enabled' => true]);

    Bus::assertDispatched(SyncPlexDvrJob::class, function (SyncPlexDvrJob $job) {
        return $job->trigger === 'channel_observer';
    });
});

it('dispatches SyncPlexDvrJob when channel is disabled', function () {
    Bus::fake();

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'enabled' => true,
    ]);

    $channel->update(['enabled' => false]);

    Bus::assertDispatched(SyncPlexDvrJob::class, function (SyncPlexDvrJob $job) {
        return $job->trigger === 'channel_observer';
    });
});

it('does not dispatch SyncPlexDvrJob when other fields change', function () {
    Bus::fake();

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'enabled' => true,
    ]);

    $channel->update(['name' => 'Updated Name']);

    Bus::assertNotDispatched(SyncPlexDvrJob::class);
});

it('does not dispatch SyncPlexDvrJob on mass update (query builder)', function () {
    Bus::fake();

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'enabled' => false,
    ]);

    Channel::where('id', $channel->id)->update(['enabled' => true]);

    Bus::assertNotDispatched(SyncPlexDvrJob::class);
});

it('does not dispatch SyncPlexDvrJob while the playlist is processing', function () {
    Bus::fake();

    $this->playlist->forceFill([
        'processing' => ['live_processing' => true],
    ])->save();

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'enabled' => false,
    ]);

    $channel->update(['enabled' => true]);

    Bus::assertNotDispatched(SyncPlexDvrJob::class);
});
