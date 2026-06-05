<?php

use App\Models\User;
use App\Services\QueueIndicatorService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function queueIndicatorEndpoint(): string
{
    return route('admin.queue-indicator');
}

function queueIndicatorPayload(array $overrides = []): array
{
    return array_merge([
        'running' => 1,
        'queued' => 4,
        'upcoming' => [
            [
                'id' => 'job-123',
                'name' => 'App\\Jobs\\SyncPlaylist',
                'queue' => 'default',
                'connection' => 'redis',
                'status' => 'pending',
            ],
        ],
        'degraded' => false,
        'as_of' => now()->toIso8601String(),
    ], $overrides);
}

function setQueueManagerEnabled(bool $enabled): void
{
    app()->instance(GeneralSettings::class, (object) ['show_queue_manager' => $enabled]);
}

it('protects the queue indicator endpoint from guests', function () {
    $this->getJson(queueIndicatorEndpoint())->assertUnauthorized();
});

it('blocks non admin users from queue details', function () {
    setQueueManagerEnabled(true);

    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->getJson(queueIndicatorEndpoint())
        ->assertForbidden();
});

it('blocks admins when the queue manager is disabled', function () {
    setQueueManagerEnabled(false);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->getJson(queueIndicatorEndpoint())
        ->assertForbidden();
});

it('returns the expected queue indicator json for admins', function () {
    setQueueManagerEnabled(true);

    $payload = queueIndicatorPayload();

    $this->mock(QueueIndicatorService::class, function ($mock) use ($payload) {
        $mock->shouldReceive('getSnapshot')->once()->with(10)->andReturn($payload);
    });

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->getJson(queueIndicatorEndpoint())
        ->assertOk()
        ->assertJson($payload)
        ->assertJsonStructure([
            'running',
            'queued',
            'upcoming' => [
                ['id', 'name', 'queue', 'connection', 'status'],
            ],
            'degraded',
            'as_of',
        ]);
});

it('returns a robust empty queue payload', function () {
    setQueueManagerEnabled(true);

    $payload = queueIndicatorPayload([
        'running' => 0,
        'queued' => 0,
        'upcoming' => [],
    ]);

    $this->mock(QueueIndicatorService::class, function ($mock) use ($payload) {
        $mock->shouldReceive('getSnapshot')->once()->with(10)->andReturn($payload);
    });

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->getJson(queueIndicatorEndpoint())
        ->assertOk()
        ->assertJson($payload)
        ->assertJsonCount(0, 'upcoming');
});

it('keeps the endpoint available when the queue state is degraded', function () {
    setQueueManagerEnabled(true);

    $payload = queueIndicatorPayload([
        'running' => 0,
        'queued' => 0,
        'upcoming' => [],
        'degraded' => true,
        'reason' => 'horizon_unavailable',
    ]);

    $this->mock(QueueIndicatorService::class, function ($mock) use ($payload) {
        $mock->shouldReceive('getSnapshot')->once()->with(10)->andReturn($payload);
    });

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->getJson(queueIndicatorEndpoint())
        ->assertOk()
        ->assertJson([
            'running' => 0,
            'queued' => 0,
            'degraded' => true,
            'reason' => 'horizon_unavailable',
        ])
        ->assertJsonStructure([
            'running',
            'queued',
            'upcoming',
            'degraded',
            'as_of',
            'reason',
        ]);
});
