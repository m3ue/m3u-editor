<?php

/**
 * Stage 3: Exactly-once requester lifecycle notifications.
 *
 * When a request is approved, rejected, or completed, the requester (identified
 * by playlist_auth_id) must receive exactly one persisted TvNotification on the
 * 'requests' channel and one SendPushNotificationRelay dispatch.
 *
 * Polling, webhook replay, queue retry, duplicate action, and concurrent attempts
 * must NOT mint a second notification.
 *
 * No-push cases: pending, auto-approved, grabbed, downloading, provider failure,
 * admin-only operational failure, manual action, dismissal, unknown webhook,
 * scheduled or rule-created DVR, post-processing progress, deletion, retention.
 */

use App\Events\MediaRequestStatusEvent;
use App\Events\PlaylistCreated;
use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\PushDeviceToken;
use App\Models\TvNotification;
use App\Models\User;
use App\Services\ContentRequestService;
use App\Services\PushRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PlaylistCreated::class, MediaRequestStatusEvent::class, TvNotificationEvent::class]);
    Bus::fake([SendPushNotificationRelay::class]);

    $this->admin = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->playlist = Playlist::factory()->for($this->admin)->create();
    $this->integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->admin->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
    ]);
    $this->auth = PlaylistAuth::factory()->create([
        'user_id' => $this->admin->id,
        'auto_approve_requests' => false,
    ]);
    $this->auth->assignTo($this->playlist);
});

function makePendingRequest(array $overrides = []): MediaRequest
{
    return MediaRequest::create(array_merge([
        'playlist_auth_id' => test()->auth->id,
        'arr_integration_id' => test()->integration->id,
        'title' => 'The Bear',
        'external_id' => '400002',
        'request_type' => 'series',
        'payload' => [
            'tvdbId' => 400002,
            'title' => 'The Bear',
            'qualityProfileId' => 1,
            'rootFolderPath' => '/tv',
        ],
        'status' => 'pending',
        'requested_at' => now(),
    ], $overrides));
}

// -- Approval creates exactly one requester-scoped notification -----------------------

it('approval creates one TvNotification and one relay job scoped to requester', function () {
    $request = makePendingRequest();

    $playlist = $this->auth->getAssignedModel();

    app(ContentRequestService::class)->approveRequest($request);

    expect(TvNotification::count())->toBe(1);

    $notification = TvNotification::first();
    expect($notification->channel)->toBe('requests')
        ->and($notification->playlist_auth_id)->toBe($this->auth->id)
        ->and($notification->notifiable_id)->toBe($playlist->id)
        ->and($notification->status)->toBe('success')
        ->and(str_contains($notification->title, 'Request Approved'))->toBeTrue();

    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $job) {
        return $job->playlistAuthId === test()->auth->id
            && str_contains($job->title, 'Request Approved');
    });
});

// -- Rejection creates exactly one requester-scoped notification ----------------------

it('rejection creates one TvNotification and one relay job scoped to requester', function () {
    $request = makePendingRequest();

    $playlist = $this->auth->getAssignedModel();

    app(ContentRequestService::class)->rejectRequest($request);

    expect(TvNotification::count())->toBe(1);

    $notification = TvNotification::first();
    expect($notification->channel)->toBe('requests')
        ->and($notification->playlist_auth_id)->toBe($this->auth->id)
        ->and($notification->notifiable_id)->toBe($playlist->id)
        ->and($notification->status)->toBe('warning')
        ->and(str_contains($notification->title, 'Request Rejected'))->toBeTrue();

    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $job) {
        return $job->playlistAuthId === test()->auth->id
            && str_contains($job->title, 'Request Rejected');
    });
});

// -- Completion creates exactly one requester-scoped notification ---------------------

it('completion creates one TvNotification on first approved-to-completed transition', function () {
    $request = makePendingRequest(['status' => 'approved']);

    $playlist = $this->auth->getAssignedModel();

    app(ContentRequestService::class)->completeRequest($request);

    expect(TvNotification::count())->toBe(1);

    $notification = TvNotification::first();
    expect($notification->channel)->toBe('requests')
        ->and($notification->playlist_auth_id)->toBe($this->auth->id)
        ->and($notification->notifiable_id)->toBe($playlist->id)
        ->and($notification->status)->toBe('success')
        ->and(str_contains($notification->title, 'Request Completed'))->toBeTrue();

    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $job) {
        return $job->playlistAuthId === test()->auth->id
            && str_contains($job->title, 'Request Completed');
    });
});

// -- Exactly-once: duplicate approval does not mint second notification ----------------

it('duplicate approval does not create a second notification', function () {
    $request = makePendingRequest();

    $playlist = $this->auth->getAssignedModel();
    $service = app(ContentRequestService::class);

    $service->approveRequest($request);
    expect(TvNotification::count())->toBe(1);

    $request->refresh();
    $service->approveRequest($request);
    expect(TvNotification::count())->toBe(1);
});

// -- Exactly-once: duplicate rejection does not mint second notification ----------------

it('duplicate rejection does not create a second notification', function () {
    $request = makePendingRequest();

    $service = app(ContentRequestService::class);

    $service->rejectRequest($request);
    expect(TvNotification::count())->toBe(1);

    $request->refresh();
    $service->rejectRequest($request);
    expect(TvNotification::count())->toBe(1);
});

// -- Exactly-once: duplicate completion does not mint second notification ---------------

it('duplicate completion does not create a second notification', function () {
    $request = makePendingRequest(['status' => 'approved']);

    $service = app(ContentRequestService::class);

    $service->completeRequest($request);
    expect(TvNotification::count())->toBe(1);

    $request->refresh();
    $service->completeRequest($request);
    expect(TvNotification::count())->toBe(1);
});

it('creates exactly one requester notification when stale model instances retry :transition', function (string $transition, string $initialStatus) {
    $request = makePendingRequest(['status' => $initialStatus]);
    $firstAttempt = MediaRequest::query()->findOrFail($request->id);
    $staleRetry = MediaRequest::query()->findOrFail($request->id);
    $service = app(ContentRequestService::class);

    $service->{$transition}($firstAttempt);
    $service->{$transition}($staleRetry);

    expect(TvNotification::count())->toBe(1);
    Event::assertDispatched(TvNotificationEvent::class, 1);
    Bus::assertDispatched(SendPushNotificationRelay::class, 1);
})->with([
    'approval' => ['approveRequest', 'pending'],
    'rejection' => ['rejectRequest', 'pending'],
    'completion' => ['completeRequest', 'approved'],
]);

// -- Cross-transport request identity ------------------------------------------------

it('persists and broadcasts request metadata with the canonical notification UUID', function () {
    $request = makePendingRequest(['title' => 'Severance']);
    $event = null;

    app(ContentRequestService::class)->approveRequest($request);

    $notification = TvNotification::sole();
    Event::assertDispatched(TvNotificationEvent::class, function (TvNotificationEvent $dispatched) use (&$event): bool {
        $event = $dispatched;

        return true;
    });

    $storedMetadata = $notification->metadata ?? $notification->data;
    $eventPayload = method_exists($event, 'broadcastWith')
        ? $event->broadcastWith()
        : get_object_vars($event);
    $eventMetadata = $eventPayload['metadata'] ?? $eventPayload['data'] ?? null;

    expect($notification->body)->toContain($request->title)
        ->and(data_get($storedMetadata, 'request_id'))->toBe($request->id)
        ->and(data_get($storedMetadata, 'request_title'))->toBe($request->title)
        ->and(data_get($storedMetadata, 'request_status'))->toBe('approved')
        ->and($event->id)->toBe($notification->id)
        ->and($event->body)->toContain($request->title)
        ->and(data_get($eventMetadata, 'request_id'))->toBe($request->id)
        ->and(data_get($eventMetadata, 'request_title'))->toBe($request->title)
        ->and(data_get($eventMetadata, 'request_status'))->toBe('approved');
});

it('relays request metadata with the same canonical notification UUID', function () {
    $request = makePendingRequest(['title' => 'Slow Horses']);
    PushDeviceToken::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth->id,
        'token' => 'requester-device',
        'platform' => 'android',
    ]);
    $job = null;

    app(ContentRequestService::class)->approveRequest($request);

    $notification = TvNotification::sole();
    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $dispatched) use (&$job): bool {
        $job = $dispatched;

        return true;
    });

    expect($job->notificationUuid)->toBe($notification->id);

    $relay = Mockery::mock(PushRelayService::class);
    $relay->shouldReceive('isEnabled')->once()->andReturnTrue();
    $relay->shouldReceive('send')
        ->once()
        ->with(
            'requester-device',
            'android',
            Mockery::type('string'),
            Mockery::on(fn (?string $body): bool => str_contains($body ?? '', $request->title)),
            Mockery::on(fn (?array $data): bool => data_get($data, 'notification_id') === $notification->id
                && (string) data_get($data, 'request_id') === (string) $request->id
                && data_get($data, 'request_title') === $request->title
                && data_get($data, 'request_status') === 'approved'),
        );

    $job->handle($relay);
});

// -- No notification for pending status ------------------------------------------------

it('pending request does not create a TvNotification', function () {
    $request = makePendingRequest();

    expect($request->status)->toBe('pending');
    expect(TvNotification::count())->toBe(0);
    Bus::assertNotDispatched(SendPushNotificationRelay::class);
});

// -- Cross-playlist isolation ---------------------------------------------------------

it('notification is scoped to requester not to other auth on same playlist', function () {
    $auth2 = PlaylistAuth::factory()->create([
        'user_id' => $this->admin->id,
        'auto_approve_requests' => false,
    ]);
    $auth2->assignTo($this->playlist);

    $request = makePendingRequest(['playlist_auth_id' => $this->auth->id]);

    $playlist = $this->auth->getAssignedModel();

    app(ContentRequestService::class)->approveRequest($request);

    expect(TvNotification::count())->toBe(1);
    $notification = TvNotification::first();
    expect($notification->playlist_auth_id)->toBe($this->auth->id)
        ->and($notification->playlist_auth_id)->not->toBe($auth2->id);
});

it('keeps an alias-attached requester isolated across every lifecycle transport', function () {
    $this->withoutMiddleware(ThrottleRequestsWithRedis::class);

    $alias = PlaylistAlias::create([
        'name' => 'Alias Requester',
        'uuid' => fake()->uuid(),
        'user_id' => $this->admin->id,
        'playlist_id' => $this->playlist->id,
    ]);
    $aliasAuth = PlaylistAuth::factory()->create([
        'user_id' => $this->admin->id,
        'username' => 'alias-requester',
        'password' => 'alias-secret',
        'enabled' => true,
        'auto_approve_requests' => false,
    ]);
    $aliasAuth->assignTo($alias);

    $this->auth->update([
        'username' => 'direct-requester',
        'password' => 'direct-secret',
        'enabled' => true,
    ]);

    $directCredentials = [
        'username' => $this->auth->username,
        'password' => $this->auth->password,
    ];
    $aliasCredentials = [
        'username' => $aliasAuth->username,
        'password' => $aliasAuth->password,
    ];

    $this->postJson(route('tv.push.subscribe', $directCredentials), [
        'token' => 'direct-device',
        'platform' => 'android',
    ])->assertOk();
    $this->postJson(route('tv.push.subscribe', $aliasCredentials), [
        'token' => 'alias-device',
        'platform' => 'ios',
    ])->assertOk();

    $directChannel = $this->getJson(route('tv.notifications', $directCredentials))
        ->assertOk()
        ->json('reverb.channel');
    $aliasChannel = $this->getJson(route('tv.notifications', $aliasCredentials))
        ->assertOk()
        ->json('reverb.channel');

    expect($directChannel)->toBe("private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$this->auth->id}")
        ->and($aliasChannel)->toBe("private-tv.{$alias->getMorphClass()}.{$alias->uuid}.{$aliasAuth->id}")
        ->and($aliasChannel)->not->toBe($directChannel);

    $this->postJson(route('tv.broadcasting.auth', $aliasCredentials), [
        'socket_id' => '123456.78910',
        'channel_name' => $aliasChannel,
    ])->assertOk();
    $this->postJson(route('tv.broadcasting.auth', $directCredentials), [
        'socket_id' => '123456.78910',
        'channel_name' => $aliasChannel,
    ])->assertForbidden();

    $request = makePendingRequest([
        'playlist_auth_id' => $aliasAuth->id,
        'title' => 'Alias Only Request',
        'external_id' => '999001',
    ]);
    $service = app(ContentRequestService::class);

    expect($service->status($this->auth, $request->id))->toBeNull()
        ->and($service->history($this->auth, 1, 10))->toBe([
            'requests' => [],
            'total' => 0,
        ]);

    $service->approveRequest($request);

    $request->refresh();
    $notification = TvNotification::sole();
    $statusEvent = null;
    $notificationEvent = null;
    $relayJob = null;

    Event::assertDispatched(MediaRequestStatusEvent::class, function (MediaRequestStatusEvent $event) use ($alias, $aliasAuth, $aliasChannel, $request, &$statusEvent): bool {
        $statusEvent = $event;

        return $event->notifiableType === $alias->getMorphClass()
            && $event->notifiableUuid === $alias->uuid
            && $event->playlistAuthId === $aliasAuth->id
            && $event->id === $request->id
            && $event->title === $request->title
            && $event->status === 'approved'
            && $event->broadcastOn()[0]->name === $aliasChannel;
    });
    Event::assertDispatched(TvNotificationEvent::class, function (TvNotificationEvent $event) use ($alias, $aliasAuth, $aliasChannel, $request, &$notificationEvent): bool {
        $notificationEvent = $event;

        return $event->notifiableType === $alias->getMorphClass()
            && $event->notifiableUuid === $alias->uuid
            && $event->playlistAuthId === $aliasAuth->id
            && $event->body === $request->title
            && data_get($event->metadata, 'request_id') === $request->id
            && data_get($event->metadata, 'request_title') === $request->title
            && data_get($event->metadata, 'request_status') === 'approved'
            && $event->broadcastOn()[0]->name === $aliasChannel;
    });
    Bus::assertDispatched(SendPushNotificationRelay::class, function (SendPushNotificationRelay $job) use ($alias, $aliasAuth, $notification, $request, &$relayJob): bool {
        $relayJob = $job;

        return $job->notifiableType === $alias->getMorphClass()
            && $job->notifiableId === $alias->id
            && $job->playlistAuthId === $aliasAuth->id
            && $job->notificationUuid === $notification->id
            && $job->body === $request->title
            && data_get($job->data, 'request_id') === $request->id
            && data_get($job->data, 'request_title') === $request->title
            && data_get($job->data, 'request_status') === 'approved';
    });

    expect($request->status)->toBe('approved')
        ->and($notification->notifiable_type)->toBe($alias->getMorphClass())
        ->and($notification->notifiable_id)->toBe($alias->id)
        ->and($notification->playlist_auth_id)->toBe($aliasAuth->id)
        ->and($notification->body)->toBe($request->title)
        ->and(data_get($notification->metadata, 'request_id'))->toBe($request->id)
        ->and(data_get($notification->metadata, 'request_title'))->toBe($request->title)
        ->and(data_get($notification->metadata, 'request_status'))->toBe('approved')
        ->and($notificationEvent->id)->toBe($notification->id)
        ->and($statusEvent->broadcastOn()[0]->name)->not->toBe($directChannel)
        ->and($notificationEvent->broadcastOn()[0]->name)->not->toBe($directChannel);

    $this->getJson(route('tv.notifications', $directCredentials))
        ->assertOk()
        ->assertJsonCount(0, 'notifications')
        ->assertJsonMissing(['title' => $request->title])
        ->assertJsonMissing(['request_id' => $request->id])
        ->assertJsonMissing(['request_status' => 'approved']);
    $this->getJson(route('tv.notifications', $aliasCredentials))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $notification->id)
        ->assertJsonPath('notifications.0.title', 'Request Approved')
        ->assertJsonPath('notifications.0.body', $request->title)
        ->assertJsonPath('notifications.0.metadata.request_id', $request->id)
        ->assertJsonPath('notifications.0.metadata.request_status', 'approved');

    $relay = Mockery::mock(PushRelayService::class);
    $relay->shouldReceive('isEnabled')->once()->andReturnTrue();
    $relay->shouldReceive('send')
        ->once()
        ->with(
            'alias-device',
            'ios',
            Mockery::type('string'),
            $request->title,
            Mockery::on(fn (?array $data): bool => data_get($data, 'notification_id') === $notification->id
                && data_get($data, 'request_id') === $request->id
                && data_get($data, 'request_title') === $request->title
                && data_get($data, 'request_status') === 'approved'),
        );
    $relayJob->handle($relay);

    $directToken = PushDeviceToken::where('token', 'direct-device')->sole();
    $aliasToken = PushDeviceToken::where('token', 'alias-device')->sole();
    expect($directToken->notifiable_type)->toBe($this->playlist->getMorphClass())
        ->and($directToken->notifiable_id)->toBe($this->playlist->id)
        ->and($directToken->playlist_auth_id)->toBe($this->auth->id)
        ->and($aliasToken->notifiable_type)->toBe($alias->getMorphClass())
        ->and($aliasToken->notifiable_id)->toBe($alias->id)
        ->and($aliasToken->playlist_auth_id)->toBe($aliasAuth->id);

    $this->deleteJson(route('tv.push.unsubscribe', $directCredentials), [
        'token' => $aliasToken->token,
    ])->assertOk();
    expect($aliasToken->fresh())->not->toBeNull();

    $this->deleteJson(route('tv.push.unsubscribe', $aliasCredentials), [
        'token' => $aliasToken->token,
    ])->assertOk();
    expect($aliasToken->fresh())->toBeNull()
        ->and($directToken->fresh())->not->toBeNull();
});
