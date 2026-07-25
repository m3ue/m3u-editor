<?php

/**
 * Stage 1a: StartDvrRecording failure notification coverage.
 *
 * When a DVR recording fails to start, the catch block must:
 * 1. Transition the recording to Failed status with the error message.
 * 2. Persist exactly one TvNotification on the 'dvr' channel (so the TV app
 *    sees it in the Notifications screen and the unread badge increments).
 * 3. Dispatch exactly one SendPushNotificationRelay job (so mobile devices
 *    receive a push while the app is backgrounded).
 */

use App\Enums\DvrRecordingStatus;
use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Jobs\StartDvrRecording;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\TvNotification;
use App\Models\User;
use App\Services\DvrRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->dvrSetting = DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create();
    $this->group = Group::factory()->for($this->user)->create();
    $this->channel = Channel::factory()
        ->for($this->playlist)
        ->for($this->group)
        ->create(['enabled' => true]);
});

it('transitions to Failed and persists a TvNotification when recording fails to start', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'title' => 'Evening News',
            'stream_url' => 'http://example.com/stream',
        ]);

    $exceptionMessage = 'FFmpeg binary not found';
    $recorder = Mockery::mock(DvrRecorderService::class);
    $recorder->shouldReceive('start')
        ->once()
        ->andThrow(new Exception($exceptionMessage));
    $this->app->instance(DvrRecorderService::class, $recorder);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    $job = new StartDvrRecording($recording->id);
    $job->handle($recorder);

    $recording->refresh();

    expect($recording->status)->toBe(DvrRecordingStatus::Failed);
    expect($recording->error_message)->toBe($exceptionMessage);

    expect(TvNotification::count())->toBe(1);

    $notification = TvNotification::first();
    expect($notification->notifiable_type)->toBe($this->playlist->getMorphClass())
        ->and($notification->notifiable_id)->toBe($this->playlist->id)
        ->and($notification->channel)->toBe('dvr')
        ->and($notification->title)->toBe('Recording Failed')
        ->and($notification->status)->toBe('danger')
        ->and($notification->body)->toBe('Evening News');

    Event::assertDispatched(TvNotificationEvent::class, function (TvNotificationEvent $event) use ($notification) {
        return $event->id === $notification->id
            && $event->channel === 'dvr'
            && $event->status === 'danger'
            && $event->title === 'Recording Failed';
    });

    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $job) {
        return $job->notifiableType === $this->playlist->getMorphClass()
            && $job->notifiableId === $this->playlist->id
            && $job->title === 'Recording Failed';
    });
});

it('does not persist a TvNotification when recording has no resolvable owning playlist', function () {
    $orphanSetting = DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => null,
        'merged_playlist_id' => null,
    ]);
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($orphanSetting, 'dvrSetting')
        ->for($this->channel)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'stream_url' => 'http://example.com/stream',
        ]);

    $recorder = Mockery::mock(DvrRecorderService::class);
    $recorder->shouldReceive('start')
        ->once()
        ->andThrow(new Exception('No stream'));
    $this->app->instance(DvrRecorderService::class, $recorder);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    $job = new StartDvrRecording($recording->id);
    $job->handle($recorder);

    expect(TvNotification::count())->toBe(0);
    Event::assertNotDispatched(TvNotificationEvent::class);
    Bus::assertNotDispatched(SendPushNotificationRelay::class);
});

it('does not emit a notification when recording is not in Scheduled status', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create([
            'status' => DvrRecordingStatus::Recording,
            'stream_url' => 'http://example.com/stream',
        ]);

    $recorder = Mockery::mock(DvrRecorderService::class);
    $this->app->instance(DvrRecorderService::class, $recorder);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    $job = new StartDvrRecording($recording->id);
    $job->handle($recorder);

    $recording->refresh();
    expect($recording->status)->toBe(DvrRecordingStatus::Recording);
    expect(TvNotification::count())->toBe(0);
    Event::assertNotDispatched(TvNotificationEvent::class);
    Bus::assertNotDispatched(SendPushNotificationRelay::class);
});
