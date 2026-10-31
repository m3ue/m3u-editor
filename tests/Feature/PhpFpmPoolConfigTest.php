<?php

/**
 * The PHP-FPM pool spawns children on demand and reaps them once idle, so a
 * quiet UI doesn't keep several fully booted workers resident.
 */
it('runs the PHP-FPM pool on demand so idle children are reaped', function () {
    expect(file_get_contents(base_path('docker/8.4/www.conf')))
        ->toMatch('/^pm = ondemand$/m')
        ->toMatch('/^pm\.process_idle_timeout = \d+$/m');
});
