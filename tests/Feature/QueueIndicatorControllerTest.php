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
                'batch_id' => 'batch-123',
            ],
        ],
        'batches' => [
            [
                'id' => 'batch-123',
                'name' => 'Probe Channels',
                'total' => 10,
                'pending' => 4,
                'processed' => 6,
                'failed' => 0,
                'progress' => 60,
                'status' => 'running',
                'created_at' => now()->toIso8601String(),
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

it('returns queue details for authenticated non admin users', function () {
    setQueueManagerEnabled(true);

    $payload = queueIndicatorPayload([
        'running' => 0,
        'queued' => 1,
        'batches' => [],
        'upcoming' => [],
    ]);

    $this->mock(QueueIndicatorService::class, function ($mock) use ($payload) {
        $mock->shouldReceive('getSnapshot')->once()->with(10)->andReturn($payload);
    });

    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->getJson(queueIndicatorEndpoint())
        ->assertOk()
        ->assertJson($payload);
});

it('keeps the indicator available for admins when Horizon access is disabled', function () {
    setQueueManagerEnabled(false);

    $payload = queueIndicatorPayload([
        'running' => 0,
        'queued' => 2,
        'batches' => [],
        'upcoming' => [],
    ]);

    $this->mock(QueueIndicatorService::class, function ($mock) use ($payload) {
        $mock->shouldReceive('getSnapshot')->once()->with(10)->andReturn($payload);
    });

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->getJson(queueIndicatorEndpoint())
        ->assertOk()
        ->assertJson($payload);
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
            'batches' => [
                ['id', 'name', 'total', 'pending', 'processed', 'failed', 'progress', 'status', 'created_at'],
            ],
            'upcoming' => [
                ['id', 'name', 'queue', 'connection', 'status', 'batch_id'],
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
        'batches' => [],
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
        'batches' => [],
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
            'batches',
            'upcoming',
            'degraded',
            'as_of',
            'reason',
        ]);
});
