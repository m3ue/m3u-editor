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

use App\Events\PlaylistCreated;
use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\TvNotification;
use App\Models\User;
use App\Services\ContentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PlaylistCreated::class, TvNotificationEvent::class]);
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
