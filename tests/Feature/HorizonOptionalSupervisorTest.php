<?php

use Laravel\Horizon\ProvisioningPlan;

/**
 * Optional features drop their Horizon supervisor when switched off, since each
 * one keeps a supervisor and at least one idle worker running. The supervisor
 * gate and the feature's own config flag must read the env the same way, or a
 * feature could stay visible with nothing working its queue.
 */
dataset('optional feature supervisors', [
    'plugins' => ['PLUGINS_ENABLED', 'm3u-editor-plugin-queue', 'plugins', 'enabled'],
    'schedules direct' => ['SCHEDULES_DIRECT_ENABLED', 'm3u-editor-sd-queue', 'services', 'schedules_direct.enabled'],
    'aiostreams' => ['AIOSTREAMS_ENABLED', 'aiostreams-queue', 'services', 'aiostreams.enabled'],
    'cached content' => ['CACHED_CONTENT_ENABLED', 'cache-queue', 'cached_content', 'enabled'],
]);

it('provisions every optional supervisor by default', function () {
    expect(array_keys(configFileWith('horizon', [])['defaults']))
        ->toContain('m3u-editor-plugin-queue', 'm3u-editor-sd-queue', 'aiostreams-queue', 'cache-queue');
});

it('keeps an optional feature and its supervisor on unless the switch is explicitly false', function (string $envKey, string $supervisor, string $configFile, string $configKey, string $value) {
    expect(array_keys(configFileWith('horizon', [$envKey => $value])['defaults']))->toContain($supervisor)
        ->and(data_get(configFileWith($configFile, [$envKey => $value]), $configKey))->toBeTrue();
})->with('optional feature supervisors')->with(['true', 'TRUE', '1', '', '0', 'null', 'empty', 'no']);

it('drops an optional supervisor and turns the feature off when its switch is false', function (string $envKey, string $supervisor, string $configFile, string $configKey, string $value) {
    $config = configFileWith('horizon', [$envKey => $value]);
    $plan = new ProvisioningPlan('test-master', $config['environments'], $config['defaults']);

    expect(array_keys($config['defaults']))
        ->not->toContain($supervisor)
        ->toContain('m3u-editor-queue')
        ->and($plan->parsed['*']->keys()->all())->not->toContain($supervisor)
        ->and(data_get(configFileWith($configFile, [$envKey => $value]), $configKey))->toBeFalse();
})->with('optional feature supervisors')->with(['false', 'FALSE', 'False', '(false)']);

it('documents every optional feature switch in .env.example', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain(
        'PLUGINS_ENABLED',
        'SCHEDULES_DIRECT_ENABLED',
        'AIOSTREAMS_ENABLED',
        'CACHED_CONTENT_ENABLED',
    );
});
