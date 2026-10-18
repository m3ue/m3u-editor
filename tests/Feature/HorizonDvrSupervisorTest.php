<?php

/**
 * The DVR supervisor is only provisioned when DVR can be used at all, since
 * Horizon keeps at least one worker per queue running even when idle.
 */
function horizonSupervisorsWith(array $env): array
{
    $previous = [];
    foreach ($env as $key => $value) {
        $previous[$key] = getenv($key);
        putenv("{$key}={$value}");
    }

    try {
        return array_keys((require config_path('horizon.php'))['defaults']);
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }
}

it('provisions the DVR supervisor by default', function () {
    expect(horizonSupervisorsWith([]))->toContain('dvr-queue', 'm3u-editor-queue');
});

it('drops the DVR supervisor when DVR is turned off', function (array $env) {
    expect(horizonSupervisorsWith($env))
        ->not->toContain('dvr-queue')
        ->toContain('m3u-editor-queue', 'm3u-editor-plugin-queue', 'm3u-editor-sd-queue', 'aiostreams-queue', 'cache-queue');
})->with([
    'DVR disabled' => [['DVR_ENABLED' => 'false']],
    'proxy integration disabled' => [['M3U_PROXY_INTEGRATION_ENABLED' => 'false']],
]);
