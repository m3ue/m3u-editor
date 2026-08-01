<?php

use App\Jobs\NotifyAioStreamsResolutionComplete;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
});

it('sends a single summary notification once every channel has finished resolving', function () {
    Notification::fake();

    $resolved = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'resolved',
    ]);
    $partial = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'partial',
    ]);
    $failed = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'failed',
    ]);

    (new NotifyAioStreamsResolutionComplete([$resolved->id, $partial->id, $failed->id], [], $this->user->id, 'Test Movie'))->handle();

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn ($notification) => $notification->toArray()['body'] === '3 movies fetched | 2 resolved | 1 failed'
    );
});

it('reports on a series using the episode wording, tallying partial and resolved together', function () {
    Notification::fake();

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $episodes = Episode::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_resolution_status' => 'resolved',
    ]);

    (new NotifyAioStreamsResolutionComplete([], $episodes->pluck('id')->all(), $this->user->id, $series->name))->handle();

    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn ($notification) => $notification->toArray()['body'] === '2 episodes fetched | 2 resolved | 0 failed'
    );
});

it('reschedules itself while items are still pending instead of notifying early', function () {
    Bus::fake();
    Notification::fake();

    $pending = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'pending',
    ]);

    (new NotifyAioStreamsResolutionComplete([$pending->id], [], $this->user->id, 'Test Movie'))->handle();

    Bus::assertDispatched(NotifyAioStreamsResolutionComplete::class, fn ($job) => $job->attempt === 2 && $job->channelIds === [$pending->id]);
    Notification::assertNothingSent();
});

it('gives up waiting and notifies with whatever is resolved after the polling cap is hit', function () {
    Bus::fake();
    Notification::fake();

    $stillPending = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'pending',
    ]);
    $resolved = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'resolved',
    ]);

    (new NotifyAioStreamsResolutionComplete([$stillPending->id, $resolved->id], [], $this->user->id, 'Test Batch', attempt: 20))->handle();

    Bus::assertNotDispatched(NotifyAioStreamsResolutionComplete::class);
    Notification::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn ($notification) => $notification->toArray()['body'] === '2 movies fetched | 1 resolved | 0 failed | 1 still pending'
    );
});

it('does not notify when nothing was passed in', function () {
    Notification::fake();

    (new NotifyAioStreamsResolutionComplete([], [], $this->user->id, 'Nothing'))->handle();

    Notification::assertNothingSent();
});
