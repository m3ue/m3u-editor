<?php

/**
 * Regression coverage for the MediaRequestStatusEvent push, which lets the TV
 * app update the requests screen live over Reverb instead of polling
 * request_status/request_history. See MediaRequest::broadcastStatus() and
 * its call sites in ArrQueueMonitor::approveRequest/rejectRequest and
 * ContentRequestService::status().
 */

use App\Events\MediaRequestStatusEvent;
use App\Events\PlaylistCreated;
use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Livewire\ArrQueueMonitor;
use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\ContentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake([PlaylistCreated::class]);
    Bus::fake([SendPushNotificationRelay::class]);
    $this->adminUser = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->adminUser);
    $this->playlist = Playlist::factory()->for($this->adminUser)->create();
    $this->integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->adminUser->id,
        'enabled' => true,
        'guest_enabled' => true,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
    ]);
    $this->auth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'username' => 'requester-one',
        'password' => 'secret-one',
        'enabled' => true,
        'auto_approve_requests' => false,
    ]);
    $this->auth->assignTo($this->playlist);
});

function makeMediaRequest(array $overrides = []): MediaRequest
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
            'titleSlug' => 'the-bear',
            'images' => [],
            'qualityProfileId' => 1,
            'rootFolderPath' => '/tv',
            'searchForMissingEpisodes' => true,
        ],
        'status' => 'pending',
        'requested_at' => now(),
    ], $overrides));
}

it('broadcasts when a request is approved', function () {
    $mediaRequest = makeMediaRequest();

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
        '*/api/v3/series*' => Http::response(['id' => 1, 'title' => 'The Bear'], 201),
    ]);
    Event::fake([MediaRequestStatusEvent::class, TvNotificationEvent::class]);

    Livewire::test(ArrQueueMonitor::class)->call('approveRequest', $mediaRequest->id);

    Event::assertDispatched(
        MediaRequestStatusEvent::class,
        function (MediaRequestStatusEvent $event) use ($mediaRequest) {
            return $event->id === $mediaRequest->id
                && $event->status === 'approved'
                && $event->notifiableType === $this->playlist->getMorphClass()
                && $event->notifiableUuid === $this->playlist->uuid;
        }
    );
});

it('broadcasts when a request is rejected', function () {
    $mediaRequest = makeMediaRequest(['title' => 'Bad Show', 'request_type' => 'movie', 'external_id' => '111']);

    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);
    Event::fake([MediaRequestStatusEvent::class, TvNotificationEvent::class]);

    Livewire::test(ArrQueueMonitor::class)->call('rejectRequest', $mediaRequest->id);

    Event::assertDispatched(
        MediaRequestStatusEvent::class,
        fn (MediaRequestStatusEvent $event) => $event->id === $mediaRequest->id
            && $event->status === 'rejected'
    );
});

it('does not broadcast when the owning playlist auth has no assigned playlist', function () {
    $orphanAuth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'auto_approve_requests' => false,
    ]);
    $mediaRequest = makeMediaRequest(['playlist_auth_id' => $orphanAuth->id]);

    $event = MediaRequestStatusEvent::fromRequest($mediaRequest->fresh());

    expect($event)->toBeNull();
});

it('broadcasts requests only on the controller-announced channel for their credential', function () {
    $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
    $otherAuth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'username' => 'requester-two',
        'password' => 'secret-two',
        'enabled' => true,
        'auto_approve_requests' => false,
    ]);
    $otherAuth->assignTo($this->playlist);

    $firstChannel = $this->getJson(route('tv.notifications', [
        'username' => $this->auth->username,
        'password' => $this->auth->password,
    ]))->assertOk()->json('reverb.channel');
    $secondChannel = $this->getJson(route('tv.notifications', [
        'username' => $otherAuth->username,
        'password' => $otherAuth->password,
    ]))->assertOk()->json('reverb.channel');

    $firstEvent = MediaRequestStatusEvent::fromRequest(makeMediaRequest()->fresh());
    $secondEvent = MediaRequestStatusEvent::fromRequest(makeMediaRequest([
        'playlist_auth_id' => $otherAuth->id,
    ])->fresh());

    expect($firstEvent->broadcastOn())->toHaveCount(1)
        ->and($firstEvent->broadcastOn()[0]->name)->toBe($firstChannel)
        ->and($secondEvent->broadcastOn())->toHaveCount(1)
        ->and($secondEvent->broadcastOn()[0]->name)->toBe($secondChannel)
        ->and($firstChannel)->not->toBe($secondChannel)
        ->and($firstEvent->broadcastAs())->toBe('request.status');
});

it('broadcasts exactly one requester-scoped status event when stale models retry :transition', function (string $transition, string $initialStatus, string $expectedStatus) {
    $mediaRequest = makeMediaRequest(['status' => $initialStatus]);
    $firstAttempt = MediaRequest::query()->findOrFail($mediaRequest->id);
    $staleRetry = MediaRequest::query()->findOrFail($mediaRequest->id);
    Event::fake([MediaRequestStatusEvent::class, TvNotificationEvent::class]);
    $service = app(ContentRequestService::class);

    $service->{$transition}($firstAttempt);
    $service->{$transition}($staleRetry);

    Event::assertDispatched(MediaRequestStatusEvent::class, 1);
    Event::assertDispatched(MediaRequestStatusEvent::class, function (MediaRequestStatusEvent $event) use ($mediaRequest, $expectedStatus): bool {
        $channels = $event->broadcastOn();

        return $event->id === $mediaRequest->id
            && $event->status === $expectedStatus
            && count($channels) === 1
            && $channels[0]->name === "private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$this->auth->id}";
    });
})->with([
    'approval' => ['approveRequest', 'pending', 'approved'],
    'rejection' => ['rejectRequest', 'pending', 'rejected'],
    'completion' => ['completeRequest', 'approved', 'completed'],
]);

it('broadcasts completion only once when status polling repeats', function () {
    $mediaRequest = makeMediaRequest(['status' => 'approved']);
    ArrQueueEvent::create([
        'arr_integration_id' => $this->integration->id,
        'user_id' => $this->adminUser->id,
        'external_id' => $mediaRequest->external_id,
        'title' => $mediaRequest->title,
        'event_type' => 'Download',
        'status' => 'imported',
        'quality' => 'WEB-1080p',
        'size' => 100,
        'progress' => 100,
        'last_event_at' => now(),
    ]);
    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []])]);
    Event::fake([MediaRequestStatusEvent::class, TvNotificationEvent::class]);
    $service = app(ContentRequestService::class);

    $firstPoll = $service->status($this->auth, $mediaRequest->id);
    $secondPoll = $service->status($this->auth, $mediaRequest->id);

    expect($firstPoll['status'])->toBe('completed')
        ->and($secondPoll['status'])->toBe('completed');
    Event::assertDispatched(MediaRequestStatusEvent::class, 1);
});

it('serializes the wire payload with snake_case keys matching formatRequest() on the server / MediaRequestSummary on the client', function () {
    $mediaRequest = makeMediaRequest([
        'status' => 'approved',
        'reviewed_at' => now(),
    ]);

    $event = MediaRequestStatusEvent::fromRequest($mediaRequest->fresh());

    expect($event->broadcastWith())->toBe([
        'notifiableType' => $this->playlist->getMorphClass(),
        'notifiableUuid' => $this->playlist->uuid,
        'id' => $mediaRequest->id,
        'status' => 'approved',
        'type' => 'series',
        'external_id' => '400002',
        'title' => 'The Bear',
        'integration_id' => $this->integration->id,
        'integration_name' => $this->integration->name,
        'season_number' => null,
        'episode_number' => null,
        'requested_at' => $mediaRequest->fresh()->requested_at->toIso8601String(),
        'can_dismiss' => false,
    ]);
});
