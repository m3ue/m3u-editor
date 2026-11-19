<?php

use App\Enums\EpgSourceType;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\MediaServerIntegration;
use App\Models\Plugin;
use App\Models\User;
use App\Services\OptionalQueueSupervisors;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Horizon leaves out the supervisors of optional features nobody uses yet, and a
 * scheduled check starts them, with their normal config, once the feature is used.
 */
const OPTIONAL_QUEUE_SUPERVISORS = [
    'm3u-editor-plugin-queue',
    'm3u-editor-sd-queue',
    'dvr-queue',
    'aiostreams-queue',
    'cache-queue',
];

beforeEach(function () {
    Bus::fake();
    Queue::fake();
    Http::preventStrayRequests();

    config(['horizon.defaults' => array_merge(config('horizon.defaults'), [
        'm3u-editor-plugin-queue' => ['connection' => 'plugin', 'queue' => ['plugin-invocations'], 'balance' => 'simple', 'maxProcesses' => 1],
        'm3u-editor-sd-queue' => ['connection' => 'redis', 'queue' => ['schedules-direct'], 'balance' => 'simple', 'maxProcesses' => 1],
        'dvr-queue' => ['connection' => 'redis', 'queue' => ['dvr', 'dvr-post', 'dvr-meta'], 'balance' => 'auto', 'maxProcesses' => 4],
        'aiostreams-queue' => ['connection' => 'redis', 'queue' => ['aiostreams-resolve'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 2],
        'cache-queue' => ['connection' => 'redis', 'queue' => ['cache'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 4],
    ])]);
});

function optionalQueueCreatePlugin(): Plugin
{
    $pluginId = 'optional-queue-fixture-'.Str::lower(Str::random(6));

    return Plugin::query()->create([
        'plugin_id' => $pluginId,
        'name' => 'Optional Queue Fixture',
        'version' => '1.0.0',
        'api_version' => '1.0.0',
        'description' => 'Optional queue test fixture.',
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
    ]);
}

/**
 * @param  array<int, object>  $masters
 */
function optionalQueueFakeMasters(array $masters): void
{
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->shouldReceive('all')->andReturn($masters);
    app()->instance(MasterSupervisorRepository::class, $repository);
}

function optionalQueueMaster(array $supervisors = []): object
{
    return (object) [
        'name' => 'host-ab12',
        'environment' => 'production',
        'pid' => 4242,
        'status' => 'running',
        'supervisors' => $supervisors,
    ];
}

it('leaves out every optional supervisor when no feature is in use', function () {
    expect(app(OptionalQueueSupervisors::class)->leaveOutUnusedSupervisors())->toBe(OPTIONAL_QUEUE_SUPERVISORS);

    foreach (OPTIONAL_QUEUE_SUPERVISORS as $supervisor) {
        expect(config("horizon.defaults.{$supervisor}.maxProcesses"))->toBe(0);
    }

    expect(config('horizon.defaults.m3u-editor-queue.maxProcesses'))->toBeGreaterThan(0);
});

it('keeps the supervisor of a feature that is in use', function (string $supervisor, Closure $useFeature) {
    $useFeature();

    $leftOut = app(OptionalQueueSupervisors::class)->leaveOutUnusedSupervisors();

    expect($leftOut)->not->toContain($supervisor)
        ->and(config("horizon.defaults.{$supervisor}.maxProcesses"))->toBeGreaterThan(0);
})->with([
    'plugins' => ['m3u-editor-plugin-queue', fn () => optionalQueueCreatePlugin()],
    'SchedulesDirect' => ['m3u-editor-sd-queue', fn () => Epg::factory()->create(['source_type' => EpgSourceType::SCHEDULES_DIRECT])],
    'DVR switched on' => ['dvr-queue', fn () => DvrSetting::factory()->enabled()->create()],
    'DVR recordings' => ['dvr-queue', fn () => DvrRecording::factory()->create()],
    'AIOStreams' => ['aiostreams-queue', fn () => MediaServerIntegration::factory()->create(['type' => 'aiostreams'])],
    'cached content' => ['cache-queue', fn () => CachedContentFile::factory()->create()],
]);

it('treats DVR as unused while every DVR setting is switched off', function () {
    DvrSetting::factory()->create(['enabled' => false]);

    expect(app(OptionalQueueSupervisors::class)->leaveOutUnusedSupervisors())->toContain('dvr-queue');
});

it('keeps the supervisor of an unused feature that already has queued jobs', function () {
    Queue::push(new DownloadCachedContentFile(1), '', 'cache');

    expect(app(OptionalQueueSupervisors::class)->leaveOutUnusedSupervisors())->not->toContain('cache-queue');
});

it('keeps every supervisor when the feature checks fail', function () {
    Schema::rename('dvr_settings', 'dvr_settings_moved');

    expect(app(OptionalQueueSupervisors::class)->leaveOutUnusedSupervisors())->not->toContain('dvr-queue');
});

it('ignores supervisors switched off in config', function () {
    $defaults = config('horizon.defaults');
    unset($defaults['aiostreams-queue']);
    config(['horizon.defaults' => $defaults]);

    expect(app(OptionalQueueSupervisors::class)->leaveOutUnusedSupervisors())->not->toContain('aiostreams-queue')
        ->and(config('horizon.defaults.aiostreams-queue'))->toBeNull();
});

it('leaves out unused supervisors only when the Horizon master starts', function (string $command, bool $leavesOut) {
    event(new CommandStarting($command, new ArrayInput([]), new NullOutput));

    expect(config('horizon.defaults.cache-queue.maxProcesses'))->toBe($leavesOut ? 0 : 4);
})->with([
    ['horizon', true],
    ['horizon:supervisor', false],
    ['horizon:work', false],
    ['schedule:run', false],
]);

it('starts a left-out supervisor once its feature is in use, with its normal config', function () {
    CachedContentFile::factory()->create();
    optionalQueueFakeMasters([optionalQueueMaster(['host-ab12:m3u-editor-queue'])]);

    $pushed = [];
    $commandQueue = Mockery::mock(HorizonCommandQueue::class);
    $commandQueue->shouldReceive('push')->andReturnUsing(function (string $name, string $command, array $options) use (&$pushed): void {
        $pushed[] = compact('name', 'command', 'options');
    });
    app()->instance(HorizonCommandQueue::class, $commandQueue);

    expect(app(OptionalQueueSupervisors::class)->startNeededSupervisors())->toBe(['host-ab12:cache-queue'])
        ->and($pushed)->toHaveCount(1)
        ->and($pushed[0]['name'])->toBe('master:host-ab12')
        ->and($pushed[0]['command'])->toBe(AddSupervisor::class)
        ->and($pushed[0]['options'])->toMatchArray([
            'name' => 'host-ab12:cache-queue',
            'connection' => 'redis',
            'queue' => 'cache',
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'parentId' => 4242,
        ]);

    // The start request holds off a second one while Horizon starts the supervisor.
    expect(app(OptionalQueueSupervisors::class)->startNeededSupervisors())->toBe([]);
});

it('does not start a supervisor that is already running', function () {
    CachedContentFile::factory()->create();
    optionalQueueFakeMasters([optionalQueueMaster(['host-ab12:cache-queue'])]);
    app()->instance(HorizonCommandQueue::class, Mockery::mock(HorizonCommandQueue::class)->shouldNotReceive('push')->getMock());

    expect(app(OptionalQueueSupervisors::class)->startNeededSupervisors())->toBe([])
        ->and(Cache::has('horizon:optional-supervisor-start:host-ab12:cache-queue'))->toBeFalse();
});

it('does nothing while Horizon is not running', function () {
    CachedContentFile::factory()->create();
    optionalQueueFakeMasters([]);
    app()->instance(HorizonCommandQueue::class, Mockery::mock(HorizonCommandQueue::class)->shouldNotReceive('push')->getMock());

    expect(app(OptionalQueueSupervisors::class)->startNeededSupervisors())->toBe([]);
});

function optionalQueueRetentionEvent(): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => $event->description === 'App\Jobs\DvrRetentionCleanup');
}

it('queues DVR retention cleanup only while DVR is in use', function () {
    expect(optionalQueueRetentionEvent()->filtersPass(app()))->toBeFalse();

    DvrSetting::factory()->enabled()->for(User::factory())->create();

    expect(optionalQueueRetentionEvent()->filtersPass(app()))->toBeTrue();
});
