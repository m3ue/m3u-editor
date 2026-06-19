<?php

use App\Events\ArrQueueUpdated;
use App\Models\ArrIntegration;
use App\Models\ArrQueueEvent;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Only fake the specific broadcast event — faking ALL events swallows Eloquent
    // model lifecycle events (creating/saving), which breaks the webhook_secret observer.
    Event::fake([ArrQueueUpdated::class]);
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/media/movies',
    ]);
    $this->sonarr = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'quality_profile_id' => 1,
        'root_folder_path' => '/media/tv',
    ]);
});

it('returns 204 for Radarr Test event without creating a record', function () {
    $response = $this->postJson(
        '/api/webhooks/arr/'.$this->radarr->webhook_secret,
        ['eventType' => 'Test', 'movie' => ['title' => 'Test Movie', 'tmdbId' => 1]]
    );

    $response->assertNoContent();
    expect(ArrQueueEvent::count())->toBe(0);
    Event::assertNotDispatched(ArrQueueUpdated::class);
});

it('returns 404 for an unknown webhook secret', function () {
    $response = $this->postJson('/api/webhooks/arr/not-a-real-secret', [
        'eventType' => 'Grab',
    ]);

    $response->assertNotFound();
});

it('creates a monitored event on MovieAdded and broadcasts', function () {
    $response = $this->postJson(
        '/api/webhooks/arr/'.$this->radarr->webhook_secret,
        [
            'eventType' => 'MovieAdded',
            'movie' => ['id' => 5, 'title' => 'Conclave', 'tmdbId' => 974635, 'year' => 2024],
            'addMethod' => ['addedBy' => 'manual'],
        ]
    );

    $response->assertNoContent();

    $event = ArrQueueEvent::first();
    expect($event)->not->toBeNull()
        ->and($event->title)->toBe('Conclave')
        ->and($event->status)->toBe('monitored')
        ->and($event->event_type)->toBe('MovieAdded')
        ->and($event->external_id)->toBe('974635')
        ->and($event->download_id)->toBeNull();

    Event::assertDispatched(ArrQueueUpdated::class, fn ($e) => $e->userId === $this->user->id);
});

it('creates a grabbing event on Grab and promotes monitored to grabbing', function () {
    // Pre-existing monitored event from MovieAdded
    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $this->radarr->id,
        'user_id' => $this->user->id,
        'external_id' => '974635',
        'title' => 'Conclave',
        'event_type' => 'MovieAdded',
        'status' => 'monitored',
        'download_id' => null,
        'last_event_at' => now()->subMinutes(5),
    ]);

    $response = $this->postJson(
        '/api/webhooks/arr/'.$this->radarr->webhook_secret,
        [
            'eventType' => 'Grab',
            'movie' => ['title' => 'Conclave', 'tmdbId' => 974635],
            'release' => [
                'releaseTitle' => 'Conclave.2024.1080p.BluRay.x264',
                'quality' => ['quality' => ['name' => 'Bluray-1080p']],
                'size' => 14_000_000_000,
            ],
            'downloadId' => 'abc123',
        ]
    );

    $response->assertNoContent();

    // Monitored row should be deleted; a grabbing row should replace it.
    expect(ArrQueueEvent::where('status', 'monitored')->count())->toBe(0);

    $grabbing = ArrQueueEvent::where('status', 'grabbing')->first();
    expect($grabbing)->not->toBeNull()
        ->and($grabbing->download_id)->toBe('abc123')
        ->and($grabbing->quality)->toBe('Bluray-1080p')
        ->and($grabbing->size)->toBe(14_000_000_000);

    Event::assertDispatched(ArrQueueUpdated::class);
});

it('marks a grab event as imported on Download', function () {
    ArrQueueEvent::factory()->create([
        'arr_integration_id' => $this->radarr->id,
        'user_id' => $this->user->id,
        'external_id' => '974635',
        'download_id' => 'abc123',
        'title' => 'Conclave',
        'event_type' => 'Grab',
        'status' => 'grabbing',
        'quality' => 'Bluray-1080p',
        'last_event_at' => now()->subMinutes(30),
    ]);

    $response = $this->postJson(
        '/api/webhooks/arr/'.$this->radarr->webhook_secret,
        [
            'eventType' => 'Download',
            'movie' => ['title' => 'Conclave', 'tmdbId' => 974635],
            'downloadId' => 'abc123',
            'movieFile' => ['quality' => ['quality' => ['name' => 'Bluray-1080p']]],
        ]
    );

    $response->assertNoContent();

    $event = ArrQueueEvent::where('download_id', 'abc123')->first();
    expect($event)->not->toBeNull()
        ->and($event->status)->toBe('imported')
        ->and($event->progress)->toBe(100);

    Event::assertDispatched(ArrQueueUpdated::class);
});

it('marks an event as manual_required on ManualInteractionRequired', function () {
    $response = $this->postJson(
        '/api/webhooks/arr/'.$this->radarr->webhook_secret,
        [
            'eventType' => 'ManualInteractionRequired',
            'movie' => ['title' => 'Conclave', 'tmdbId' => 974635],
            'downloadId' => 'abc123',
        ]
    );

    $response->assertNoContent();

    $event = ArrQueueEvent::where('status', 'manual_required')->first();
    expect($event)->not->toBeNull()
        ->and($event->title)->toBe('Conclave');

    Event::assertDispatched(ArrQueueUpdated::class);
});

it('handles Sonarr SeriesAdd event', function () {
    $response = $this->postJson(
        '/api/webhooks/arr/'.$this->sonarr->webhook_secret,
        [
            'eventType' => 'SeriesAdd',
            'series' => ['id' => 10, 'title' => 'Severance', 'tvdbId' => 371980],
        ]
    );

    $response->assertNoContent();

    $event = ArrQueueEvent::first();
    expect($event)->not->toBeNull()
        ->and($event->title)->toBe('Severance')
        ->and($event->status)->toBe('monitored')
        ->and($event->external_id)->toBe('371980');
});

it('auto-generates a webhook_secret on creation', function () {
    $integration = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    expect($integration->webhook_secret)->not->toBeNull()
        ->and(strlen($integration->webhook_secret))->toBeGreaterThan(20);
});

it('exposes a webhook_url accessor', function () {
    expect($this->radarr->webhook_url)
        ->toContain('/api/webhooks/arr/')
        ->toContain($this->radarr->webhook_secret);
});

it('handles duplicate Grab events for the same download_id idempotently', function () {
    $payload = [
        'eventType' => 'Grab',
        'movie' => ['title' => 'Conclave', 'tmdbId' => 974635],
        'release' => [
            'releaseTitle' => 'Conclave.2024.1080p.BluRay.x264',
            'quality' => ['quality' => ['name' => 'Bluray-1080p']],
            'size' => 14_000_000_000,
        ],
        'downloadId' => 'abc123',
    ];

    $this->postJson('/api/webhooks/arr/'.$this->radarr->webhook_secret, $payload)->assertNoContent();
    $this->postJson('/api/webhooks/arr/'.$this->radarr->webhook_secret, $payload)->assertNoContent();

    // updateOrCreate on download_id means only one record exists.
    expect(ArrQueueEvent::where('download_id', 'abc123')->count())->toBe(1);
    expect(ArrQueueEvent::where('status', 'grabbing')->count())->toBe(1);
    // Broadcast fires for each webhook call (intentional — UI always refreshes).
    Event::assertDispatchedTimes(ArrQueueUpdated::class, 2);
});

it('logs debug for unknown event types and still broadcasts', function () {
    $response = $this->postJson(
        '/api/webhooks/arr/'.$this->radarr->webhook_secret,
        ['eventType' => 'EpisodeFileDelete', 'movie' => ['title' => 'Test', 'tmdbId' => 1]]
    );

    $response->assertNoContent();
    expect(ArrQueueEvent::count())->toBe(0);
    Event::assertDispatched(ArrQueueUpdated::class);
});
