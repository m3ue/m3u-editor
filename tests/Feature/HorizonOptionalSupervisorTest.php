<?php

use Laravel\Horizon\ProvisioningPlan;

/**
 * Optional supervisors can be switched off for unused features, since each one
 * keeps a supervisor and at least one idle worker running.
 */
function horizonConfigWith(array $env): array
{
    $previous = [];
    foreach ($env as $key => $value) {
        $previous[$key] = getenv($key);
        putenv("{$key}={$value}");
    }

    try {
        return require config_path('horizon.php');
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }
}

it('provisions every optional supervisor by default', function () {
    expect(array_keys(horizonConfigWith([])['defaults']))
        ->toContain('m3u-editor-plugin-queue', 'm3u-editor-sd-queue', 'aiostreams-queue', 'cache-queue');
});

it('drops an optional supervisor when its switch is off', function (string $envKey, string $supervisor) {
    $config = horizonConfigWith([$envKey => 'false']);
    $plan = new ProvisioningPlan('test-master', $config['environments'], $config['defaults']);

    expect(array_keys($config['defaults']))
        ->not->toContain($supervisor)
        ->toContain('m3u-editor-queue')
        ->and($plan->parsed['*']->keys()->all())->not->toContain($supervisor);
})->with([
    'plugin' => ['HORIZON_PLUGIN_QUEUE_ENABLED', 'm3u-editor-plugin-queue'],
    'schedules direct' => ['HORIZON_SD_QUEUE_ENABLED', 'm3u-editor-sd-queue'],
    'aiostreams' => ['HORIZON_AIOSTREAMS_QUEUE_ENABLED', 'aiostreams-queue'],
    'cache' => ['HORIZON_CACHE_QUEUE_ENABLED', 'cache-queue'],
]);

it('documents every optional supervisor switch in .env.example', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain(
        'HORIZON_PLUGIN_QUEUE_ENABLED',
        'HORIZON_SD_QUEUE_ENABLED',
        'HORIZON_AIOSTREAMS_QUEUE_ENABLED',
        'HORIZON_CACHE_QUEUE_ENABLED',
    );
});
