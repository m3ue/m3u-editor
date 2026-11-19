<?php

use App\Models\Plugin;
use App\Plugins\PluginManager;
use App\Services\DvrSchedulerService;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The frequent checks run inside schedule:run instead of booting a separate
 * `php artisan` process each, which was nearly all of their CPU.
 */
function scheduledEventNamed(string $name): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => $event->description === $name);
}

it('runs the frequent checks in the scheduler process with a short overlap lock', function (string $command, string $expression) {
    $event = scheduledEventNamed($command);

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe($expression)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(15);
})->with([
    ['app:refresh-playlist', '* * * * *'],
    ['app:refresh-media-server-integrations', '* * * * *'],
    ['app:refresh-epg', '* * * * *'],
    ['app:epg-cache-health-check', '*/15 * * * *'],
    ['app:run-scheduled-backups', '* * * * *'],
    ['plugins:recover-stale-runs', '* * * * *'],
    ['app:dvr-scheduler-tick', '* * * * *'],
    ['m3u-proxy:register-webhook', '*/5 * * * *'],
    ['app:refresh-playlist-profiles', '*/15 * * * *'],
]);

it('no longer starts a separate process for the frequent checks', function () {
    $spawned = collect(app(Schedule::class)->events())
        ->reject(fn (Event $event) => $event instanceof CallbackEvent)
        ->map(fn (Event $event) => $event->command)
        ->filter(fn (string $command) => str_ends_with($command, ' app:refresh-playlist')
            || str_ends_with($command, ' app:refresh-epg')
            || str_ends_with($command, ' app:dvr-scheduler-tick'));

    expect($spawned)->toBeEmpty();
});

it('keeps scheduled plugin invocations in their own process', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_ends_with((string) $event->command, ' plugins:run-scheduled'));

    expect($event)->not->toBeNull()
        ->and($event)->not->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

function scheduledPluginsEvent(): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_ends_with((string) $event->command, ' plugins:run-scheduled'));
}

function schedulerInProcessCreatePlugin(array $overrides = []): Plugin
{
    $pluginId = 'scheduler-fixture-'.Str::lower(Str::random(6));

    return Plugin::query()->create(array_merge([
        'plugin_id' => $pluginId,
        'name' => 'Scheduler Fixture',
        'version' => '1.0.0',
        'api_version' => '1.0.0',
        'description' => 'Scheduler test fixture.',
        'entrypoint' => 'Plugin.php',
        'class_name' => 'AppLocalPlugins\\'.Str::studly($pluginId).'\\Plugin',
        'capabilities' => ['scheduled'],
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

it('skips the scheduled plugin process when no plugin is scheduled', function () {
    expect(scheduledPluginsEvent()->filtersPass(app()))->toBeFalse();
});

it('skips the scheduled plugin process for plugins that cannot run on a schedule', function (array $overrides) {
    schedulerInProcessCreatePlugin($overrides);

    expect(scheduledPluginsEvent()->filtersPass(app()))->toBeFalse();
})->with([
    'disabled' => [['enabled' => false]],
    'unavailable' => [['available' => false]],
    'invalid' => [['validation_status' => 'invalid']],
    'no scheduled capability' => [['capabilities' => ['hooks']]],
]);

it('starts the scheduled plugin process when an enabled plugin is scheduled', function () {
    schedulerInProcessCreatePlugin();

    expect(scheduledPluginsEvent()->filtersPass(app()))->toBeTrue();
});

it('starts the scheduled plugin process when the check itself fails', function () {
    Schema::rename('extension_plugins', 'extension_plugins_moved');

    expect(scheduledPluginsEvent()->filtersPass(app()))->toBeTrue();
});

it('runs the command when the event fires', function () {
    $this->mock(DvrSchedulerService::class)->shouldReceive('tick')->once();

    $event = scheduledEventNamed('app:dvr-scheduler-tick');
    $event->run(app());

    expect($event->exitCode)->toBe(0);
});

it('surfaces a failing check to schedule:run instead of swallowing it', function () {
    $this->mock(PluginManager::class)->shouldReceive('recoverStaleRuns')->andThrow(new RuntimeException('plugin store unavailable'));

    $event = scheduledEventNamed('plugins:recover-stale-runs');

    expect(fn () => $event->run(app()))->toThrow(RuntimeException::class, 'plugin store unavailable')
        ->and($event->exitCode)->toBe(1);
});
