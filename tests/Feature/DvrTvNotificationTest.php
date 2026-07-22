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
