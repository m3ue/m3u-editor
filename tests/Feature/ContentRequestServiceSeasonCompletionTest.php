<?php

/**
 * A Sonarr "Download" webhook only carries the series-level external ID, so
 * importing an episode from ANY season of a series used to complete a
 * season-specific request outright (see #1668). status() must now verify
 * that every monitored season actually has episode files before completing.
 */

use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\ContentRequestService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = ArrIntegration::factory()->sonarr()->guestEnabled()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
    ]);
    $this->auth = PlaylistAuth::factory()->create(['user_id' => $this->user->id]);
    $this->auth->assignTo($this->playlist);

    // No active queue entry: status() falls through to the ArrQueueEvent history.
    Http::fake([
        '*/queue*' => Http::response(['records' => []], 200),
    ]);
});

/**
 * Season 2 is requested (monitored); season 1 is not.
 */
function createSeasonScopedSeriesRequest(User $user, PlaylistAuth $auth, ArrIntegration $integration): MediaRequest
{
    return MediaRequest::create([
        'playlist_auth_id' => $auth->id,
        'arr_integration_id' => $integration->id,
        'title' => 'Synthetic Series',
        'external_id' => '371980',
        'request_type' => 'series',
        'status' => 'approved',
        'requested_at' => now()->subHour(),
        'payload' => [
            'tvdbId' => 371980,
            'seasons' => [
                ['seasonNumber' => 1, 'monitored' => false],
                ['seasonNumber' => 2, 'monitored' => true],
            ],
        ],
    ]);
}

it('does not complete a season-specific request when an unrequested season is imported', function () {
    $mediaRequest = createSeasonScopedSeriesRequest($this->user, $this->auth, $this->integration);

    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $this->integration->id,
        'user_id' => $this->user->id,
        'external_id' => '371980',
        'title' => 'Synthetic Series',
        'event_type' => 'Download',
        'status' => 'imported',
        'progress' => 100,
        'last_event_at' => now(),
    ]);

    Http::fake([
        '*/queue*' => Http::response(['records' => []], 200),
        '*/series/lookup*' => Http::response([['id' => 55]], 200),
        '*/episode*' => Http::response([
            ['seasonNumber' => 1, 'episodeNumber' => 1, 'hasFile' => true],
        ], 200),
    ]);

    $result = app(ContentRequestService::class)->status($this->auth, $mediaRequest->id);

    expect($result['status'])->not->toBe('completed')
        ->and($mediaRequest->fresh()->status)->toBe('approved');
});

it('completes the request once every monitored season actually has episode files', function () {
    $mediaRequest = createSeasonScopedSeriesRequest($this->user, $this->auth, $this->integration);

    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $this->integration->id,
        'user_id' => $this->user->id,
        'external_id' => '371980',
        'title' => 'Synthetic Series',
        'event_type' => 'Download',
        'status' => 'imported',
        'progress' => 100,
        'last_event_at' => now(),
    ]);

    Http::fake([
        '*/queue*' => Http::response(['records' => []], 200),
        '*/series/lookup*' => Http::response([['id' => 55]], 200),
        '*/episode*' => Http::response([
            ['seasonNumber' => 2, 'episodeNumber' => 1, 'hasFile' => true],
        ], 200),
    ]);

    $result = app(ContentRequestService::class)->status($this->auth, $mediaRequest->id);

    expect($result['status'])->toBe('completed')
        ->and($mediaRequest->fresh()->status)->toBe('completed');
});

it('still completes unscoped (movie/episode) requests as before', function () {
    $mediaRequest = MediaRequest::create([
        'playlist_auth_id' => $this->auth->id,
        'arr_integration_id' => $this->integration->id,
        'title' => 'Synthetic Series',
        'external_id' => '371980',
        'request_type' => 'episode',
        'status' => 'approved',
        'requested_at' => now()->subHour(),
        'payload' => ['tvdbId' => 371980],
    ]);

    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $this->integration->id,
        'user_id' => $this->user->id,
        'external_id' => '371980',
        'title' => 'Synthetic Series',
        'event_type' => 'Download',
        'status' => 'imported',
        'progress' => 100,
        'last_event_at' => now(),
    ]);

    $result = app(ContentRequestService::class)->status($this->auth, $mediaRequest->id);

    expect($result['status'])->toBe('completed')
        ->and($mediaRequest->fresh()->status)->toBe('completed');
});
