<?php

use App\Livewire\ArrQueueMonitor;
use App\Livewire\ArrSearch;
use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->adminUser = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->adminUser);
    $this->integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->adminUser->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
    ]);
});

// ── PlaylistAuth toggle ────────────────────────────────────────────────────

it('playlist auth defaults to auto_approve_requests false', function () {
    $auth = PlaylistAuth::factory()->create(['user_id' => $this->adminUser->id]);
    expect($auth->auto_approve_requests)->toBeFalse();
});

it('playlist auth can enable auto_approve_requests', function () {
    $auth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'auto_approve_requests' => true,
    ]);
    expect($auth->auto_approve_requests)->toBeTrue();
});

// ── ArrSearch guest gate ───────────────────────────────────────────────────

it('sends request directly to arr when auto_approve_requests is true', function () {
    $auth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'auto_approve_requests' => true,
    ]);

    Http::fake([
        '*/api/v3/series*' => Http::response(['id' => 1, 'title' => 'Dark'], 201),
    ]);

    $component = Livewire::test(ArrSearch::class, [
        'guestIntegrationIds' => [$this->integration->id],
        'guestMode' => true,
        'playlistAuthId' => $auth->id,
    ]);

    $component->set('results', [[
        'integrationId' => $this->integration->id,
        'title' => 'Dark',
        'titleSlug' => 'dark',
        'tvdbId' => 12345,
        'images' => [],
    ]]);

    $component->call('request', 0);

    expect(MediaRequest::count())->toBe(0);
    Http::assertSentCount(1);
});

it('creates a pending MediaRequest when auto_approve_requests is false', function () {
    $auth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'auto_approve_requests' => false,
    ]);

    $component = Livewire::test(ArrSearch::class, [
        'guestIntegrationIds' => [$this->integration->id],
        'guestMode' => true,
        'playlistAuthId' => $auth->id,
    ]);

    $component->set('results', [[
        'integrationId' => $this->integration->id,
        'title' => 'Dark',
        'titleSlug' => 'dark',
        'tvdbId' => 12345,
        'images' => [],
    ]]);

    $component->call('request', 0);

    expect(MediaRequest::count())->toBe(1);

    $request = MediaRequest::first();
    expect($request->title)->toBe('Dark')
        ->and($request->status)->toBe('pending')
        ->and($request->request_type)->toBe('series')
        ->and($request->playlist_auth_id)->toBe($auth->id)
        ->and($request->arr_integration_id)->toBe($this->integration->id);

    // No HTTP call to Arr API.
    Http::assertNothingSent();
});

it('creates a pending episode MediaRequest when auto_approve_requests is false', function () {
    $auth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'auto_approve_requests' => false,
    ]);

    $component = Livewire::test(ArrSearch::class, [
        'guestIntegrationIds' => [$this->integration->id],
        'guestMode' => true,
        'playlistAuthId' => $auth->id,
    ]);

    $component->set('detailResult', [
        'integrationId' => $this->integration->id,
        'title' => 'Severance',
        'tvdbId' => 99999,
    ]);
    $component->set('detailIntegrationId', $this->integration->id);

    $component->call('requestEpisode', 1, 3);

    expect(MediaRequest::count())->toBe(1);

    $request = MediaRequest::first();
    expect($request->request_type)->toBe('episode')
        ->and($request->season_number)->toBe(1)
        ->and($request->episode_number)->toBe(3)
        ->and($request->status)->toBe('pending');

    Http::assertNothingSent();
});

// ── ArrQueueMonitor approve/reject ─────────────────────────────────────────

it('shows pending media requests in the queue monitor', function () {
    $auth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'auto_approve_requests' => false,
    ]);

    $mediaRequest = MediaRequest::create([
        'playlist_auth_id' => $auth->id,
        'arr_integration_id' => $this->integration->id,
        'title' => 'Stranger Things',
        'external_id' => '305074',
        'request_type' => 'series',
        'payload' => ['tvdbId' => 305074, 'title' => 'Stranger Things'],
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);

    $items = $component->get("queues.{$this->integration->id}.items");
    $pendingItem = collect($items)->firstWhere('source', 'media_request');

    expect($pendingItem)->not->toBeNull()
        ->and($pendingItem['title'])->toBe('Stranger Things')
        ->and($pendingItem['status'])->toBe('pending_approval')
        ->and($pendingItem['media_request_id'])->toBe($mediaRequest->id);
});

it('approves a media request and sends it to arr', function () {
    $auth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'auto_approve_requests' => false,
    ]);

    $mediaRequest = MediaRequest::create([
        'playlist_auth_id' => $auth->id,
        'arr_integration_id' => $this->integration->id,
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
            'searchForMovie' => true,
        ],
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
        '*/api/v3/series*' => Http::response(['id' => 1, 'title' => 'The Bear'], 201),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);
    $component->call('approveRequest', $mediaRequest->id);

    $mediaRequest->refresh();
    expect($mediaRequest->status)->toBe('approved')
        ->and($mediaRequest->reviewed_by_user_id)->toBe($this->adminUser->id);
});

it('rejects a media request', function () {
    $auth = PlaylistAuth::factory()->create([
        'user_id' => $this->adminUser->id,
        'auto_approve_requests' => false,
    ]);

    $mediaRequest = MediaRequest::create([
        'playlist_auth_id' => $auth->id,
        'arr_integration_id' => $this->integration->id,
        'title' => 'Bad Show',
        'external_id' => '111',
        'request_type' => 'movie',
        'payload' => ['tmdbId' => 111, 'title' => 'Bad Show'],
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);
    $component->call('rejectRequest', $mediaRequest->id);

    $mediaRequest->refresh();
    expect($mediaRequest->status)->toBe('rejected')
        ->and($mediaRequest->reviewed_by_user_id)->toBe($this->adminUser->id);
});

it('cannot approve a request belonging to another users integration', function () {
    $otherUser = User::factory()->create(['permissions' => ['use_integrations']]);
    $otherIntegration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $otherUser->id,
    ]);

    $auth = PlaylistAuth::factory()->create([
        'user_id' => $otherUser->id,
        'auto_approve_requests' => false,
    ]);

    $mediaRequest = MediaRequest::create([
        'playlist_auth_id' => $auth->id,
        'arr_integration_id' => $otherIntegration->id,
        'title' => 'Sneaky Show',
        'external_id' => '999',
        'request_type' => 'series',
        'payload' => ['tvdbId' => 999],
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);
    $component->call('approveRequest', $mediaRequest->id);

    // Status unchanged because the integration does not belong to the admin user.
    $mediaRequest->refresh();
    expect($mediaRequest->status)->toBe('pending');
});
