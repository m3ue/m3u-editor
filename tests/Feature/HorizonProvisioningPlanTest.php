<?php

use Laravel\Horizon\ProvisioningPlan;

/**
 * Builds the supervisor options exactly as `php artisan horizon` does, so a
 * supervisor setting Horizon rejects (e.g. minProcesses below 1) fails here
 * instead of crash-looping the queue process in the container.
 */
it('provisions every Horizon supervisor without errors', function () {
    $config = require config_path('horizon.php');

    $plan = new ProvisioningPlan('test-master', $config['environments'], $config['defaults']);

    expect($plan->parsed['*']->keys()->all())->toEqualCanonicalizing(array_keys($config['defaults']));
});
