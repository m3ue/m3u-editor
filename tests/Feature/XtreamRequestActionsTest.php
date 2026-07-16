<?php

use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\PlaylistRequestSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'request_enabled' => true,
        'auto_approve_requests' => false,
        'username' => 'request-user',
        'password' => 'request-pass',
    ]);
    $this->auth->assignTo($this->playlist);

    foreach (['request_search', 'request_submit', 'request_history', 'request_status', 'request_dismiss'] as $action) {
        RateLimiter::clear("xtream-request:{$action}:{$this->auth->id}");
    }

    PlaylistRequestSetting::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
    ]);

    $this->radarr = ArrIntegration::factory()->for($this->user)->radarr()->guestEnabled()->create([
        'url' => 'http://radarr.test',
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies',
    ]);
    $this->sonarr = ArrIntegration::factory()->for($this->user)->sonarr()->guestEnabled()->create([
        'url' => 'http://sonarr.test',
        'quality_profile_id' => 2,
        'root_folder_path' => '/tv',
    ]);
});

function requestActionUrl(string $action, array $parameters = []): string
{
    return '/player_api.php?'.http_build_query(array_merge([
        'username' => 'request-user',
        'password' => 'request-pass',
        'action' => $action,
    ], $parameters));
}

it('advertises the versioned request action contract without integration credentials', function () {
    $response = $this->getJson(requestActionUrl('panel'));

    $response->assertOk()
        ->assertJsonPath('m3u_editor.requests.api_version', 1)
        ->assertJsonPath('m3u_editor.requests.actions.search', 'request_search')
        ->assertJsonPath('m3u_editor.requests.actions.submit', 'request_submit')
        ->assertJsonPath('m3u_editor.requests.actions.history', 'request_history')
        ->assertJsonPath('m3u_editor.requests.actions.status', 'request_status')
        ->assertJsonPath('m3u_editor.requests.actions.dismiss', 'request_dismiss')
        ->assertJsonPath('m3u_editor.requests.content_types', ['movie', 'series'])
        ->assertJsonPath('m3u_editor.requests.approval_behavior', 'pending_approval');

    expect($response->json('m3u_editor.requests'))->not->toHaveKeys([
        'username',
        'password',
        'api_key',
        'integration_id',
    ])->and($response->json('m3u_editor.requests.error_codes'))->toContain(
        'invalid_request',
        'request_access_denied',
        'providers_unavailable',
        'already_requested',
        'request_not_found',
        'request_not_dismissible',
        'rate_limited',
    );
});

it('advertises auto approval only for credentials configured to auto approve', function () {
    $this->auth->update(['auto_approve_requests' => true]);

    $this->getJson(requestActionUrl('panel'))
        ->assertOk()
        ->assertJsonPath('m3u_editor.requests.approval_behavior', 'auto_approval');
});

it('denies request actions when the capability gate is disabled', function () {
    $this->auth->update(['request_enabled' => false]);

    $this->getJson(requestActionUrl('panel'))
        ->assertOk()
        ->assertJsonMissingPath('m3u_editor.requests');

    $this->getJson(requestActionUrl('request_search', ['query' => 'Alien']))
        ->assertForbidden()
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('error.code', 'request_access_denied');

    foreach (range(1, 6) as $attempt) {
        $this->postJson(requestActionUrl('request_submit'), [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'request_access_denied');
    }

    Http::assertNothingSent();
});

it('does not authenticate disabled or expired playlist auth credentials', function (array $attributes) {
    $this->auth->update($attributes);

    $this->getJson(requestActionUrl('panel'))
        ->assertUnauthorized()
        ->assertJsonMissingPath('m3u_editor.requests');

    $this->getJson(requestActionUrl('request_history'))
        ->assertUnauthorized()
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('error.code', 'authentication_failed')
        ->assertJsonMissingPath('data');
})->with([
    'disabled' => [['enabled' => false]],
    'expired' => [['expires_at' => now()->subMinute()]],
]);

it('does not advertise or execute request actions through alias credentials', function () {
    $alias = PlaylistAlias::create([
        'name' => 'Request Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'username' => 'alias-user',
        'password' => 'alias-pass',
    ]);
    $url = '/player_api.php?'.http_build_query([
        'username' => $alias->username,
        'password' => $alias->password,
        'action' => 'panel',
    ]);

    $this->getJson($url)
        ->assertOk()
        ->assertJsonMissingPath('m3u_editor.requests');

    $this->getJson(str_replace('action=panel', 'action=request_search&query=Alien', $url))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'request_access_denied');
});

it('does not expose obsolete request action names', function (string $action) {
    $this->getJson(requestActionUrl($action, ['term' => 'Alien']))
        ->assertBadRequest()
        ->assertJsonPath('error', 'Invalid action parameter');

    Http::assertNothingSent();
})->with([
    'search_requests',
    'submit_movie_request',
    'submit_series_request',
    'get_request_queue',
]);

it('rate limits request submission without limiting unrelated xtream actions', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson(requestActionUrl('request_submit'), [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_request');
    }

    $this->postJson(requestActionUrl('request_submit'), [])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('error.code', 'rate_limited');

    $this->getJson(requestActionUrl('panel'))->assertOk();
});

it('searches only enabled guest integrations and returns paginated deduplicated results', function () {
    ArrIntegration::factory()->for($this->user)->radarr()->create([
        'enabled' => true,
        'guest_enabled' => false,
        'url' => 'http://private-radarr.test',
    ]);

    Http::fake([
        'http://radarr.test/api/v3/movie/lookup*' => Http::response([[
            'tmdbId' => 348,
            'title' => 'Alien',
            'titleSlug' => 'alien',
            'year' => 1979,
            'overview' => 'In space no one can hear you scream.',
            'remotePoster' => 'https://images.test/alien.jpg',
            'images' => [],
        ]]),
        'http://sonarr.test/api/v3/series/lookup*' => Http::response([]),
    ]);

    $response = $this->getJson(requestActionUrl('request_search', [
        'query' => 'Alien',
        'type' => 'movie',
        'page' => 1,
        'per_page' => 1,
    ]));

    $response->assertOk()
        ->assertJsonPath('api_version', 1)
        ->assertJsonCount(1, 'data.results')
        ->assertJsonPath('data.results.0.type', 'movie')
        ->assertJsonPath('data.results.0.external_id', '348')
        ->assertJsonPath('data.results.0.integration_id', $this->radarr->id)
        ->assertJsonPath('meta.pagination.current_page', 1)
        ->assertJsonPath('meta.pagination.per_page', 1)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('meta.partial', false)
        ->assertJsonMissingPath('data.results.0.api_key')
        ->assertJsonMissingPath('data.results.0.root_folder_path');

    Http::assertSentCount(1);
});

it('returns an empty successful search result when providers return no matches', function () {
    Http::fake([
        'http://radarr.test/api/v3/movie/lookup*' => Http::response([]),
        'http://sonarr.test/api/v3/series/lookup*' => Http::response([]),
    ]);

    $this->getJson(requestActionUrl('request_search', ['query' => 'Unknown']))
        ->assertOk()
        ->assertJsonPath('data.results', [])
        ->assertJsonPath('meta.pagination.total', 0)
        ->assertJsonPath('meta.partial', false);
});

it('returns safe partial search results when one provider is unavailable', function () {
    Http::fake([
        'http://radarr.test/api/v3/movie/lookup*' => Http::response([[
            'tmdbId' => 348,
            'title' => 'Alien',
        ]]),
        'http://sonarr.test/api/v3/series/lookup*' => Http::response([
            'internal' => 'provider secret failure',
        ], 503),
    ]);

    $response = $this->getJson(requestActionUrl('request_search', ['query' => 'Alien']));

    $response->assertOk()
        ->assertJsonCount(1, 'data.results')
        ->assertJsonPath('meta.partial', true)
        ->assertJsonPath('meta.unavailable_providers', 1)
        ->assertDontSee('provider secret failure');
});

it('returns a stable error when every searched provider is unavailable', function () {
    Http::fake([
        '*' => Http::response([
            'internal' => 'private provider failure',
        ], 503),
    ]);

    $this->getJson(requestActionUrl('request_search', ['query' => 'Alien']))
        ->assertServiceUnavailable()
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('error.code', 'providers_unavailable')
        ->assertJsonMissingPath('error.exception')
        ->assertDontSee('private provider failure');
});

it('creates pending movie and series requests from canonical arr metadata', function (string $type, ArrIntegration $integration, array $lookupItem) {
    Http::fake([
        '*' => Http::response([$lookupItem]),
    ]);

    $externalId = $type === 'movie' ? $lookupItem['tmdbId'] : $lookupItem['tvdbId'];

    $response = $this->postJson(requestActionUrl('request_submit'), [
        'type' => $type,
        'integration_id' => $integration->id,
        'external_id' => $externalId,
        'seasons' => $type === 'series' ? [1] : null,
    ]);

    $response->assertCreated()
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.request.type', $type)
        ->assertJsonPath('data.request.external_id', (string) $externalId);

    $mediaRequest = MediaRequest::sole();
    expect($mediaRequest->playlist_auth_id)->toBe($this->auth->id)
        ->and($mediaRequest->arr_integration_id)->toBe($integration->id)
        ->and($mediaRequest->title)->toBe($lookupItem['title'])
        ->and($mediaRequest->request_type)->toBe($type)
        ->and($mediaRequest->payload)->not->toHaveKeys(['api_key', 'url']);

    if ($type === 'series') {
        expect($mediaRequest->payload['seasons'])->toHaveCount(2)
            ->and($mediaRequest->payload['seasons'][0]['seasonNumber'])->toBe(1)
            ->and($mediaRequest->payload['seasons'][0]['monitored'])->toBeTrue()
            ->and($mediaRequest->payload['seasons'][1]['seasonNumber'])->toBe(2)
            ->and($mediaRequest->payload['seasons'][1]['monitored'])->toBeFalse();
    }
})->with([
    'movie' => fn () => ['movie', $this->radarr, [
        'tmdbId' => 348,
        'title' => 'Alien',
        'titleSlug' => 'alien',
        'images' => [],
    ]],
    'series' => fn () => ['series', $this->sonarr, [
        'tvdbId' => 81189,
        'title' => 'Breaking Bad',
        'titleSlug' => 'breaking-bad',
        'images' => [],
        'seasons' => [
            ['seasonNumber' => 1],
            ['seasonNumber' => 2],
        ],
    ]],
]);

it('submits directly to arr when the playlist auth auto approves requests', function () {
    $this->auth->update(['auto_approve_requests' => true]);

    Http::fake(function (ClientRequest $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && $path === '/api/v3/movie') {
            return Http::response([]);
        }

        if ($request->method() === 'GET' && $path === '/api/v3/movie/lookup') {
            return Http::response([[
                'tmdbId' => 348,
                'title' => 'Alien',
                'titleSlug' => 'alien',
                'images' => [],
            ]]);
        }

        return Http::response(['id' => 91, 'title' => 'Alien'], 201);
    });

    $this->postJson(requestActionUrl('request_submit'), [
        'type' => 'movie',
        'integration_id' => $this->radarr->id,
        'external_id' => 348,
    ])->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.request.status', 'approved');

    expect(MediaRequest::count())->toBe(1)
        ->and(MediaRequest::sole()->playlist_auth_id)->toBe($this->auth->id)
        ->and(MediaRequest::sole()->status)->toBe('approved');
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/api/v3/movie');
});

it('rejects a duplicate request without contacting arr', function () {
    MediaRequest::create([
        'playlist_auth_id' => $this->auth->id,
        'arr_integration_id' => $this->radarr->id,
        'title' => 'Alien',
        'external_id' => '348',
        'request_type' => 'movie',
        'payload' => ['tmdbId' => 348],
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    $this->postJson(requestActionUrl('request_submit'), [
        'type' => 'movie',
        'integration_id' => $this->radarr->id,
        'external_id' => 348,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'already_requested');

    Http::assertNothingSent();
});

it('rejects content already available in arr', function () {
    Http::fake([
        'http://radarr.test/api/v3/movie*' => Http::response([[
            'id' => 91,
            'tmdbId' => 348,
            'title' => 'Alien',
        ]]),
    ]);

    $this->postJson(requestActionUrl('request_submit'), [
        'type' => 'movie',
        'integration_id' => $this->radarr->id,
        'external_id' => 348,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'already_available');

    expect(MediaRequest::count())->toBe(0);
});

it('rejects malformed and unauthorized integration submissions with stable errors', function () {
    $otherIntegration = ArrIntegration::factory()->for(User::factory()->create())->radarr()->guestEnabled()->create();

    $this->postJson(requestActionUrl('request_submit'), [
        'type' => 'movie',
        'integration_id' => $otherIntegration->id,
        'external_id' => 348,
    ])->assertUnprocessable()
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('error.code', 'invalid_integration');

    $this->postJson(requestActionUrl('request_submit'), [
        'type' => 'series',
        'integration_id' => $this->sonarr->id,
        'external_id' => 81189,
        'seasons' => [],
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_request')
        ->assertJsonPath('error.fields.0', 'seasons');

    Http::assertNothingSent();
});

it('returns a safe provider error when request submission cannot reach arr', function () {
    Http::fake([
        '*' => Http::response([
            'internal' => 'private provider exception',
        ], 503),
    ]);

    $this->postJson(requestActionUrl('request_submit'), [
        'type' => 'movie',
        'integration_id' => $this->radarr->id,
        'external_id' => 348,
    ])->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'provider_unavailable')
        ->assertJsonMissingPath('error.exception')
        ->assertDontSee('private provider exception');
});

it('returns paginated request history only for the authenticated playlist auth', function () {
    $otherAuth = PlaylistAuth::factory()->for($this->user)->create();

    foreach ([[$this->auth, 'Mine'], [$otherAuth, 'Not Mine']] as [$auth, $title]) {
        MediaRequest::create([
            'playlist_auth_id' => $auth->id,
            'arr_integration_id' => $this->radarr->id,
            'title' => $title,
            'external_id' => (string) fake()->unique()->numberBetween(100, 999),
            'request_type' => 'movie',
            'payload' => [],
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    $this->getJson(requestActionUrl('request_history', ['page' => 1, 'per_page' => 10]))
        ->assertOk()
        ->assertJsonCount(1, 'data.requests')
        ->assertJsonPath('data.requests.0.title', 'Mine')
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonMissing(['title' => 'Not Mine']);

    Http::assertNothingSent();
});

it('does not advertise or execute request actions through owner credentials', function () {
    MediaRequest::create([
        'playlist_auth_id' => $this->auth->id,
        'arr_integration_id' => $this->radarr->id,
        'title' => 'Private Guest Request',
        'external_id' => '348',
        'request_type' => 'movie',
        'payload' => [],
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    $url = '/player_api.php?'.http_build_query([
        'username' => $this->user->name,
        'password' => $this->playlist->uuid,
        'action' => 'request_history',
    ]);

    $this->getJson(str_replace('request_history', 'panel', $url))
        ->assertOk()
        ->assertJsonMissingPath('m3u_editor.requests');

    $this->getJson($url)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'request_access_denied')
        ->assertJsonMissing(['title' => 'Private Guest Request']);
});

it('returns status only for an owned request', function () {
    $mine = MediaRequest::create([
        'playlist_auth_id' => $this->auth->id,
        'arr_integration_id' => $this->radarr->id,
        'title' => 'Mine',
        'external_id' => '348',
        'request_type' => 'movie',
        'payload' => [],
        'status' => 'pending',
        'requested_at' => now(),
    ]);
    $other = MediaRequest::create([
        'playlist_auth_id' => PlaylistAuth::factory()->for($this->user)->create()->id,
        'arr_integration_id' => $this->radarr->id,
        'title' => 'Other',
        'external_id' => '349',
        'request_type' => 'movie',
        'payload' => [],
        'status' => 'approved',
        'requested_at' => now(),
    ]);

    $this->getJson(requestActionUrl('request_status', ['request_id' => $mine->id]))
        ->assertOk()
        ->assertJsonPath('data.request.id', $mine->id)
        ->assertJsonPath('data.request.status', 'pending_approval');

    $this->getJson(requestActionUrl('request_status', ['request_id' => $other->id]))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'request_not_found')
        ->assertJsonMissing(['title' => 'Other']);
});

it('reuses normalized arr queue status for an approved owned request', function () {
    $mediaRequest = MediaRequest::create([
        'playlist_auth_id' => $this->auth->id,
        'arr_integration_id' => $this->radarr->id,
        'title' => 'Alien',
        'external_id' => '348',
        'request_type' => 'movie',
        'payload' => [],
        'status' => 'approved',
        'requested_at' => now(),
    ]);
    Http::fake([
        'http://radarr.test/api/v3/queue*' => Http::response([
            'records' => [[
                'id' => 91,
                'movie' => ['title' => 'Alien'],
                'status' => 'downloading',
                'trackedDownloadState' => 'importing',
                'size' => 100,
                'sizeleft' => 25,
            ]],
        ]),
    ]);

    $this->getJson(requestActionUrl('request_status', ['request_id' => $mediaRequest->id]))
        ->assertOk()
        ->assertJsonPath('data.request.status', 'importing')
        ->assertJsonPath('data.request.progress', 75);
});

it('marks an approved request completed from a persisted arr import event', function () {
    $mediaRequest = MediaRequest::create([
        'playlist_auth_id' => $this->auth->id,
        'arr_integration_id' => $this->radarr->id,
        'title' => 'Alien',
        'external_id' => '348',
        'request_type' => 'movie',
        'payload' => [],
        'status' => 'approved',
        'requested_at' => now(),
    ]);
    ArrQueueEvent::create([
        'arr_integration_id' => $this->radarr->id,
        'user_id' => $this->user->id,
        'external_id' => '348',
        'title' => 'Alien',
        'event_type' => 'Download',
        'status' => 'imported',
        'quality' => 'Bluray-1080p',
        'size' => 100,
        'progress' => 100,
        'last_event_at' => now(),
    ]);
    Http::fake([
        'http://radarr.test/api/v3/queue*' => Http::response(['records' => []]),
    ]);

    $this->getJson(requestActionUrl('request_status', ['request_id' => $mediaRequest->id]))
        ->assertOk()
        ->assertJsonPath('data.request.status', 'completed')
        ->assertJsonPath('data.request.can_dismiss', true)
        ->assertJsonPath('data.request.progress', 100)
        ->assertJsonPath('data.request.quality', 'Bluray-1080p');

    expect($mediaRequest->fresh()->status)->toBe('completed');
});

it('dismisses only owned completed or rejected requests', function (string $status) {
    $mediaRequest = MediaRequest::create([
        'playlist_auth_id' => $this->auth->id,
        'arr_integration_id' => $this->radarr->id,
        'title' => 'Finished',
        'external_id' => '348',
        'request_type' => 'movie',
        'payload' => [],
        'status' => $status,
        'requested_at' => now(),
    ]);

    $this->postJson(requestActionUrl('request_dismiss'), [
        'request_id' => $mediaRequest->id,
    ])->assertOk()
        ->assertJsonPath('data.dismissed', true)
        ->assertJsonPath('data.request_id', $mediaRequest->id);

    expect($mediaRequest->fresh())->toBeNull();
})->with(['completed', 'rejected']);

it('returns stable dismiss errors for missing foreign and non-dismissible requests', function () {
    $pending = MediaRequest::create([
        'playlist_auth_id' => $this->auth->id,
        'arr_integration_id' => $this->radarr->id,
        'title' => 'Pending',
        'external_id' => '348',
        'request_type' => 'movie',
        'payload' => [],
        'status' => 'pending',
        'requested_at' => now(),
    ]);
    $foreign = MediaRequest::create([
        'playlist_auth_id' => PlaylistAuth::factory()->for($this->user)->create()->id,
        'arr_integration_id' => $this->radarr->id,
        'title' => 'Foreign',
        'external_id' => '349',
        'request_type' => 'movie',
        'payload' => [],
        'status' => 'completed',
        'requested_at' => now(),
    ]);

    $this->postJson(requestActionUrl('request_dismiss'), ['request_id' => $pending->id])
        ->assertConflict()
        ->assertJsonPath('error.code', 'request_not_dismissible');

    foreach ([$foreign->id, 999999] as $requestId) {
        $this->postJson(requestActionUrl('request_dismiss'), ['request_id' => $requestId])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'request_not_found')
            ->assertJsonMissing(['title' => 'Foreign']);
    }
});
