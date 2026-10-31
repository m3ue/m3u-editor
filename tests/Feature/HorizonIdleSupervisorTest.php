<?php

/**
 * Occasional-use supervisors (plugin, SchedulesDirect, AIOStreams) scale to zero
 * workers while idle, since every idle Horizon worker is a fully booted app
 * holding ~110 MB. Their concurrency caps are unchanged.
 */
function horizonDefaultsWith(array $env): array
{
    $previous = [];
    foreach ($env as $key => $value) {
        $previous[$key] = getenv($key);
        putenv("{$key}={$value}");
    }

    try {
        return (require config_path('horizon.php'))['defaults'];
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }
}

it('scales occasional-use supervisors to zero idle workers', function (string $supervisor, int $maxProcesses) {
    $options = horizonDefaultsWith([])[$supervisor];

    expect($options['balance'])->toBe('auto')
        ->and($options['minProcesses'])->toBe(0)
        ->and($options['maxProcesses'])->toBe($maxProcesses);
})->with([
    'plugin' => ['m3u-editor-plugin-queue', 1],
    'schedules direct' => ['m3u-editor-sd-queue', 1],
    'aiostreams' => ['aiostreams-queue', 2],
]);

it('keeps a warm worker when the min processes env var is set', function (string $supervisor, string $envKey) {
    expect(horizonDefaultsWith([$envKey => '1'])[$supervisor]['minProcesses'])->toBe(1);
})->with([
    'plugin' => ['m3u-editor-plugin-queue', 'HORIZON_PLUGIN_MIN_PROCESSES'],
    'schedules direct' => ['m3u-editor-sd-queue', 'HORIZON_SD_MIN_PROCESSES'],
    'aiostreams' => ['aiostreams-queue', 'HORIZON_AIOSTREAMS_MIN_PROCESSES'],
]);

it('documents the min processes env vars in .env.example', function () {
    expect(file_get_contents(base_path('.env.example')))
        ->toContain('HORIZON_PLUGIN_MIN_PROCESSES', 'HORIZON_SD_MIN_PROCESSES', 'HORIZON_AIOSTREAMS_MIN_PROCESSES');
});

it('runs the PHP-FPM pool on demand so idle children are reaped', function () {
    expect(file_get_contents(base_path('docker/8.4/www.conf')))
        ->toMatch('/^pm = ondemand$/m')
        ->toMatch('/^pm\.process_idle_timeout = \d+$/m');
});
