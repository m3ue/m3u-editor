<?php

/**
 * Regression coverage for DvrRecording::notifyTv(), the persisted-notification
 * counterpart to broadcastStatus(). Unlike broadcastStatus() (fired on every
 * status change, purely for the TV app's live "recording" dot and DVR
 * Recordings list refresh), notifyTv() is only called at the specific
 * user-facing transitions — started, completed, failed, cancelled — and
 * reuses the same TvNotification/TvNotificationEvent pipeline as every other
 * TV notification, so it gets persistence, the unread badge, Notifications
 * screen history, and channel-subscription filtering for free.
 */

use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PushDeviceToken;
use App\Models\TvNotification;
use App\Models\User;
use App\Services\PushRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->create(['enabled' => true, 'title_custom' => 'News 24']);

    $this->recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['title' => 'Evening News']);
});

it('persists a TvNotification and dispatches TvNotificationEvent on the dvr channel', function () {
    Event::fake([TvNotificationEvent::class]);

    $this->recording->notifyTv('Recording Started', 'info');

    $record = TvNotification::query()->latest('created_at')->first();

    expect($record)->not->toBeNull();
    expect($record->notifiable_type)->toBe($this->playlist->getMorphClass());
    expect($record->notifiable_id)->toBe($this->playlist->id);
    expect($record->channel)->toBe('dvr');
    expect($record->title)->toBe('Recording Started');
    expect($record->body)->toBe('Evening News');
    expect($record->status)->toBe('info');

    Event::assertDispatched(
        TvNotificationEvent::class,
        fn (TvNotificationEvent $event) => $event->id === $record->id
            && $event->channel === 'dvr'
            && $event->title === 'Recording Started'
            && $event->notifiableUuid === $this->playlist->uuid
    );
});

it('delivers a global dvr status notification to every entitled credential transport', function () {
    $playlistAuths = PlaylistAuth::factory()->count(2)->for($this->user)->create([
        'enabled' => true,
    ]);

    foreach ($playlistAuths as $index => $playlistAuth) {
        $playlistAuth->assignTo($this->playlist);
        PushDeviceToken::factory()->create([
            'notifiable_type' => $this->playlist->getMorphClass(),
            'notifiable_id' => $this->playlist->id,
            'playlist_auth_id' => $playlistAuth->id,
            'token' => "dvr-device-{$index}",
        ]);
    }

    Event::fake([TvNotificationEvent::class]);

    $this->recording->notifyTv('Recording Started', 'info');

    $notification = TvNotification::sole();
    $event = null;
    $relayJob = null;

    Event::assertDispatched(TvNotificationEvent::class, function (TvNotificationEvent $dispatched) use (&$event): bool {
        $event = $dispatched;

        return true;
    });
    Queue::assertPushed(SendPushNotificationRelay::class, function (SendPushNotificationRelay $dispatched) use (&$relayJob): bool {
        $relayJob = $dispatched;

        return true;
    });

    $channels = collect($event->broadcastOn())->pluck('name');
    foreach ($playlistAuths as $playlistAuth) {
        expect($channels)->toContain("private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$playlistAuth->id}");
    }

    $notificationIds = [];
    $relay = Mockery::mock(PushRelayService::class);
    $relay->shouldReceive('isEnabled')->once()->andReturnTrue();
    $relay->shouldReceive('send')
        ->twice()
        ->andReturnUsing(function (string $token, string $platform, string $title, ?string $body, ?array $data) use (&$notificationIds): void {
            $notificationIds[$token] = data_get($data, 'notification_id');
        });

    $relayJob->handle($relay);

    expect($notification->playlist_auth_id)->toBeNull()
        ->and($event->id)->toBe($notification->id)
        ->and($relayJob->notificationUuid)->toBe($notification->id)
        ->and($notificationIds)->toBe([
            'dvr-device-0' => $notification->id,
            'dvr-device-1' => $notification->id,
        ]);
});

it('does nothing when the dvr setting has no resolvable owning playlist', function () {
    $orphanSetting = DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => null,
        'merged_playlist_id' => null,
    ]);
    $orphanRecording = DvrRecording::factory()
        ->for($this->user)
        ->for($orphanSetting, 'dvrSetting')
        ->for($this->channel)
        ->create();

    Event::fake([TvNotificationEvent::class]);

    $orphanRecording->notifyTv('Recording Started', 'info');

    Event::assertNotDispatched(TvNotificationEvent::class);
});
