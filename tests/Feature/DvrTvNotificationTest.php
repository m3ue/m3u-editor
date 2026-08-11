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
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PushDeviceToken;
use App\Models\TvNotification;
use App\Models\User;
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

it('notifies only the admin channel for an owner-created recording, never any guest', function () {
    // The recording has no playlist_auth_id (created directly by the owner),
    // so no guest — regardless of their own dvr_enabled flag — should see it.
    $playlistAuths = PlaylistAuth::factory()->count(2)->for($this->user)->create([
        'enabled' => true,
        'dvr_enabled' => true,
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

    Event::assertDispatched(TvNotificationEvent::class, function (TvNotificationEvent $dispatched) use (&$event): bool {
        $event = $dispatched;

        return true;
    });

    $channels = collect($event->broadcastOn())->pluck('name');
    expect($channels->values()->all())->toBe([
        "private-tv.{$this->playlist->getMorphClass()}-admin.{$this->playlist->uuid}",
    ]);

    foreach ($playlistAuths as $playlistAuth) {
        expect($channels)->not->toContain("private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$playlistAuth->id}");
    }

    expect($notification->playlist_auth_id)->toBeNull()
        ->and($event->adminOnly)->toBeTrue();
});

it('notifies only the owning guest\'s channel for a guest-created recording', function () {
    $owningAuth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'dvr_enabled' => true,
    ]);
    $otherAuth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'dvr_enabled' => true,
    ]);
    $owningAuth->assignTo($this->playlist);
    $otherAuth->assignTo($this->playlist);

    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['title' => 'Evening News', 'playlist_auth_id' => $owningAuth->id]);

    Event::fake([TvNotificationEvent::class]);

    $recording->notifyTv('Recording Started', 'info');

    $notification = TvNotification::sole();
    $event = null;

    Event::assertDispatched(TvNotificationEvent::class, function (TvNotificationEvent $dispatched) use (&$event): bool {
        $event = $dispatched;

        return true;
    });

    $channels = collect($event->broadcastOn())->pluck('name');
    expect($channels->values()->all())->toBe([
        "private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$owningAuth->id}",
    ]);

    expect($notification->playlist_auth_id)->toBe($owningAuth->id);
});

it('skips the notification entirely when the owning guest\'s dvr_enabled is false', function () {
    $owningAuth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'dvr_enabled' => false,
    ]);
    $owningAuth->assignTo($this->playlist);

    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['title' => 'Evening News', 'playlist_auth_id' => $owningAuth->id]);

    Event::fake([TvNotificationEvent::class]);

    $recording->notifyTv('Recording Started', 'info');

    Event::assertNotDispatched(TvNotificationEvent::class);
    expect(TvNotification::count())->toBe(0);
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
