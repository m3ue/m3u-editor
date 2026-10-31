<?php

/**
 * The AIOStreams supervisor idles at one worker and scales up to its cap only
 * while resolution jobs are waiting, instead of keeping every worker running.
 */
it('idles the AIOStreams supervisor at one worker and keeps its concurrency cap', function () {
    $supervisor = (require config_path('horizon.php'))['defaults']['aiostreams-queue'];

    expect($supervisor['balance'])->toBe('auto')
        ->and($supervisor['minProcesses'])->toBe(1)
        ->and($supervisor['maxProcesses'])->toBe(2)
        ->and($supervisor['queue'])->toBe(['aiostreams-resolve']);
});
