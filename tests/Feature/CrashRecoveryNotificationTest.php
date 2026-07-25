<?php

/**
 * Stage 1b: Crash recovery notification coverage.
 *
 * When the app boots and finds stale RECORDING rows (server crashed mid-recording),
 * recoverFromCrash() must:
 * 1. Transition every stale recording to Failed status.
 * 2. Persist exactly one aggregated TvNotification per effective playlist on the
 *    'dvr' channel, summarizing the number of failed recordings.
 * 3. Dispatch exactly one SendPushNotificationRelay per playlist.
 * 4. Handle alias-resolved playlists correctly (the effective playlist owns the
 *    notification, not the alias).
 * 5. Maintain strict cross-playlist isolation: recordings from playlist A must
 *    not produce a notification visible to playlist B.
 */

use App\Enums\DvrRecordingStatus;
use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PushDeviceToken;
use App\Models\TvNotification;
use App\Models\User;
use App\Services\DvrRecorderService;
use App\Services\PushRelayService;
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

it('transitions stale Recording entries to Failed', function () {
    $recording = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Recording]);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    app(DvrRecorderService::class)->recoverFromCrash();

    $recording->refresh();
    expect($recording->status)->toBe(DvrRecordingStatus::Failed);
    expect($recording->error_message)->toBe('Server restarted during recording');
});

it('persists one aggregated TvNotification per effective playlist on crash recovery', function () {
    $recording1 = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Recording, 'title' => 'News']);
    $recording2 = DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Recording, 'title' => 'Sports']);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    app(DvrRecorderService::class)->recoverFromCrash();

    expect(TvNotification::count())->toBe(1);

    $notification = TvNotification::first();
    expect($notification->notifiable_type)->toBe($this->playlist->getMorphClass())
        ->and($notification->notifiable_id)->toBe($this->playlist->id)
        ->and($notification->channel)->toBe('dvr')
        ->and($notification->status)->toBe('danger')
        ->and(str_contains($notification->title, '2'))->toBeTrue();
});

it('dispatches one SendPushNotificationRelay per playlist on crash recovery', function () {
    DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Recording]);
    DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Recording]);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    app(DvrRecorderService::class)->recoverFromCrash();

    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $job) {
        return $job->notifiableType === $this->playlist->getMorphClass()
            && $job->notifiableId === $this->playlist->id
            && str_contains($job->title, '2');
    });

    Bus::assertDispatched(SendPushNotificationRelay::class, 1);
});

it('delivers the aggregated crash notification to every entitled credential transport', function () {
    $playlistAuths = PlaylistAuth::factory()->count(2)->for($this->user)->create([
        'enabled' => true,
    ]);

    foreach ($playlistAuths as $index => $playlistAuth) {
        $playlistAuth->assignTo($this->playlist);
        PushDeviceToken::factory()->create([
            'notifiable_type' => $this->playlist->getMorphClass(),
            'notifiable_id' => $this->playlist->id,
            'playlist_auth_id' => $playlistAuth->id,
            'token' => "crash-device-{$index}",
        ]);
    }

    DvrRecording::factory()
        ->count(2)
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Recording]);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    app(DvrRecorderService::class)->recoverFromCrash();

    $notification = TvNotification::sole();
    $event = null;
    $relayJob = null;

    Event::assertDispatched(TvNotificationEvent::class, function (TvNotificationEvent $dispatched) use (&$event): bool {
        $event = $dispatched;

        return true;
    });
    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $dispatched) use (&$relayJob): bool {
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
        ->and($notification->title)->toContain('2')
        ->and($event->id)->toBe($notification->id)
        ->and($relayJob->notificationUuid)->toBe($notification->id)
        ->and($notificationIds)->toBe([
            'crash-device-0' => $notification->id,
            'crash-device-1' => $notification->id,
        ]);
});

it('maintains cross-playlist isolation on crash recovery', function () {
    $playlist2 = Playlist::factory()->for($this->user)->create();
    $dvrSetting2 = DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($playlist2)
        ->create();
    $channel2 = Channel::factory()
        ->for($playlist2)
        ->for($this->group)
        ->create(['enabled' => true]);

    DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Recording, 'title' => 'Playlist1 Recording']);
    DvrRecording::factory()
        ->for($this->user)
        ->for($dvrSetting2)
        ->for($channel2)
        ->create(['status' => DvrRecordingStatus::Recording, 'title' => 'Playlist2 Recording']);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    app(DvrRecorderService::class)->recoverFromCrash();

    expect(TvNotification::count())->toBe(2);

    $n1 = TvNotification::where('notifiable_id', $this->playlist->id)->first();
    $n2 = TvNotification::where('notifiable_id', $playlist2->id)->first();

    expect($n1)->not->toBeNull()
        ->and($n2)->not->toBeNull()
        ->and($n1->notifiable_id)->not->toBe($n2->notifiable_id);

    Bus::assertDispatched(SendPushNotificationRelay::class, 2);
});

it('keeps colliding numeric IDs separate across every playlist owner type', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create([
        'id' => $this->playlist->id,
    ]);
    $mergedPlaylist = MergedPlaylist::factory()->for($this->user)->create([
        'id' => $this->playlist->id,
    ]);
    $owners = collect([$this->playlist, $customPlaylist, $mergedPlaylist]);

    $owners->each(function (Playlist|CustomPlaylist|MergedPlaylist $owner): void {
        $setting = $owner->is($this->playlist)
            ? $this->dvrSetting
            : DvrSetting::factory()->enabled()->for($this->user)->create(
                DvrSetting::ownerAttributes($owner),
            );

        DvrRecording::factory()
            ->for($this->user)
            ->for($setting)
            ->create([
                'status' => DvrRecordingStatus::Recording,
                'title' => class_basename($owner).' Recording',
            ]);
    });

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    app(DvrRecorderService::class)->recoverFromCrash();

    $expectedRecipients = $owners
        ->map(fn (Playlist|CustomPlaylist|MergedPlaylist $owner): string => $owner->getMorphClass().':'.$owner->id)
        ->sort()
        ->values()
        ->all();
    $actualRecipients = TvNotification::query()
        ->get()
        ->map(fn (TvNotification $notification): string => $notification->notifiable_type.':'.$notification->notifiable_id)
        ->sort()
        ->values()
        ->all();

    expect($actualRecipients)->toBe($expectedRecipients);
    Bus::assertDispatched(SendPushNotificationRelay::class, 3);
});

it('does not create notifications when there are no stale recordings', function () {
    DvrRecording::factory()
        ->for($this->user)
        ->for($this->dvrSetting)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    Event::fake([TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    app(DvrRecorderService::class)->recoverFromCrash();

    expect(TvNotification::count())->toBe(0);
    Bus::assertNotDispatched(SendPushNotificationRelay::class);
});
