<?php

use App\Filament\Resources\Plugins\Pages\EditPlugin;
use App\Jobs\ExecutePluginInvocation;
use App\Models\Plugin;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\PluginHookDispatcher;
use App\Plugins\PluginManager;
use Filament\Actions\Testing\TestAction;
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

it('isolates plugin invocations on a dedicated long-running queue worker', function () {
    $job = new ExecutePluginInvocation(1, 'action', 'health_check');
    $connection = config('queue.connections.plugin');
    $supervisor = config('horizon.defaults.m3u-editor-plugin-queue');

    expect($job->connection)->toBe('plugin')
        ->and($job->queue)->toBe('plugin-invocations');

    expect(property_exists($job, 'timeout'))->toBeTrue()
        ->and($job->timeout)->toBe(21600)
        ->and(property_exists($job, 'tries'))->toBeTrue()
        ->and($job->tries)->toBe(1)
        ->and(property_exists($job, 'failOnTimeout'))->toBeTrue()
        ->and($job->failOnTimeout)->toBeTrue();

    expect($connection)->toBeArray()
        ->and($connection['driver'])->toBe('redis')
        ->and($connection['queue'])->toBe('plugin-invocations')
        ->and($connection['retry_after'])->toBeGreaterThan($job->timeout);

    expect($supervisor)->toBeArray()
        ->and($supervisor['connection'])->toBe('plugin')
        ->and($supervisor['queue'])->toBe(['plugin-invocations'])
        ->and($supervisor['timeout'])->toBe(21600)
        ->and($supervisor['tries'])->toBe(1)
        ->and(config('horizon.defaults.m3u-editor-queue.queue'))->not->toContain('plugin-invocations');
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
});
