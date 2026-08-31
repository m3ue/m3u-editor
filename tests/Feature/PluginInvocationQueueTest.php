<?php

use App\Filament\Resources\Plugins\Pages\EditPlugin;
use App\Jobs\ExecutePluginInvocation;
use App\Models\Plugin;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\PluginHookDispatcher;
use App\Plugins\PluginIntegrityService;
use App\Plugins\PluginMalwareScanner;
use App\Plugins\PluginManager;
use App\Plugins\PluginManifestLoader;
use App\Plugins\PluginSchemaManager;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginValidator;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

function createPluginForInvocationQueueTests(array $overrides = []): Plugin
{
    $pluginId = 'invocation-queue-'.Str::lower(Str::random(6));

    return Plugin::query()->create(array_merge([
        'plugin_id' => $pluginId,
        'name' => 'Invocation Queue Fixture',
        'version' => '1.0.0',
        'api_version' => '1.0.0',
        'description' => 'Plugin invocation queue test fixture.',
        'entrypoint' => 'Plugin.php',
        'class_name' => 'AppLocalPlugins\\'.Str::studly($pluginId).'\\Plugin',
        'capabilities' => [],
        'hooks' => [],
        'permissions' => [],
        'schema_definition' => ['tables' => []],
        'actions' => [],
        'settings_schema' => [],
        'settings' => [],
        'data_ownership' => ['tables' => [], 'directories' => [], 'files' => []],
        'source_type' => 'local_directory',
        'path' => storage_path('app/testing-plugin-sources/'.$pluginId),
        'available' => true,
        'enabled' => true,
        'installation_status' => 'installed',
        'trust_state' => 'trusted',
        'validation_status' => 'valid',
        'integrity_status' => 'verified',
    ], $overrides));
}

function mockPluginManagerForInvocationQueueTests(): PluginManager
{
    return Mockery::mock(PluginManager::class, [
        app(PluginValidator::class),
        app(PluginSchemaMapper::class),
        app(PluginManifestLoader::class),
        app(PluginIntegrityService::class),
        app(PluginSchemaManager::class),
        app(PluginMalwareScanner::class),
    ])->makePartial();
}

it('fails resumed pending runs when the plugin is no longer eligible', function (array $overrides): void {
    $failedAt = Carbon::parse('2026-08-28 12:00:00');
    Carbon::setTestNow($failedAt);

    try {
        $plugin = createPluginForInvocationQueueTests($overrides);
        $run = PluginRun::query()->create([
            'extension_plugin_id' => $plugin->id,
            'status' => 'pending',
            'invocation_type' => 'action',
            'action' => 'resume_scan',
            'trigger' => 'manual',
            'dry_run' => true,
            'payload' => [],
            'progress' => 42,
            'progress_message' => 'Run queued and waiting for the worker to resume.',
            'last_heartbeat_at' => $failedAt->copy()->subMinute(),
            'run_state' => [
                'resume' => [
                    'last_step' => 'checkpoint-3',
                ],
            ],
        ]);

        (new ExecutePluginInvocation(
            $plugin->id,
            'action',
            'resume_scan',
            options: ['existing_run_id' => $run->id],
        ))->handle(app(PluginManager::class));

        $run->refresh();

        expect($run->status)->toBe('failed')
            ->and($run->summary)->toBe('The resumed invocation could not start because the plugin is no longer eligible.')
            ->and($run->progress_message)->toBe($run->summary)
            ->and($run->progress)->toBe(42)
            ->and($run->run_state)->toBe([
                'resume' => [
                    'last_step' => 'checkpoint-3',
                ],
            ])
            ->and($run->last_heartbeat_at)->toEqual($failedAt)
            ->and($run->finished_at)->toEqual($failedAt)
            ->and($run->result)->toBe([
                'status' => 'failed',
                'success' => false,
                'summary' => $run->summary,
                'data' => [],
            ])
            ->and($run->logs()->count())->toBe(1)
            ->and($run->logs()->first()->level)->toBe('error')
            ->and($run->logs()->first()->message)->toBe($run->summary);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'disabled' => [['enabled' => false]],
    'uninstalled' => [['installation_status' => 'uninstalled']],
    'unavailable' => [['available' => false]],
    'invalid' => [['validation_status' => 'invalid']],
    'untrusted' => [['trust_state' => 'pending_review']],
    'integrity-unverified' => [['integrity_status' => 'unknown']],
]);

it('does not overwrite another plugin or non-pending resumed run when the plugin is ineligible', function (): void {
    $plugin = createPluginForInvocationQueueTests(['enabled' => false]);
    $otherPlugin = createPluginForInvocationQueueTests();
    $crossPluginRun = PluginRun::query()->create([
        'extension_plugin_id' => $otherPlugin->id,
        'status' => 'pending',
        'invocation_type' => 'action',
        'action' => 'resume_scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => [],
    ]);
    $terminalRun = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'status' => 'stale',
        'invocation_type' => 'action',
        'action' => 'resume_scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => [],
    ]);

    $job = new ExecutePluginInvocation($plugin->id, 'action', 'resume_scan');
    $job->options = ['existing_run_id' => $crossPluginRun->id];
    $job->handle(app(PluginManager::class));
    $job->options = ['existing_run_id' => $terminalRun->id];
    $job->handle(app(PluginManager::class));

    expect($crossPluginRun->fresh()->status)->toBe('pending')
        ->and($crossPluginRun->logs()->count())->toBe(0)
        ->and($terminalRun->fresh()->status)->toBe('stale')
        ->and($terminalRun->logs()->count())->toBe(0);
});

it('keeps ineligible fresh and missing-plugin invocations as silent no-ops', function (): void {
    $plugin = createPluginForInvocationQueueTests(['enabled' => false]);

    (new ExecutePluginInvocation($plugin->id, 'action', 'resume_scan'))->handle(app(PluginManager::class));
    (new ExecutePluginInvocation($plugin->id, 'action', 'resume_scan', options: ['existing_run_id' => 100000]))->handle(app(PluginManager::class));
    (new ExecutePluginInvocation($plugin->id + 100000, 'action', 'resume_scan'))->handle(app(PluginManager::class));

    expect(PluginRun::query()->count())->toBe(0);
});

it('executes only one duplicate resumed job after the first reaches a terminal status', function (): void {
    $plugin = createPluginForInvocationQueueTests();
    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'status' => 'pending',
        'invocation_type' => 'action',
        'action' => 'resume_scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => [],
    ]);
    $pluginInstance = Mockery::mock(PluginInterface::class);
    $pluginInstance->shouldReceive('runAction')
        ->once()
        ->andReturn(PluginActionResult::success('Resume completed.'));
    $pluginManager = mockPluginManagerForInvocationQueueTests();
    $pluginManager->shouldReceive('instantiate')
        ->once()
        ->andReturn($pluginInstance);
    $job = new ExecutePluginInvocation(
        $plugin->id,
        'action',
        'resume_scan',
        options: ['existing_run_id' => $run->id, 'resume' => true],
    );

    $job->handle($pluginManager);
    $job->handle($pluginManager);

    expect($run->fresh()->status)->toBe('completed')
        ->and($run->logs()->where('message', 'Run resumed from its last saved checkpoint.')->count())->toBe(1);
});

it('does not execute a resumed job for a non-pending run', function (string $status): void {
    $plugin = createPluginForInvocationQueueTests();
    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'status' => $status,
        'invocation_type' => 'action',
        'action' => 'resume_scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => [],
        'summary' => 'Original summary.',
        'started_at' => now()->subHours(7),
        'last_heartbeat_at' => now()->subHour(),
    ]);
    $pluginManager = mockPluginManagerForInvocationQueueTests();
    $pluginManager->shouldNotReceive('instantiate');

    (new ExecutePluginInvocation(
        $plugin->id,
        'action',
        'resume_scan',
        options: ['existing_run_id' => $run->id, 'resume' => true],
    ))->handle($pluginManager);

    expect($run->fresh()->status)->toBe($status)
        ->and($run->fresh()->summary)->toBe('Original summary.')
        ->and($run->logs()->count())->toBe(0);
})->with(['completed', 'failed', 'cancelled', 'stale', 'running']);

it('does not execute a resumed job when its invocation does not match the persisted run', function (
    string $persistedInvocationType,
    string $persistedName,
    string $jobInvocationType,
    string $jobName,
): void {
    $plugin = createPluginForInvocationQueueTests();
    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'status' => 'pending',
        'invocation_type' => $persistedInvocationType,
        $persistedInvocationType => $persistedName,
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => [],
        'progress_message' => 'Waiting for a worker.',
    ]);
    $pluginManager = mockPluginManagerForInvocationQueueTests();
    $pluginManager->shouldNotReceive('instantiate');

    (new ExecutePluginInvocation(
        $plugin->id,
        $jobInvocationType,
        $jobName,
        options: ['existing_run_id' => $run->id, 'resume' => true],
    ))->handle($pluginManager);

    expect($run->fresh()->status)->toBe('pending')
        ->and($run->fresh()->progress_message)->toBe('Waiting for a worker.')
        ->and($run->logs()->count())->toBe(0);
})->with([
    'invocation type' => ['action', 'resume_scan', 'hook', 'resume_scan'],
    'invalid invocation type' => ['action', 'resume_scan', 'invalid', 'resume_scan'],
    'action name' => ['action', 'resume_scan', 'action', 'different_action'],
    'hook name' => ['hook', 'playlist.synced', 'hook', 'playlist.updated'],
]);

it('does not execute a resumed job for another plugin or a missing run', function (): void {
    $plugin = createPluginForInvocationQueueTests();
    $otherPlugin = createPluginForInvocationQueueTests();
    $crossPluginRun = PluginRun::query()->create([
        'extension_plugin_id' => $otherPlugin->id,
        'status' => 'pending',
        'invocation_type' => 'action',
        'action' => 'resume_scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => [],
    ]);
    $pluginManager = mockPluginManagerForInvocationQueueTests();
    $pluginManager->shouldNotReceive('instantiate');

    (new ExecutePluginInvocation(
        $plugin->id,
        'action',
        'resume_scan',
        options: ['existing_run_id' => $crossPluginRun->id, 'resume' => true],
    ))->handle($pluginManager);
    (new ExecutePluginInvocation(
        $plugin->id,
        'action',
        'resume_scan',
        options: ['existing_run_id' => 100000, 'resume' => true],
    ))->handle($pluginManager);

    expect($crossPluginRun->fresh()->status)->toBe('pending')
        ->and($crossPluginRun->logs()->count())->toBe(0);
});

it('allows only one claimant to transition a pending resumed run', function (): void {
    $claimedAt = Carbon::parse('2026-08-28 14:00:00');
    Carbon::setTestNow($claimedAt);

    try {
        $plugin = createPluginForInvocationQueueTests();
        $run = PluginRun::query()->create([
            'extension_plugin_id' => $plugin->id,
            'status' => 'pending',
            'invocation_type' => 'action',
            'action' => 'resume_scan',
            'trigger' => 'manual',
            'dry_run' => true,
            'payload' => [],
            'started_at' => $claimedAt->copy()->subHours(7),
            'last_heartbeat_at' => $claimedAt->copy()->subHour(),
        ]);
        $pluginManager = app(PluginManager::class);
        $prepareRun = new ReflectionMethod($pluginManager, 'prepareRun');
        $attributes = [
            'trigger' => 'manual',
            'invocation_type' => 'action',
            'action' => 'resume_scan',
            'payload' => [],
            'dry_run' => true,
            'user_id' => null,
        ];
        $options = [
            'existing_run_id' => $run->id,
            'resume' => true,
        ];

        $winner = $prepareRun->invoke($pluginManager, $plugin, $attributes, $options);
        $loser = $prepareRun->invoke($pluginManager, $plugin, $attributes, $options);

        expect($winner)->toBeInstanceOf(PluginRun::class)
            ->and($loser)->toBeNull()
            ->and($run->fresh()->status)->toBe('running')
            ->and($run->fresh()->started_at)->toEqual($claimedAt)
            ->and($run->fresh()->last_heartbeat_at)->toEqual($claimedAt)
            ->and($run->logs()->where('message', 'Run resumed from its last saved checkpoint.')->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

it('isolates plugin invocations on a dedicated long-running queue worker', function () {
    $job = new ExecutePluginInvocation(1, 'action', 'health_check');
    $connection = config('queue.connections.plugin');
    $supervisor = config('horizon.defaults.m3u-editor-plugin-queue');

    expect($job->connection)->toBe('plugin')
        ->and($job->queue)->toBe('plugin-invocations');

    expect(property_exists($job, 'timeout'))->toBeFalse()
        ->and(property_exists($job, 'tries'))->toBeFalse()
        ->and(property_exists($job, 'failOnTimeout'))->toBeTrue()
        ->and($job->failOnTimeout)->toBeTrue();

    expect($connection)->toBeArray()
        ->and($connection['driver'])->toBe('redis')
        ->and($connection['queue'])->toBe('plugin-invocations')
        ->and($connection['retry_after'])->toBe(21905);

    expect($supervisor)->toBeArray()
        ->and($supervisor['connection'])->toBe('plugin')
        ->and($supervisor['queue'])->toBe(['plugin-invocations'])
        ->and($supervisor['timeout'])->toBe(21600)
        ->and($supervisor['tries'])->toBe(1)
        ->and($connection['retry_after'])->toBeGreaterThan($supervisor['timeout'])
        ->and(config('horizon.defaults.m3u-editor-queue.queue'))->not->toContain('plugin-invocations');
});

it('documents every dedicated plugin supervisor tuning default', function () {
    $environment = file_get_contents(base_path('.env.example'));

    expect($environment)
        ->toContain('# HORIZON_PLUGIN_MAX_PROCESSES=1')
        ->toContain('# HORIZON_PLUGIN_MAX_TIME=3600')
        ->toContain('# HORIZON_PLUGIN_MAX_JOBS=50')
        ->toContain('# HORIZON_PLUGIN_MEMORY=256');
});

it('routes manually queued plugin actions through the dedicated worker', function () {
    Queue::fake();

    $user = User::factory()->admin()->create();
    $this->actingAs($user);
    $plugin = createPluginForInvocationQueueTests([
        'actions' => [[
            'id' => 'health_check',
            'label' => 'Health Check',
        ]],
    ]);

    Livewire::test(EditPlugin::class, ['record' => $plugin->getKey()])
        ->callAction(TestAction::make('plugin_action_health_check'))
        ->assertNotified();

    Queue::assertPushedOn('plugin-invocations', ExecutePluginInvocation::class, function (ExecutePluginInvocation $job) use ($plugin, $user) {
        return $job->connection === 'plugin'
            && $job->pluginId === $plugin->id
            && $job->invocationType === 'action'
            && $job->name === 'health_check'
            && $job->options['trigger'] === 'manual'
            && $job->options['user_id'] === $user->id;
    });
});

it('routes plugin hooks through the dedicated worker', function () {
    Queue::fake();

    $plugin = createPluginForInvocationQueueTests([
        'hooks' => ['playlist.synced'],
    ]);

    app(PluginHookDispatcher::class)->dispatch('playlist.synced', ['playlist_id' => 123]);

    Queue::assertPushedOn('plugin-invocations', ExecutePluginInvocation::class, function (ExecutePluginInvocation $job) use ($plugin) {
        return $job->connection === 'plugin'
            && $job->pluginId === $plugin->id
            && $job->invocationType === 'hook'
            && $job->name === 'playlist.synced'
            && $job->payload === ['playlist_id' => 123];
    });
});

it('routes scheduled plugin invocations through the dedicated worker', function () {
    Queue::fake();

    $plugin = createPluginForInvocationQueueTests([
        'capabilities' => ['scheduled'],
    ]);
    $pluginManager = Mockery::mock(PluginManager::class);
    $pluginManager->shouldReceive('scheduledInvocations')
        ->once()
        ->withArgs(fn (Plugin $candidate): bool => $candidate->is($plugin))
        ->andReturn([[
            'type' => 'action',
            'name' => 'scheduled_scan',
            'payload' => ['playlist_id' => 456],
        ]]);
    app()->instance(PluginManager::class, $pluginManager);

    $this->artisan('plugins:run-scheduled')
        ->assertSuccessful()
        ->expectsOutput('Queued 1 scheduled plugin invocation(s).');

    Queue::assertPushedOn('plugin-invocations', ExecutePluginInvocation::class, function (ExecutePluginInvocation $job) use ($plugin) {
        return $job->connection === 'plugin'
            && $job->pluginId === $plugin->id
            && $job->invocationType === 'action'
            && $job->name === 'scheduled_scan'
            && $job->options['trigger'] === 'schedule';
    });
});

it('routes resumed stale plugin runs through the dedicated worker', function () {
    Queue::fake();

    $plugin = createPluginForInvocationQueueTests();
    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'status' => 'stale',
        'invocation_type' => 'action',
        'action' => 'resume_scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => ['playlist_id' => 789],
        'started_at' => now()->subHour(),
        'stale_at' => now(),
    ]);

    app(PluginManager::class)->resumeRun($run);

    Queue::assertPushedOn('plugin-invocations', ExecutePluginInvocation::class, function (ExecutePluginInvocation $job) use ($plugin, $run) {
        return $job->connection === 'plugin'
            && $job->pluginId === $plugin->id
            && $job->name === 'resume_scan'
            && $job->options['existing_run_id'] === $run->id
            && $job->options['resume'] === true;
    });

    expect($run->fresh()->status)->toBe('pending');
});

it('starts resumed plugin runs with a fresh execution age', function () {
    Queue::fake();

    $resumedAt = Carbon::parse('2026-08-26 12:00:00');
    Carbon::setTestNow($resumedAt);

    try {
        $plugin = createPluginForInvocationQueueTests();
        $run = PluginRun::query()->create([
            'extension_plugin_id' => $plugin->id,
            'status' => 'stale',
            'invocation_type' => 'action',
            'action' => 'resume_scan',
            'trigger' => 'manual',
            'dry_run' => true,
            'payload' => [],
            'progress' => 42,
            'last_heartbeat_at' => $resumedAt->copy()->subMinutes(20),
            'started_at' => $resumedAt->copy()->subMinutes(366),
            'run_state' => [
                'resume' => [
                    'last_step' => 'checkpoint-3',
                ],
            ],
        ]);

        app(PluginManager::class)->resumeRun($run);

        $queuedJob = null;
        Queue::assertPushed(ExecutePluginInvocation::class, function (ExecutePluginInvocation $job) use (&$queuedJob): bool {
            $queuedJob = $job;

            return true;
        });

        $claimedRun = null;
        $pluginInstance = Mockery::mock(PluginInterface::class);
        $pluginInstance->shouldReceive('runAction')
            ->once()
            ->andReturnUsing(function (string $action, array $payload, PluginExecutionContext $context) use (&$claimedRun): PluginActionResult {
                $claimedRun = $context->run->fresh();

                return PluginActionResult::success('Resume completed.');
            });
        $pluginManager = mockPluginManagerForInvocationQueueTests();
        $pluginManager->shouldReceive('instantiate')
            ->once()
            ->andReturn($pluginInstance);
        $queuedJob->handle($pluginManager);

        expect($claimedRun)->toBeInstanceOf(PluginRun::class)
            ->and($claimedRun->started_at)->toEqual($resumedAt)
            ->and($claimedRun->last_heartbeat_at)->toEqual($resumedAt)
            ->and($claimedRun->progress)->toBe(42)
            ->and($claimedRun->run_state)->toBe([
                'resume' => [
                    'last_step' => 'checkpoint-3',
                ],
            ])
            ->and($pluginManager->recoverStaleRuns(15, 365))->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('atomically claims a resumable run before dispatching it once', function () {
    Queue::fake();

    $plugin = createPluginForInvocationQueueTests();
    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'status' => 'failed',
        'invocation_type' => 'action',
        'action' => 'resume_scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => [],
        'progress_message' => 'Failed during scan.',
        'started_at' => now()->subHour(),
        'finished_at' => now(),
    ]);

    $pluginManager = app(PluginManager::class);
    $pluginManager->resumeRun($run);
    $pluginManager->resumeRun($run->fresh());

    Queue::assertPushed(ExecutePluginInvocation::class, 1);

    expect($run->fresh()->status)->toBe('pending');
});

it('releases a resume claim when dispatch fails', function () {
    $plugin = createPluginForInvocationQueueTests();
    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'status' => 'stale',
        'invocation_type' => 'action',
        'action' => 'resume_scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => [],
        'progress_message' => 'Waiting for operator.',
        'started_at' => now()->subHours(7),
        'stale_at' => now(),
        'finished_at' => now(),
    ]);

    Bus::shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('Queue unavailable.'));

    expect(fn () => app(PluginManager::class)->resumeRun($run))
        ->toThrow(RuntimeException::class, 'Queue unavailable.');

    $run->refresh();

    expect($run->status)->toBe('stale')
        ->and($run->progress_message)->toBe('Waiting for operator.');
});

it('surfaces a friendly error when another resume for the same run holds the lock', function () {
    $plugin = createPluginForInvocationQueueTests();
    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'status' => 'stale',
        'invocation_type' => 'action',
        'action' => 'resume_scan',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => [],
        'progress_message' => 'Waiting for operator.',
        'started_at' => now()->subHours(7),
        'stale_at' => now(),
    ]);

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->with("plugins:resume-run:{$run->id}", 30)->andReturn($lock);

    expect(fn () => app(PluginManager::class)->resumeRun($run))
        ->toThrow(RuntimeException::class, 'Another resume for this run is already in progress. Try again in a moment.');

    expect($run->fresh()->status)->toBe('stale')
        ->and($run->fresh()->progress_message)->toBe('Waiting for operator.')
        ->and($run->logs()->count())->toBe(0);
});
