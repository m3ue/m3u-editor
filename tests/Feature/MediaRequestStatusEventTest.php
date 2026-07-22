<?php

/**
 * Regression coverage for the MediaRequestStatusEvent push, which lets the TV
 * app update the requests screen live over Reverb instead of polling
 * request_status/request_history. See MediaRequest::broadcastStatus() and
 * its call sites in ArrQueueMonitor::approveRequest/rejectRequest and
 * ContentRequestService::status().
 */

use App\Events\MediaRequestStatusEvent;
use App\Livewire\ArrQueueMonitor;
use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->adminUser = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->adminUser);
    $this->playlist = Playlist::factory()->for($this->adminUser)->create();
    $this->integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->adminUser->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
    ]);
    $this->auth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
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
    Event::fake([MediaRequestStatusEvent::class]);

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
    Event::fake([MediaRequestStatusEvent::class]);

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

it('broadcasts on the private channel scoped to the playlist uuid', function () {
    $mediaRequest = makeMediaRequest();

    $event = MediaRequestStatusEvent::fromRequest($mediaRequest->fresh());
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe(
        "private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}"
    );
    expect($event->broadcastAs())->toBe('request.status');
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
