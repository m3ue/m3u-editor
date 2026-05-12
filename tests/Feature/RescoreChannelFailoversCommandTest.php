<?php

use App\Jobs\RescoreChannelFailovers;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
});

it('dispatches rescore for playlists whose interval has elapsed', function () {
    $due = Playlist::factory()->for($this->user)->createQuietly([
        'auto_rescore_failovers_interval' => 'daily',
        'last_failover_rescore_at' => now()->subDays(2),
    ]);

    $notDue = Playlist::factory()->for($this->user)->createQuietly([
        'auto_rescore_failovers_interval' => 'daily',
        'last_failover_rescore_at' => now()->subHours(2),
    ]);

    $this->artisan('app:rescore-channel-failovers')->assertExitCode(0);

    Bus::assertDispatched(RescoreChannelFailovers::class, fn (RescoreChannelFailovers $job) => $job->playlistId === $due->id);
    Bus::assertNotDispatched(RescoreChannelFailovers::class, fn (RescoreChannelFailovers $job) => $job->playlistId === $notDue->id);
});

it('dispatches when last_failover_rescore_at is null (never run)', function () {
    $never = Playlist::factory()->for($this->user)->createQuietly([
        'auto_rescore_failovers_interval' => 'weekly',
        'last_failover_rescore_at' => null,
    ]);

    $this->artisan('app:rescore-channel-failovers')->assertExitCode(0);

    Bus::assertDispatched(RescoreChannelFailovers::class, fn (RescoreChannelFailovers $job) => $job->playlistId === $never->id);
});

it('skips playlists with no auto_rescore_failovers_interval set', function () {
    Playlist::factory()->for($this->user)->createQuietly([
        'auto_rescore_failovers_interval' => null,
        'last_failover_rescore_at' => null,
    ]);

    $this->artisan('app:rescore-channel-failovers')->assertExitCode(0);

    Bus::assertNotDispatched(RescoreChannelFailovers::class);
});

it('skips playlists with an unrecognized interval value', function () {
    Playlist::factory()->for($this->user)->createQuietly([
        'auto_rescore_failovers_interval' => 'banana',
        'last_failover_rescore_at' => null,
    ]);

    $this->artisan('app:rescore-channel-failovers')->assertExitCode(0);

    Bus::assertNotDispatched(RescoreChannelFailovers::class);
});

it('respects the weekly interval', function () {
    $dueWeekly = Playlist::factory()->for($this->user)->createQuietly([
        'auto_rescore_failovers_interval' => 'weekly',
        'last_failover_rescore_at' => now()->subDays(8),
    ]);

    $notDueWeekly = Playlist::factory()->for($this->user)->createQuietly([
        'auto_rescore_failovers_interval' => 'weekly',
        'last_failover_rescore_at' => now()->subDays(3),
    ]);

    $this->artisan('app:rescore-channel-failovers')->assertExitCode(0);

    Bus::assertDispatched(RescoreChannelFailovers::class, fn (RescoreChannelFailovers $job) => $job->playlistId === $dueWeekly->id);
    Bus::assertNotDispatched(RescoreChannelFailovers::class, fn (RescoreChannelFailovers $job) => $job->playlistId === $notDueWeekly->id);
});

it('dispatches a single playlist immediately when an ID is passed', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'auto_rescore_failovers_interval' => null,
        'last_failover_rescore_at' => now(),
    ]);

    $this->artisan('app:rescore-channel-failovers', ['playlist' => $playlist->id])->assertExitCode(0);

    Bus::assertDispatched(RescoreChannelFailovers::class, fn (RescoreChannelFailovers $job) => $job->playlistId === $playlist->id);
});

it('returns failure when the supplied playlist ID does not exist', function () {
    $this->artisan('app:rescore-channel-failovers', ['playlist' => 999999])->assertExitCode(1);
    Bus::assertNotDispatched(RescoreChannelFailovers::class);
});
