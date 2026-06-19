<?php

use App\Livewire\ArrQueueMonitor;
use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
});

it('renders with no integrations', function () {
    Livewire::test(ArrQueueMonitor::class)
        ->assertOk()
        ->assertSet('queues', []);
});

it('loads the sonarr queue on mount', function () {
    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response([
            'records' => [
                [
                    'id' => 1,
                    'status' => 'downloading',
                    'size' => 1_073_741_824,
                    'sizeleft' => 268_435_456,
                    'timeleft' => '00:10:00',
                    'series' => ['title' => 'Breaking Bad'],
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);

    expect($component->get("queues.{$integration->id}.items.0.title"))->toBe('Breaking Bad');
    expect($component->get("queues.{$integration->id}.items.0.progress"))->toBe(75);
    expect($component->get("queues.{$integration->id}.items.0.formattedSize"))->toBe('1.00 GB');
    expect($component->get("queues.{$integration->id}.integration.type"))->toBe('sonarr');
});

it('loads the radarr queue on mount', function () {
    $integration = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response([
            'records' => [
                [
                    'id' => 10,
                    'status' => 'queued',
                    'size' => 2_147_483_648,
                    'sizeleft' => 2_147_483_648,
                    'timeleft' => null,
                    'movie' => ['title' => 'Inception'],
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);

    expect($component->get("queues.{$integration->id}.items.0.title"))->toBe('Inception');
    expect($component->get("queues.{$integration->id}.items.0.progress"))->toBe(0);
    expect($component->get("queues.{$integration->id}.integration.type"))->toBe('radarr');
});

it('groups queues by type via computed properties', function () {
    $sonarr = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
    ]);
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response(['records' => []], 200),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);

    expect($component->get('sonarrQueues'))->toHaveCount(1);
    expect($component->get('radarrQueues'))->toHaveCount(1);
    expect($component->get('sonarrQueues.0.integration.id'))->toBe($sonarr->id);
    expect($component->get('radarrQueues.0.integration.id'))->toBe($radarr->id);
});

it('continues loading other integrations when one is unreachable', function () {
    $sonarr = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
        'url' => 'http://sonarr.local',
    ]);
    $radarr = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'url' => 'http://radarr.local',
    ]);

    Http::fake([
        'sonarr.local/*' => function () {
            throw new ConnectionException('connection refused');
        },
        'radarr.local/*' => Http::response([
            'records' => [
                [
                    'id' => 5,
                    'status' => 'downloading',
                    'size' => 1_048_576,
                    'sizeleft' => 0,
                    'timeleft' => null,
                    'movie' => ['title' => 'Dune'],
                ],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);

    expect($component->get("queues.{$sonarr->id}.error"))->toBeTrue();
    expect($component->get("queues.{$sonarr->id}.items"))->toBeEmpty();
    expect($component->get("queues.{$radarr->id}.error"))->toBeFalse();
    expect($component->get("queues.{$radarr->id}.items.0.title"))->toBe('Dune');
});

it('only shows integrations belonging to the authenticated user', function () {
    $otherUser = User::factory()->create(['permissions' => ['use_integrations']]);
    ArrIntegration::factory()->sonarr()->create([
        'user_id' => $otherUser->id,
    ]);

    // No fake needed — no HTTP calls should be made for another user's integrations
    Livewire::test(ArrQueueMonitor::class)
        ->assertSet('queues', []);
});

it('formats bytes correctly', function () {
    expect(ArrQueueMonitor::formatBytes(0))->toBe('–');
    expect(ArrQueueMonitor::formatBytes(1_024))->toBe('1.00 KB');
    expect(ArrQueueMonitor::formatBytes(1_048_576))->toBe('1.00 MB');
    expect(ArrQueueMonitor::formatBytes(1_073_741_824))->toBe('1.00 GB');
});

it('returns correct status badge color for known statuses', function () {
    expect(ArrQueueMonitor::statusBadge('downloading')['color'])->toBe('primary');
    expect(ArrQueueMonitor::statusBadge('queued')['color'])->toBe('warning');
    expect(ArrQueueMonitor::statusBadge('completed')['color'])->toBe('success');
    expect(ArrQueueMonitor::statusBadge('failed')['color'])->toBe('danger');
    expect(ArrQueueMonitor::statusBadge('paused')['color'])->toBe('gray');
});

it('snapshots a live item that vanishes between polls and marks it completed', function () {
    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
    ]);

    // First poll returns an active item; second poll returns empty (item completed and left the queue).
    Http::fake([
        '*/api/v3/queue*' => Http::sequence()
            ->push([
                'records' => [[
                    'id' => 1,
                    'downloadId' => 'ghost-dl-abc',
                    'status' => 'downloading',
                    'size' => 1_073_741_824,
                    'sizeleft' => 536_870_912,
                    'timeleft' => '00:05:00',
                    'series' => ['title' => 'Severance'],
                ]],
            ], 200)
            ->push(['records' => []], 200),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);

    expect($component->get("queues.{$integration->id}.items.0.title"))->toBe('Severance');
    expect($component->get("queues.{$integration->id}.items.0.source"))->toBe('live');

    $component->call('loadQueues');

    $items = $component->get("queues.{$integration->id}.items");
    $snapshot = collect($items)->firstWhere('source', 'snapshot');

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['title'])->toBe('Severance')
        ->and($snapshot['status'])->toBe('completed')
        ->and($snapshot['progress'])->toBe(100)
        ->and($snapshot['can_dismiss'])->toBeTrue();

    expect($component->get('completedSnapshots'))->toHaveCount(1);
});

it('caps completed snapshots at MAX_SNAPSHOTS_PER_INTEGRATION per integration', function () {
    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
    ]);

    // Seed the component with 26 orphan live items across two polls, one per cycle.
    $component = null;
    for ($i = 1; $i <= 26; $i++) {
        // Each iteration: previous item gone, new item present.
        Http::fake([
            '*/api/v3/queue*' => Http::response([
                'records' => [[
                    'id' => $i,
                    'downloadId' => "dl-{$i}",
                    'status' => 'downloading',
                    'size' => 0,
                    'sizeleft' => 0,
                    'series' => ['title' => "Show {$i}"],
                ]],
            ], 200),
        ]);

        if ($component === null) {
            $component = Livewire::test(ArrQueueMonitor::class);
        } else {
            $component->call('loadQueues');
        }

        // Now clear the queue so the item becomes an orphan on next poll.
        Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);
        $component->call('loadQueues');
    }

    expect(count($component->get('completedSnapshots')))->toBeLessThanOrEqual(25);
});

it('counts total items across all queues', function () {
    ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->user->id,
    ]);
    ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
    ]);

    Http::fake([
        '*/api/v3/queue*' => Http::response([
            'records' => [
                ['id' => 1, 'status' => 'downloading', 'size' => 0, 'sizeleft' => 0, 'series' => ['title' => 'A']],
                ['id' => 2, 'status' => 'queued', 'size' => 0, 'sizeleft' => 0, 'movie' => ['title' => 'B']],
            ],
        ], 200),
    ]);

    $component = Livewire::test(ArrQueueMonitor::class);

    expect($component->get('totalCount'))->toBe(4);
});
